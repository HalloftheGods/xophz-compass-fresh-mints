<?php
/**
 * Admin-only REST routes for the Agent Finder sync (administrators only: manage_options on every route).
 *
 * GET  /wp-json/xophz-freshmints/v1/agent-finder/runs      page, per_page
 *        -> { runs: [...], total, page, perPage, totalPages }   newest run first
 * GET  /wp-json/xophz-freshmints/v1/agent-finder/agents    page, per_page, since, annuity_capable, delivery_status
 *        -> { agents: [...], total, page, perPage, totalPages } newest first
 *        since: ISO 8601 date or datetime (no zone means UTC); keeps agents first seen at or after it.
 *        annuity_capable: true or false; omit for both.  delivery_status: pending, delivered or failed.
 * POST /wp-json/xophz-freshmints/v1/agent-finder/run-now   no body
 *        -> { run: {...} } when the run finished, even a partial one; 409 freshmints_agent_finder_running
 *           when a run is already going. The run is synchronous: a normal 7-day window takes a few seconds.
 *
 * An empty table is an empty list with total 0, never made-up rows. Times are UTC, as ISO 8601 with a Z.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-agent-finder-sync.php';

class Freshmints_Agent_Finder_Rest {

	const REST_NAMESPACE = 'xophz-freshmints/v1';
	const MAX_PER_PAGE   = 100;

	public function register_routes(): void {
		$paging = array(
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ),
		);

		register_rest_route( self::REST_NAMESPACE, '/agent-finder/runs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_runs' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => $paging,
		) );

		register_rest_route( self::REST_NAMESPACE, '/agent-finder/agents', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_agents' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => $paging + array(
				'since'           => array(
					'type'              => 'string',
					'validate_callback' => array( $this, 'validate_since' ),
					'sanitize_callback' => array( $this, 'sanitize_since' ),
				),
				'annuity_capable' => array( 'type' => 'boolean' ),
				'delivery_status' => array( 'type' => 'string', 'enum' => Freshmints_Agent_Finder_Store::DELIVERY_STATUSES ),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/agent-finder/run-now', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_run_now' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function validate_since( $value ): bool {
		return is_string( $value ) && self::parse_since( $value ) !== null;
	}

	public function sanitize_since( $value ): string {
		$stamp = is_string( $value ) ? self::parse_since( $value ) : null;
		return $stamp === null ? '' : gmdate( 'Y-m-d H:i:s', $stamp );
	}

	public function handle_runs( WP_REST_Request $request ): WP_REST_Response {
		$page   = (int) $request['page'];
		$per    = (int) $request['per_page'];
		$result = Freshmints_Agent_Finder_Store::list_runs( $page, $per );

		return $this->paged( 'runs', array_map( array( $this, 'shape_run' ), $result['rows'] ), $result['total'], $page, $per );
	}

	public function handle_agents( WP_REST_Request $request ): WP_REST_Response {
		$page    = (int) $request['page'];
		$per     = (int) $request['per_page'];
		$filters = array();

		if ( ! empty( $request['since'] ) ) {
			$filters['since'] = (string) $request['since'];
		}
		if ( $request->has_param( 'annuity_capable' ) ) {
			$filters['annuity_capable'] = (bool) rest_sanitize_boolean( $request['annuity_capable'] );
		}
		if ( ! empty( $request['delivery_status'] ) ) {
			$filters['delivery_status'] = (string) $request['delivery_status'];
		}

		$result = Freshmints_Agent_Finder_Store::list_agents( $filters, $page, $per );

		return $this->paged( 'agents', array_map( array( $this, 'shape_agent' ), $result['rows'] ), $result['total'], $page, $per );
	}

	public function handle_run_now() {
		$run = ( new Freshmints_Agent_Finder_Sync() )->run( 'manual' );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		// The ids go to the delivery listeners; the response only carries the run's counts.
		unset( $run['new_agent_ids'] );
		return new WP_REST_Response( array( 'run' => $this->shape_run( $run ) ) );
	}

	private function paged( string $key, array $items, int $total, int $page, int $per ): WP_REST_Response {
		$pages    = (int) ceil( $total / $per );
		$response = new WP_REST_Response( array(
			$key         => $items,
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per,
			'totalPages' => $pages,
		) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	private function shape_run( array $r ): array {
		$status = 'complete';
		if ( empty( $r['finished_at'] ) ) {
			$status = 'unfinished';
		} elseif ( (int) $r['partial'] === 1 ) {
			$status = 'partial';
		}

		return array(
			'id'          => (int) $r['id'],
			'trigger'     => (string) $r['trigger_source'],
			'status'      => $status,
			'startedAt'   => self::iso( $r['started_at'] ),
			'finishedAt'  => self::iso( $r['finished_at'] ),
			'windowStart' => self::iso( $r['window_start'] ),
			'windowEnd'   => self::iso( $r['window_end'] ),
			'found'       => (int) $r['found'],
			'newCount'    => (int) $r['new_count'],
			'skipped'     => (int) $r['skipped'],
			'partial'     => (int) $r['partial'] === 1,
			'errorCode'   => (string) $r['error_code'],
			'durationMs'  => (int) $r['duration_ms'],
		);
	}

	private function shape_agent( array $a ): array {
		return array(
			'id'             => (int) $a['id'],
			'npn'            => (string) $a['npn'],
			'name'           => (string) $a['name'],
			'licenseType'    => (string) $a['license_type'],
			'qualification'  => (string) $a['qualification'],
			'annuityCapable' => (int) $a['annuity_capable'] === 1,
			'firstIssueDate' => $a['first_issue_date'] === null ? null : (string) $a['first_issue_date'],
			'city'           => (string) $a['city'],
			'state'          => (string) $a['state'],
			'zip'            => (string) $a['zip'],
			'sourceLabel'    => (string) $a['source_label'],
			'firstSeenRunId' => (int) $a['first_seen_run_id'],
			'firstSeenAt'    => self::iso( $a['first_seen_at'] ),
			'deliveryStatus' => (string) $a['delivery_status'],
			'deliveredAt'    => self::iso( $a['delivered_at'] ),
			'attempts'       => (int) $a['attempts'],
		);
	}

	/** A stored UTC datetime as ISO 8601 with a Z, or null when it is empty. */
	private static function iso( $mysql_utc ): ?string {
		if ( ! is_string( $mysql_utc ) || $mysql_utc === '' ) {
			return null;
		}
		$stamp = strtotime( $mysql_utc . ' UTC' );
		return $stamp === false ? null : gmdate( 'Y-m-d\TH:i:s\Z', $stamp );
	}

	/** Unix time for an ISO 8601 date or datetime (UTC when it names no zone), or null when it is anything else. */
	private static function parse_since( string $value ): ?int {
		$value   = trim( $value );
		$pattern = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/';
		if ( ! preg_match( $pattern, $value ) ) {
			return null;
		}
		$has_zone = strlen( $value ) > 10 && preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value );
		$stamp    = strtotime( $has_zone ? $value : $value . ' UTC' );
		return $stamp === false ? null : $stamp;
	}
}
