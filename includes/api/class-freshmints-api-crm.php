<?php
/**
 * POST /crm/sync (manage_options): push one lead into Questbook CRM, and into Bomb Bag when asked.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';
require_once __DIR__ . '/class-freshmints-api-bomb-bag.php';

class Freshmints_Api_Crm extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/crm/sync', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_crm_sync' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	/**
	 * 1-Click Plug-and-Play Questbook CRM Contact Sync
	 */
	public function handle_crm_sync( WP_REST_Request $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$lead = $body['lead'] ?? array();

		if ( empty( $lead['fullName'] ) ) {
			return new WP_Error( 'invalid_lead', 'Lead fullName is required for Questbook sync.', array( 'status' => 400 ) );
		}

		$name_parts       = explode( ' ', trim( $lead['fullName'] ) );
		$first_name       = $name_parts[0] ?? '';
		$last_name        = count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '';
		$email            = $lead['skipTraceData']['primaryEmail'] ?? ( $lead['email'] ?? '' );
		$phone            = $lead['skipTraceData']['verifiedPhone'] ?? ( $lead['phone'] ?? '' );
		$profession       = $lead['profession'] ?? '';
		$profession_title = $lead['professionTitle'] ?? '';
		$license_num      = $lead['licenseNumber'] ?? '';
		$city             = $lead['city'] ?? '';
		$state            = $lead['state'] ?? '';
		$deal_value       = (float) ( $lead['estimatedDealValue'] ?? 1650 );
		$outreach_status  = $lead['outreachStatus'] ?? 'uncontacted';
		$college          = $lead['collegeOrSchool'] ?? '';
		$address          = $lead['skipTraceData']['currentAddress'] ?? '';

		$contacts_table = $wpdb->prefix . 'xophz_qb_contacts';
		$deals_table    = $wpdb->prefix . 'xophz_qb_deals';
		$contact_id     = 0;
		$action         = 'created';

		$company_name = ! empty( $lead['collegeOrSchool'] ) ? $lead['collegeOrSchool'] : ( $profession_title . ' Practice' );
		$meta_data = array(
			'profession'         => $profession,
			'professionTitle'    => $profession_title,
			'licenseNumber'      => $license_num,
			'city'               => $city,
			'state'              => $state,
			'address'            => $address,
			'collegeOrSchool'    => $college,
			'estimatedDealValue' => $deal_value,
			'outreachStatus'     => $outreach_status,
			'freshMintsLeadId'   => $lead['id'] ?? '',
			'notes'              => "Imported from Fresh Mints: {$profession_title} in {$city}, {$state}. License: {$license_num}.",
			'company'            => $company_name,
			'servicePackage'     => 'w4 2-Year Cloud Hosting',
			'paymentStatus'      => 'Pending',
			'retainer'           => $deal_value,
		);

		// Check if Questbook custom contacts table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $contacts_table ) ) === $contacts_table;

		if ( $table_exists ) {
			// Find existing contact by email or exact first/last name
			$existing = null;
			if ( ! empty( $email ) ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, meta_data FROM {$contacts_table} WHERE email = %s LIMIT 1",
					$email
				) );
			}
			if ( ! $existing && ! empty( $first_name ) && ! empty( $last_name ) ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, meta_data FROM {$contacts_table} WHERE first_name = %s AND last_name = %s LIMIT 1",
					$first_name,
					$last_name
				) );
			}

			if ( $existing ) {
				$contact_id = (int) $existing->id;
				$action     = 'updated';
				$existing_meta = json_decode( $existing->meta_data, true ) ?: array();
				$merged_meta   = array_merge( $existing_meta, $meta_data );

				$wpdb->update(
					$contacts_table,
					array(
						'first_name'  => sanitize_text_field( $first_name ),
						'last_name'   => sanitize_text_field( $last_name ),
						'email'       => sanitize_email( $email ),
						'phone'       => sanitize_text_field( $phone ),
						'company'     => sanitize_text_field( $company_name ),
						'source'      => 'Fresh Mints',
						'lead_status' => 'Lead',
						'meta_data'   => wp_json_encode( $merged_meta ),
						'updated_at'  => current_time( 'mysql' ),
					),
					array( 'id' => $contact_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$inserted = $wpdb->insert(
					$contacts_table,
					array(
						'first_name'  => sanitize_text_field( $first_name ),
						'last_name'   => sanitize_text_field( $last_name ),
						'email'       => sanitize_email( $email ),
						'phone'       => sanitize_text_field( $phone ),
						'company'     => sanitize_text_field( $company_name ),
						'source'      => 'Fresh Mints',
						'lead_status' => 'Lead',
						'meta_data'   => wp_json_encode( $meta_data ),
						'created_at'  => current_time( 'mysql' ),
						'updated_at'  => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					$contact_id = (int) $wpdb->insert_id;
				}
			}

			// Also sync into Questbook deals table
			$deals_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $deals_table ) ) === $deals_table;
			if ( $deals_table_exists && $contact_id > 0 ) {
				$existing_deal = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$deals_table} WHERE contact_id = %d LIMIT 1",
					$contact_id
				) );
				if ( ! $existing_deal ) {
					$wpdb->insert(
						$deals_table,
						array(
							'title'       => sanitize_text_field( "{$lead['fullName']} - {$profession_title}" ),
							'contact_id'  => $contact_id,
							'amount'      => $deal_value,
							'stage'       => 'New',
							'description' => sanitize_textarea_field( "Fresh Mints License Lead: {$license_num} ({$city}, {$state})" ),
							'created_at'  => current_time( 'mysql' ),
							'updated_at'  => current_time( 'mysql' ),
						),
						array( '%s', '%d', '%f', '%s', '%s', '%s', '%s' )
					);
				}
			}
		}

		// Also maintain CPT for backward compatibility with post queries
		$cpt_existing = 0;
		if ( ! empty( $email ) ) {
			$found = get_posts( array(
				'post_type'   => 'questbook_contact',
				'meta_key'    => '_qb_email',
				'meta_value'  => $email,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			) );
			if ( ! empty( $found ) ) {
				$cpt_existing = $found[0];
			}
		}

		$post_data = array(
			'post_title'  => sanitize_text_field( $lead['fullName'] ),
			'post_type'   => 'questbook_contact',
			'post_status' => 'publish',
		);
		if ( $cpt_existing > 0 ) {
			$post_data['ID'] = $cpt_existing;
			$cpt_id = wp_update_post( $post_data );
		} else {
			$cpt_id = wp_insert_post( $post_data );
		}

		if ( ! is_wp_error( $cpt_id ) && $cpt_id > 0 ) {
			$preview_slug = sanitize_title( $lead['websiteConfig']['previewSlug'] ?? $this->generate_preview_slug( $lead['fullName'], $lead['id'] ?? '' ) );
			$preview_url  = home_url( '/fresh-mints/#/preview/' . $preview_slug );

			update_post_meta( $cpt_id, '_qb_first_name', sanitize_text_field( $first_name ) );
			update_post_meta( $cpt_id, '_qb_last_name', sanitize_text_field( $last_name ) );
			update_post_meta( $cpt_id, '_qb_full_name', sanitize_text_field( $lead['fullName'] ) );
			update_post_meta( $cpt_id, '_qb_email', sanitize_email( $email ) );
			update_post_meta( $cpt_id, '_qb_phone', sanitize_text_field( $phone ) );
			update_post_meta( $cpt_id, '_qb_profession', sanitize_text_field( $profession ) );
			update_post_meta( $cpt_id, '_qb_license_number', sanitize_text_field( $license_num ) );
			update_post_meta( $cpt_id, '_qb_city', sanitize_text_field( $city ) );
			update_post_meta( $cpt_id, '_qb_state', sanitize_text_field( $state ) );
			update_post_meta( $cpt_id, '_qb_lead_source', 'fresh_mints' );
			update_post_meta( $cpt_id, '_qb_deal_value', $deal_value );
			update_post_meta( $cpt_id, '_qb_outreach_status', sanitize_text_field( $outreach_status ) );
			update_post_meta( $cpt_id, '_qb_fresh_mints_lead_id', sanitize_text_field( $lead['id'] ?? '' ) );
			update_post_meta( $cpt_id, '_qb_preview_slug', $preview_slug );
			update_post_meta( $cpt_id, '_qb_preview_url', $preview_url );
			if ( $contact_id > 0 ) {
				update_post_meta( $cpt_id, '_qb_custom_table_contact_id', $contact_id );
			}
		}

		$final_contact_id = $contact_id > 0 ? $contact_id : ( ! is_wp_error( $cpt_id ) ? $cpt_id : 0 );

		// Auto-sync into Bomb Bag if setting or request flag is active
		$auto_bomb_bag  = get_option( 'xophz_compass_freshmints_auto_sync_bomb_bag', '0' );
		$bomb_bag_res   = null;
		$bomb_bag_error = null;
		if ( $auto_bomb_bag === '1' || ! empty( $body['syncBombBag'] ) ) {
			// The bomb-bag handler reads get_json_params(), which parses only a JSON body sent with a JSON content type.
			$bomb_bag_req = new WP_REST_Request( 'POST', '/xophz-freshmints/v1/bomb-bag/sync' );
			$bomb_bag_req->set_header( 'Content-Type', 'application/json' );
			$bomb_bag_req->set_body( wp_json_encode( array( 'lead' => $lead ) ) );
			$bomb_bag_res = ( new Freshmints_Api_Bomb_Bag() )->handle_bomb_bag_sync( $bomb_bag_req );

			// The contact is already written to Questbook by now, so a Bomb Bag failure is reported next
			// to the CRM result instead of failing the whole sync.
			if ( is_wp_error( $bomb_bag_res ) ) {
				$bomb_bag_error = array(
					'code'    => $bomb_bag_res->get_error_code(),
					'message' => $bomb_bag_res->get_error_message(),
				);
				$bomb_bag_res   = null;
			}
		}

		$response = array(
			'success'        => true,
			'contactId'      => $final_contact_id,
			'action'         => $action,
			'crmMessage'     => "Successfully synced {$lead['fullName']} into Questbook CRM (ID: #{$final_contact_id}).",
			'bombBagResult'  => $bomb_bag_res ? $bomb_bag_res->get_data() : null,
		);
		if ( $bomb_bag_error ) {
			$response['bombBagError'] = $bomb_bag_error;
		}

		return rest_ensure_response( $response );
	}
}
