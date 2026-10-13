<?php
/**
 * Bomb Bag email marketing (manage_options): POST /bomb-bag/sync adds leads as subscribers,
 * GET /bomb-bag/lists returns the subscriber lists.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';

class Freshmints_Api_Bomb_Bag extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/bomb-bag/sync', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bomb_bag_sync' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/bomb-bag/lists', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_bomb_bag_lists' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	/**
	 * 1-Click Sync single lead or array of leads into Bomb Bag Email Marketing & Journeys
	 */
	public function handle_bomb_bag_sync( WP_REST_Request $request ) {
		global $wpdb;
		$body           = $request->get_json_params();
		$lead           = $body['lead'] ?? null;
		$leads          = $body['leads'] ?? ( $lead ? array( $lead ) : array() );
		$target_list_id = ! empty( $body['listId'] ) ? absint( $body['listId'] ) : 0;
		$custom_tags    = ! empty( $body['tags'] ) && is_array( $body['tags'] ) ? $body['tags'] : array();

		if ( empty( $leads ) ) {
			return new WP_Error( 'missing_lead', 'Lead data is required for Bomb Bag synchronization.', array( 'status' => 400 ) );
		}

		$subscribers_table      = $wpdb->prefix . 'bomb_bag_subscribers';
		$lists_table            = $wpdb->prefix . 'bomb_bag_lists';
		$list_subscribers_table = $wpdb->prefix . 'bomb_bag_list_subscribers';
		$tags_table             = $wpdb->prefix . 'bomb_bag_tags';
		$subscriber_tags_table  = $wpdb->prefix . 'bomb_bag_subscriber_tags';

		// Verify that Bomb Bag subscribers table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscribers_table ) ) === $subscribers_table;
		if ( ! $table_exists ) {
			return new WP_Error( 'bomb_bag_not_active', 'Bomb Bag database tables are not installed or active.', array( 'status' => 400 ) );
		}

		$synced_results = array();

		foreach ( $leads as $l ) {
			$full_name        = trim( $l['fullName'] ?? 'Practitioner Lead' );
			$name_parts       = explode( ' ', $full_name, 2 );
			$first_name       = $name_parts[0] ?? '';
			$last_name        = $name_parts[1] ?? '';
			$profession       = sanitize_text_field( $l['profession'] ?? 'general' );
			$profession_title = sanitize_text_field( $l['professionTitle'] ?? ucwords( str_replace( '_', ' ', $profession ) ) );
			$city             = sanitize_text_field( $l['city'] ?? '' );
			$state            = sanitize_text_field( $l['state'] ?? '' );
			$license_num      = sanitize_text_field( $l['licenseNumber'] ?? '' );
			$phone            = sanitize_text_field( $l['skipTraceData']['verifiedPhone'] ?? '' );
			$preview_slug     = sanitize_title( $l['websiteConfig']['previewSlug'] ?? $this->generate_preview_slug( $full_name, $l['id'] ?? '' ) );
			$preview_url      = home_url( '/fresh-mints/#/preview/' . $preview_slug );
			$deal_value       = (float) ( $l['estimatedDealValue'] ?? 1650 );
			$outreach_status  = sanitize_text_field( $l['outreachStatus'] ?? 'Uncontacted' );

			// Determine valid or fallback email
			$email = sanitize_email( $l['skipTraceData']['primaryEmail'] ?? '' );
			if ( empty( $email ) ) {
				$email_slug = sanitize_title( $full_name );
				$email      = $email_slug . '-' . sanitize_title( $license_num ?: ( $l['id'] ?? 'lead' ) ) . '@leads.freshmints.local';
			}

			// Custom fields JSON
			$custom_fields = array(
				'profession'       => $profession,
				'profession_title' => $profession_title,
				'license_number'   => $license_num,
				'city'             => $city,
				'state'            => $state,
				'phone'            => $phone,
				'preview_slug'     => $preview_slug,
				'preview_url'      => $preview_url,
				'deal_value'       => $deal_value,
				'outreach_status'  => $outreach_status,
				'lead_id'          => $l['id'] ?? '',
				'source'           => 'Fresh Mints',
			);

			// Check existing subscriber by email
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, custom_fields FROM {$subscribers_table} WHERE email = %s LIMIT 1",
				$email
			) );

			$subscriber_id = 0;
			$action        = 'created';

			if ( $existing ) {
				$subscriber_id = (int) $existing->id;
				$action        = 'updated';
				$existing_cf   = json_decode( $existing->custom_fields, true ) ?: array();
				$merged_cf     = array_merge( $existing_cf, $custom_fields );

				$wpdb->update(
					$subscribers_table,
					array(
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'source'        => 'Fresh Mints',
						'custom_fields' => wp_json_encode( $merged_cf ),
						'updated_at'    => current_time( 'mysql' ),
					),
					array( 'id' => $subscriber_id ),
					array( '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$inserted = $wpdb->insert(
					$subscribers_table,
					array(
						'email'         => $email,
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'status'        => 'active',
						'source'        => 'Fresh Mints',
						'score'         => 50,
						'lead_status'   => 'warm',
						'custom_fields' => wp_json_encode( $custom_fields ),
						'subscribed_at' => current_time( 'mysql' ),
						'created_at'    => current_time( 'mysql' ),
						'updated_at'    => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( $inserted ) {
					$subscriber_id = (int) $wpdb->insert_id;
				}
			}

			if ( $subscriber_id > 0 ) {
				// Manage Lists
				$assigned_list_id = $target_list_id;
				if ( ! $assigned_list_id ) {
					// Ensure default master Fresh Mints list exists
					$master_list_name = 'Fresh Mints: All Leads';
					$list_row         = $wpdb->get_row( $wpdb->prepare(
						"SELECT id FROM {$lists_table} WHERE name = %s LIMIT 1",
						$master_list_name
					) );
					if ( $list_row ) {
						$assigned_list_id = (int) $list_row->id;
					} else {
						$wpdb->insert(
							$lists_table,
							array(
								'name'        => $master_list_name,
								'description' => 'Master list of practitioner leads synced from Fresh Mints discovery platform.',
								'created_at'  => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%s' )
						);
						$assigned_list_id = (int) $wpdb->insert_id;
					}
				}

				if ( $assigned_list_id > 0 ) {
					// Attach to list
					$link_exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$list_subscribers_table} WHERE list_id = %d AND subscriber_id = %d",
						$assigned_list_id,
						$subscriber_id
					) );
					if ( ! $link_exists ) {
						$wpdb->insert(
							$list_subscribers_table,
							array(
								'list_id'       => $assigned_list_id,
								'subscriber_id' => $subscriber_id,
							),
							array( '%d', '%d' )
						);
					}

					// Update subscriber count in list
					$count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(subscriber_id) FROM {$list_subscribers_table} WHERE list_id = %d",
						$assigned_list_id
					) );
					$wpdb->update(
						$lists_table,
						array( 'subscriber_count' => $count ),
						array( 'id' => $assigned_list_id ),
						array( '%d' ),
						array( '%d' )
					);
				}

				// Manage Tags
				$tags_to_apply = array_unique( array_merge(
					array( 'Fresh Mints', $profession_title, $state ?: 'US', $outreach_status ),
					$custom_tags
				) );

				$tags_table_exists     = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tags_table ) ) === $tags_table;
				$sub_tags_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscriber_tags_table ) ) === $subscriber_tags_table;

				if ( $tags_table_exists && $sub_tags_table_exists ) {
					foreach ( $tags_to_apply as $tag_name ) {
						$tag_name = trim( $tag_name );
						if ( empty( $tag_name ) ) {
							continue;
						}

						$tag_slug = sanitize_title( $tag_name );
						$tag_row  = $wpdb->get_row( $wpdb->prepare(
							"SELECT id FROM {$tags_table} WHERE slug = %s OR name = %s LIMIT 1",
							$tag_slug,
							$tag_name
						) );

						$tag_id = 0;
						if ( $tag_row ) {
							$tag_id = (int) $tag_row->id;
						} else {
							$wpdb->insert(
								$tags_table,
								array(
									'name'       => $tag_name,
									'slug'       => $tag_slug,
									'created_at' => current_time( 'mysql' ),
								),
								array( '%s', '%s', '%s' )
							);
							$tag_id = (int) $wpdb->insert_id;
						}

						if ( $tag_id > 0 ) {
							$tag_link_exists = $wpdb->get_var( $wpdb->prepare(
								"SELECT COUNT(*) FROM {$subscriber_tags_table} WHERE subscriber_id = %d AND tag_id = %d",
								$subscriber_id,
								$tag_id
							) );
							if ( ! $tag_link_exists ) {
								$wpdb->insert(
									$subscriber_tags_table,
									array(
										'subscriber_id' => $subscriber_id,
										'tag_id'        => $tag_id,
									),
									array( '%d', '%d' )
								);
							}
						}
					}
				}

				// Trigger Bomb Bag automation hook
				do_action( 'bomb_bag_subscriber_created', $subscriber_id, $assigned_list_id );

				$synced_results[] = array(
					'leadId'       => $l['id'] ?? '',
					'subscriberId' => $subscriber_id,
					'listId'       => $assigned_list_id,
					'action'       => $action,
					'email'        => $email,
					'previewSlug'  => $preview_slug,
				);
			}
		}

		return rest_ensure_response( array(
			'success'      => true,
			'syncedCount'  => count( $synced_results ),
			'results'      => $synced_results,
			'subscriberId' => $synced_results[0]['subscriberId'] ?? 0,
			'message'      => count( $synced_results ) === 1
				? "Successfully synced {$leads[0]['fullName']} into Bomb Bag Marketing (Subscriber ID: #{$synced_results[0]['subscriberId']})."
				: "Successfully synced " . count( $synced_results ) . " leads into Bomb Bag Marketing.",
		) );
	}

	/**
	 * Get available Bomb Bag subscriber lists
	 */
	public function handle_get_bomb_bag_lists() {
		global $wpdb;
		$lists_table = $wpdb->prefix . 'bomb_bag_lists';
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table ) ) === $lists_table;

		if ( ! $table_exists ) {
			return rest_ensure_response( array(
				'success' => true,
				'lists'   => array(),
			) );
		}

		$lists = $wpdb->get_results( "SELECT id, name, description, subscriber_count FROM {$lists_table} ORDER BY name ASC" );

		return rest_ensure_response( array(
			'success' => true,
			'lists'   => $lists ?: array(),
		) );
	}
}
