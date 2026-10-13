<?php
/**
 * Storage for the Agent Finder weekly sync: two custom tables and the queries on them.
 *
 *   {prefix}freshmints_af_agents  one row per NPN (unique key), the newly licensed agent as the registry gave it
 *   {prefix}freshmints_af_runs    one row per sync run: window, counts, partial flag, error code, timing
 *
 * Why tables and not a custom post type: an agent is looked up by NPN, listed with filters and counted in
 * the thousands, and a unique key is what makes the sync idempotent. Posts and postmeta can do none of that
 * without a second index.
 *
 * The sync only ever INSERTs agents (INSERT IGNORE on the unique NPN). A row that exists is never rewritten,
 * delivered or not, so the identity fields keep what the first run saw. record_delivery() is the one writer
 * of the delivery columns, for the delivery task (#6024).
 *
 * Times are stored in UTC. The tables are created and upgraded with dbDelta and are left in place when the
 * plugin is deactivated.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Agent_Finder_Store {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'freshmints_agent_finder_db_version';
	const INSERT_CHUNK      = 200;

	const DELIVERY_STATUSES = array( 'pending', 'delivered', 'failed' );

	public static function agents_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'freshmints_af_agents';
	}

	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'freshmints_af_runs';
	}

	/**
	 * Create or upgrade the tables. Safe to call any number of times.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$agents  = self::agents_table();
		$runs    = self::runs_table();

		dbDelta( "CREATE TABLE {$agents} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			npn VARCHAR(32) NOT NULL,
			name VARCHAR(255) NOT NULL,
			license_type VARCHAR(100) NOT NULL DEFAULT '',
			qualification VARCHAR(100) NOT NULL DEFAULT '',
			annuity_capable TINYINT(1) NOT NULL DEFAULT 0,
			first_issue_date DATE NULL,
			city VARCHAR(100) NOT NULL DEFAULT '',
			state VARCHAR(2) NOT NULL DEFAULT '',
			zip VARCHAR(16) NOT NULL DEFAULT '',
			source_label VARCHAR(191) NOT NULL DEFAULT '',
			first_seen_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_seen_at DATETIME NOT NULL,
			delivery_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			delivered_at DATETIME NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY npn (npn),
			KEY delivery_status (delivery_status,id),
			KEY first_seen_run_id (first_seen_run_id),
			KEY first_seen_at (first_seen_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trigger_source VARCHAR(16) NOT NULL DEFAULT 'cron',
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			window_start DATETIME NOT NULL,
			window_end DATETIME NOT NULL,
			found INT UNSIGNED NOT NULL DEFAULT 0,
			new_count INT UNSIGNED NOT NULL DEFAULT 0,
			skipped INT UNSIGNED NOT NULL DEFAULT 0,
			partial TINYINT(1) NOT NULL DEFAULT 0,
			error_code VARCHAR(48) NOT NULL DEFAULT '',
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY started_at (started_at)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** Open a run row; finish_run() closes it. A run left open was interrupted. */
	public static function start_run( string $trigger, int $window_start_ts, int $window_end_ts ): int {
		global $wpdb;
		$wpdb->insert(
			self::runs_table(),
			array(
				'trigger_source' => $trigger,
				'started_at'     => gmdate( 'Y-m-d H:i:s' ),
				'window_start'   => gmdate( 'Y-m-d H:i:s', $window_start_ts ),
				'window_end'     => gmdate( 'Y-m-d H:i:s', $window_end_ts ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array{found:int,new_count:int,skipped:int,partial:bool,error_code:string,duration_ms:int} $result
	 */
	public static function finish_run( int $run_id, array $result ): void {
		global $wpdb;
		$wpdb->update(
			self::runs_table(),
			array(
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'found'       => $result['found'],
				'new_count'   => $result['new_count'],
				'skipped'     => $result['skipped'],
				'partial'     => $result['partial'] ? 1 : 0,
				'error_code'  => $result['error_code'],
				'duration_ms' => $result['duration_ms'],
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Unix time the last fully completed run's window ended, or null when no run has completed.
	 * Completed means finished and not partial; a capped window is still complete (its error code only informs).
	 */
	public static function last_complete_window_end(): ?int {
		global $wpdb;
		$table = self::runs_table();
		$end   = $wpdb->get_var( "SELECT MAX(window_end) FROM {$table} WHERE finished_at IS NOT NULL AND partial = 0" );
		return is_string( $end ) && $end !== '' ? (int) strtotime( $end . ' UTC' ) : null;
	}

	/**
	 * Store each agent once per NPN; an NPN already stored is left exactly as it is.
	 *
	 * @param array[] $agents Rows with npn, name, license_type, qualification, annuity_capable, first_issue_date,
	 *                        city, state, zip, source_label.
	 * @return int[] Ids of the rows this call inserted, oldest first.
	 */
	public static function insert_new_agents( array $agents, int $run_id ): array {
		global $wpdb;
		$table = self::agents_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		foreach ( array_chunk( $agents, self::INSERT_CHUNK ) as $chunk ) {
			$tuples = array();
			foreach ( $chunk as $a ) {
				$tuples[] = $wpdb->prepare(
					"(%s,%s,%s,%s,%d,NULLIF(%s,''),%s,%s,%s,%s,%d,%s)",
					$a['npn'], $a['name'], $a['license_type'], $a['qualification'], $a['annuity_capable'] ? 1 : 0,
					$a['first_issue_date'], $a['city'], $a['state'], $a['zip'], $a['source_label'], $run_id, $now
				);
			}
			$wpdb->query(
				"INSERT IGNORE INTO {$table} (npn,name,license_type,qualification,annuity_capable,first_issue_date,city,state,zip,source_label,first_seen_run_id,first_seen_at) VALUES "
				. implode( ',', $tuples )
			);
		}

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE first_seen_run_id = %d ORDER BY id", $run_id ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * The one writer of the delivery columns. Every call is one attempt. A delivered row is never touched again.
	 *
	 * @return bool True when a row was updated.
	 */
	public static function record_delivery( int $agent_id, bool $delivered ): bool {
		global $wpdb;
		$table = self::agents_table();

		if ( $delivered ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET delivery_status = 'delivered', delivered_at = %s, attempts = attempts + 1 WHERE id = %d AND delivery_status <> 'delivered'",
				gmdate( 'Y-m-d H:i:s' ),
				$agent_id
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET delivery_status = 'failed', attempts = attempts + 1 WHERE id = %d AND delivery_status <> 'delivered'",
				$agent_id
			);
		}

		return (int) $wpdb->query( $sql ) === 1;
	}

	/**
	 * @return array{rows:array[],total:int}
	 */
	public static function list_runs( int $page, int $per_page ): array {
		global $wpdb;
		$table = self::runs_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ),
			ARRAY_A
		);
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
		);
	}

	public static function get_run( int $run_id ): ?array {
		global $wpdb;
		$table = self::runs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{since?:string,annuity_capable?:bool,delivery_status?:string} $filters since is a UTC datetime
	 *        compared with first_seen_at; annuity_capable and delivery_status apply only when the key is set.
	 * @return array{rows:array[],total:int}
	 */
	public static function list_agents( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = self::agents_table();
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['since'] ) ) {
			$where[] = 'first_seen_at >= %s';
			$args[]  = $filters['since'];
		}
		if ( isset( $filters['annuity_capable'] ) ) {
			$where[] = 'annuity_capable = %d';
			$args[]  = $filters['annuity_capable'] ? 1 : 0;
		}
		if ( ! empty( $filters['delivery_status'] ) ) {
			$where[] = 'delivery_status = %s';
			$args[]  = $filters['delivery_status'];
		}

		$clause = implode( ' AND ', $where );
		$count  = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$select = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";

		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $select, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}
}
