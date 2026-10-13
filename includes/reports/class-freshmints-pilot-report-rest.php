<?php
/**
 * Admin-only REST for the 30-day pilot report and the $75-per-lead invoice.
 *
 * GET /wp-json/xophz-freshmints/v1/pilot-report?month=YYYY-MM
 *   month defaults to the current month on the site's clock.
 *   response: {
 *     month, range: { from, to, timezone },
 *     platforms: [ { platform, label, searches, posts_found, replies_sent, replies_via_webhook,
 *                    opt_ins, opt_ins_call_text, billable_leads } ],
 *     totals:    { the same counts summed, plus duplicates_excluded },
 *     agents:    { available: true, total, weeks: [ { week_start, delivered } ] }
 *                | { available: false, reason },
 *     sources:   { events: { available, reason? }, lead_magnet: { available, reason? } }
 *   }
 *   A count is null, never 0, when the report cannot know it: the source is missing, or the platform's
 *   searches do not run on the server (posts found for Hacker News, Bluesky and X). Where each number
 *   comes from is in Freshmints_Pilot_Events and Freshmints_Pilot_Report_Leads.
 *
 * GET /wp-json/xophz-freshmints/v1/pilot-report/billable?month=YYYY-MM
 *   response: { month, filename, count, duplicates_excluded, csv }
 *   csv is the billable leads as CSV text (name, email, phone, state, created, source_platform,
 *   thread_url) with spreadsheet-formula guards; see Freshmints_Pilot_Report_Csv.
 *   error: freshmints_pilot_report_leads_unavailable (503) when Lead Magnet is not active.
 *
 * Both routes require manage_options: anonymous gets 401, a logged-in user without it gets 403.
 * Error for a bad month: freshmints_pilot_report_invalid_month (400).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Report_Rest {

	const REST_NAMESPACE = 'xophz-freshmints/v1';

	const LABELS = array(
		'reddit'         => 'Reddit',
		'stack_exchange' => 'Stack Exchange',
		'youtube'        => 'YouTube',
		'hacker_news'    => 'Hacker News',
		'bluesky'        => 'Bluesky',
		'x'              => 'X',
		'other'          => 'Other sources',
	);

	const COUNT_KEYS = array(
		'searches',
		'posts_found',
		'replies_sent',
		'replies_via_webhook',
		'opt_ins',
		'opt_ins_call_text',
		'billable_leads',
	);

	public function register_routes() {
		$args = array(
			'month' => array(
				'description' => 'The month to report, YYYY-MM. Defaults to the current month.',
				'type'        => 'string',
				'required'    => false,
			),
		);

		register_rest_route( self::REST_NAMESPACE, '/pilot-report', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_report' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => $args,
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/pilot-report/billable', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_billable' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => $args,
			),
		) );
	}

	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function handle_report( WP_REST_Request $request ) {
		$range = Freshmints_Pilot_Report_Range::parse( $request->get_param( 'month' ) );
		if ( null === $range ) {
			return $this->invalid_month();
		}

		$events_ready = Freshmints_Pilot_Events::is_ready();
		$leads_ready  = Freshmints_Pilot_Report_Leads::is_available();
		$leads        = $leads_ready ? Freshmints_Pilot_Report_Leads::summarize( $range ) : null;

		$fetches = $events_ready ? Freshmints_Pilot_Events::fetch_totals( $range->from_utc(), $range->to_utc() ) : array();
		$replies = $events_ready ? Freshmints_Pilot_Events::reply_totals( $range->from_utc(), $range->to_utc() ) : array();
		$sources = null === $leads ? array() : $leads['by_source'];

		$platforms = array();
		foreach ( $this->platform_ids( $fetches, $replies, $sources ) as $id ) {
			$platforms[] = $this->row( $id, $events_ready, $leads_ready, $fetches, $replies, $sources );
		}

		$totals                        = $this->totals( $platforms );
		$totals['duplicates_excluded'] = null === $leads ? null : $leads['duplicates_excluded'];

		$response = rest_ensure_response( array(
			'month'     => $range->month,
			'range'     => $range->to_array(),
			'platforms' => $platforms,
			'totals'    => $totals,
			'agents'    => Freshmints_Pilot_Report_Agents::weekly( $range ),
			'sources'   => array(
				'events'      => $events_ready
					? array( 'available' => true )
					: array( 'available' => false, 'reason' => 'The pilot events table could not be created.' ),
				'lead_magnet' => $leads_ready
					? array( 'available' => true )
					: array( 'available' => false, 'reason' => 'The Lead Magnet plugin is not active.' ),
			),
		) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public function handle_billable( WP_REST_Request $request ) {
		$range = Freshmints_Pilot_Report_Range::parse( $request->get_param( 'month' ) );
		if ( null === $range ) {
			return $this->invalid_month();
		}

		if ( ! Freshmints_Pilot_Report_Leads::is_available() ) {
			return new WP_Error(
				'freshmints_pilot_report_leads_unavailable',
				'The Lead Magnet plugin is not active, so there are no leads to export.',
				array( 'status' => 503 )
			);
		}

		$leads    = Freshmints_Pilot_Report_Leads::summarize( $range );
		$response = rest_ensure_response( array(
			'month'               => $range->month,
			'filename'            => 'fresh-mints-billable-leads-' . $range->month . '.csv',
			'count'               => count( $leads['billable'] ),
			'duplicates_excluded' => $leads['duplicates_excluded'],
			'csv'                 => Freshmints_Pilot_Report_Csv::build( $leads['billable'] ),
		) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private function invalid_month(): WP_Error {
		return new WP_Error(
			'freshmints_pilot_report_invalid_month',
			'Send "month" as YYYY-MM, for example 2026-10.',
			array( 'status' => 400 )
		);
	}

	/**
	 * The platforms to list: the ones searched on the server (so a zero shows as 0), then any other
	 * platform with activity in the month, then "other" last when it has any.
	 *
	 * @return string[]
	 */
	private function platform_ids( array $fetches, array $replies, array $sources ): array {
		$ids = array_keys( Freshmints_Social_Rest::PROVIDERS );

		foreach ( Freshmints_Pilot_Events::PLATFORMS as $id ) {
			$active = isset( $fetches[ $id ] ) || isset( $replies[ $id ] ) || isset( $sources[ $id ] );
			if ( $active && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		$other = Freshmints_Pilot_Events::OTHER_PLATFORM;
		if ( isset( $replies[ $other ] ) || isset( $sources[ $other ] ) ) {
			$ids[] = $other;
		}

		return $ids;
	}

	private function row( string $id, bool $events_ready, bool $leads_ready, array $fetches, array $replies, array $sources ): array {
		$is_searched = isset( Freshmints_Social_Rest::PROVIDERS[ $id ] );
		$source      = $sources[ $id ] ?? array( 'opt_ins' => 0, 'opt_ins_call_text' => 0, 'billable' => 0 );

		return array(
			'platform'            => $id,
			'label'               => self::LABELS[ $id ] ?? $id,
			'searches'            => ( $events_ready && $is_searched ) ? ( $fetches[ $id ]['searches'] ?? 0 ) : null,
			'posts_found'         => ( $events_ready && $is_searched ) ? ( $fetches[ $id ]['posts'] ?? 0 ) : null,
			'replies_sent'        => $events_ready ? ( $replies[ $id ]['replies'] ?? 0 ) : null,
			'replies_via_webhook' => $events_ready ? ( $replies[ $id ]['via_webhook'] ?? 0 ) : null,
			'opt_ins'             => $leads_ready ? $source['opt_ins'] : null,
			'opt_ins_call_text'   => $leads_ready ? $source['opt_ins_call_text'] : null,
			'billable_leads'      => $leads_ready ? $source['billable'] : null,
		);
	}

	/**
	 * Each count summed over the rows that know it; null when no row knows it.
	 */
	private function totals( array $platforms ): array {
		$totals = array();
		foreach ( self::COUNT_KEYS as $key ) {
			$known          = array_filter( array_column( $platforms, $key ), 'is_int' );
			$totals[ $key ] = array() === $known ? null : array_sum( $known );
		}
		return $totals;
	}
}
