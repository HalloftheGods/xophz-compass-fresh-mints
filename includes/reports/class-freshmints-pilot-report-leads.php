<?php
/**
 * Opt-ins and billable leads for one month, read from the Lead Magnet plugin's private leads.
 *
 * An opt-in is any lead the capture form stored in the month. It belongs to a social platform when
 * its attribution says it came from a reply (utm_medium=social_reply) and utm_source names a known
 * platform; every other lead belongs to 'other'.
 *
 *   opt_ins            leads stored in the month
 *   opt_ins_call_text  of those, the ones that checked the consent box with a phone number, so the
 *                      stored contact channels include phone or sms
 *   billable           of those, the ones with a valid US phone, a valid email and a name, counted
 *                      once per email address in the month. A later submission with the same email
 *                      is a duplicate and is left out of billable (not out of opt-ins).
 *
 * A person who signs up again in a later month is billable again; this report sees one month.
 *
 * Reads only. The leads stay in Lead Magnet; nothing is copied or changed here.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Report_Leads {

	const SOCIAL_MEDIUM = 'social_reply';
	const PAGE_SIZE     = 200;
	const PHONE_PATTERN = '/^\+1[2-9]\d{2}[2-9]\d{6}$/';

	/** True when the Lead Magnet plugin is active and its lead post type exists. */
	public static function is_available(): bool {
		return class_exists( 'Lead_Magnet_Leads' ) && post_type_exists( Lead_Magnet_Leads::POST_TYPE );
	}

	/**
	 * @return array{
	 *   by_source: array<string, array{opt_ins: int, opt_ins_call_text: int, billable: int}>,
	 *   billable: array<int, array<string, string>>,
	 *   duplicates_excluded: int
	 * }
	 */
	public static function summarize( Freshmints_Pilot_Report_Range $range ): array {
		$by_source  = array();
		$billable   = array();
		$seen       = array();
		$duplicates = 0;

		foreach ( self::in_range( $range ) as $lead ) {
			$source = self::source_of( $lead );
			if ( ! isset( $by_source[ $source ] ) ) {
				$by_source[ $source ] = array( 'opt_ins' => 0, 'opt_ins_call_text' => 0, 'billable' => 0 );
			}
			$by_source[ $source ]['opt_ins']++;

			if ( ! self::has_call_text_consent( $lead ) ) {
				continue;
			}
			$by_source[ $source ]['opt_ins_call_text']++;

			if ( ! self::has_valid_contact( $lead ) ) {
				continue;
			}
			$email = strtolower( $lead['email'] );
			if ( isset( $seen[ $email ] ) ) {
				$duplicates++;
				continue;
			}
			$seen[ $email ] = true;

			$by_source[ $source ]['billable']++;
			$billable[] = array(
				'name'            => $lead['name'],
				'email'           => $lead['email'],
				'phone'           => $lead['phone'],
				'state'           => $lead['state'],
				'created'         => $lead['captured_at'],
				'source_platform' => $source,
				'thread_url'      => self::thread_url( $lead ),
			);
		}

		return array(
			'by_source'           => $by_source,
			'billable'            => $billable,
			'duplicates_excluded' => $duplicates,
		);
	}

	/**
	 * The month's leads, oldest capture first. Paged so a large month never loads every post at once.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function in_range( Freshmints_Pilot_Report_Range $range ): array {
		$leads = array();

		for ( $page = 1; ; $page++ ) {
			$query = new WP_Query(
				array(
					'post_type'      => Lead_Magnet_Leads::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'posts_per_page' => self::PAGE_SIZE,
					'paged'          => $page,
					'no_found_rows'  => true,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => Lead_Magnet_Leads::META_PREFIX . 'captured_at',
							'value'   => $range->from_captured(),
							'compare' => '>=',
							'type'    => 'CHAR',
						),
						array(
							'key'     => Lead_Magnet_Leads::META_PREFIX . 'captured_at',
							'value'   => $range->to_captured(),
							'compare' => '<',
							'type'    => 'CHAR',
						),
					),
				)
			);

			foreach ( $query->posts as $post_id ) {
				$lead = Lead_Magnet_Leads::get( (int) $post_id );
				if ( null !== $lead ) {
					$leads[] = $lead;
				}
			}

			if ( count( $query->posts ) < self::PAGE_SIZE ) {
				break;
			}
		}

		usort(
			$leads,
			static function ( array $a, array $b ): int {
				return strcmp( $a['captured_at'], $b['captured_at'] ) ?: ( $a['id'] <=> $b['id'] );
			}
		);

		return $leads;
	}

	/** A known platform when the lead came from a reply, else 'other'. */
	private static function source_of( array $lead ): string {
		$from_reply = self::SOCIAL_MEDIUM === strtolower( $lead['utm_medium'] );
		$platform   = sanitize_key( $lead['utm_source'] );

		return ( $from_reply && in_array( $platform, Freshmints_Pilot_Events::PLATFORMS, true ) )
			? $platform
			: Freshmints_Pilot_Events::OTHER_PLATFORM;
	}

	/** The consent box was checked and a phone number was given: calls and texts are allowed. */
	private static function has_call_text_consent( array $lead ): bool {
		return true === $lead['consent_given'] && array() !== array_intersect( array( 'phone', 'sms' ), $lead['contact_channels'] );
	}

	private static function has_valid_contact( array $lead ): bool {
		return 1 === preg_match( self::PHONE_PATTERN, $lead['phone'] )
			&& false !== is_email( $lead['email'] )
			&& '' !== trim( $lead['name'] );
	}

	/** The reply thread from the attribution, kept only when it is an http(s) URL. */
	private static function thread_url( array $lead ): string {
		return esc_url_raw( $lead['fm_thread'], array( 'http', 'https' ) );
	}
}
