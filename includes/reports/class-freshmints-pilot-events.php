<?php
/**
 * Pilot counters kept on the server: how many posts each Social Radar search returned, and which
 * social leads had a reply dispatched. The browser holds the social leads themselves; the server
 * keeps only these counts and ids, so the monthly pilot report has numbers to show.
 *
 * One small table, {prefix}freshmints_pilot_events, one row per event:
 *
 *   kind        'fetch'      one /social/fetch run that answered with status ok; item_count = posts returned
 *               'reply_sent' one /social/dispatch the human started; item_count = 1
 *   platform    reddit, stack_exchange, youtube, hacker_news, bluesky, x, or 'other'
 *   lead_id     the app's social lead id (the same value the funnel link carries as fm_reply); '' for fetch
 *   via         'webhook' the dispatch webhook accepted the reply, 'copy' no webhook is set up and the app
 *               copied the reply for the human to paste; '' for fetch
 *   created_at  UTC
 *
 * What is never stored: post text, titles, authors, URLs, subreddits, keywords or reply text. Reddit
 * was promised no profiles, and an id or a count is not one.
 *
 * A post that two searches both return counts twice in posts found (no post ids are kept, so there is
 * nothing to dedupe on). Replies are deduped by lead id when the report counts them.
 *
 * The table is created on first use with dbDelta and left in place when the plugin is deactivated.
 * Rows are tiny and the report needs old months for billing, so nothing is purged.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Events {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'freshmints_pilot_events_db_version';

	const KIND_FETCH = 'fetch';
	const KIND_REPLY = 'reply_sent';
	const VIA_WEBHOOK = 'webhook';
	const VIA_COPY    = 'copy';

	const OTHER_PLATFORM = 'other';
	const PLATFORMS      = array( 'reddit', 'stack_exchange', 'youtube', 'hacker_news', 'bluesky', 'x' );
	const LEAD_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'freshmints_pilot_events';
	}

	/**
	 * Create or upgrade the table. Safe to call any number of times.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(16) NOT NULL,
			platform VARCHAR(32) NOT NULL,
			item_count INT UNSIGNED NOT NULL DEFAULT 0,
			lead_id VARCHAR(64) NOT NULL DEFAULT '',
			via VARCHAR(16) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY kind_created (kind,created_at)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * True when the table is there to read. The report says "not available" instead of guessing zero
	 * when it is not.
	 */
	public static function is_ready(): bool {
		global $wpdb;
		self::maybe_install();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
		return self::table() === $found;
	}

	/**
	 * Count one /social/fetch run. Only a run that answered ok is counted; a blocked or failed run
	 * returned no posts to count.
	 *
	 * @param mixed $outcome What the provider returned: { status: 'ok', posts: [...] } or a failure.
	 */
	public static function record_fetch( string $platform, $outcome ): void {
		$is_ok = is_array( $outcome ) && ( $outcome['status'] ?? '' ) === 'ok' && is_array( $outcome['posts'] ?? null );
		if ( ! $is_ok ) {
			return;
		}

		self::insert( self::KIND_FETCH, $platform, count( $outcome['posts'] ), '', '' );
	}

	/**
	 * Count one reply dispatched by the human.
	 *
	 * @param mixed $platform Straight from the request; anything unknown is stored as 'other'.
	 * @param mixed $lead_id  The app's social lead id; anything that is not a plain id is stored as ''.
	 */
	public static function record_reply( $platform, $lead_id, string $via ): void {
		$via = self::VIA_WEBHOOK === $via ? self::VIA_WEBHOOK : self::VIA_COPY;
		self::insert( self::KIND_REPLY, $platform, 1, self::clean_lead_id( $lead_id ), $via );
	}

	/**
	 * Searches and posts returned per platform in a window.
	 *
	 * @param string $from_utc Inclusive, 'Y-m-d H:i:s'.
	 * @param string $to_utc   Exclusive, 'Y-m-d H:i:s'.
	 * @return array<string, array{searches: int, posts: int}>
	 */
	public static function fetch_totals( string $from_utc, string $to_utc ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT platform, COUNT(*) AS searches, SUM(item_count) AS posts FROM {$table} WHERE kind = %s AND created_at >= %s AND created_at < %s GROUP BY platform",
				self::KIND_FETCH,
				$from_utc,
				$to_utc
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( (array) $rows as $row ) {
			$totals[ $row['platform'] ] = array(
				'searches' => (int) $row['searches'],
				'posts'    => (int) $row['posts'],
			);
		}
		return $totals;
	}

	/**
	 * Replies dispatched per platform in a window. A lead dispatched twice counts once; dispatches
	 * that carry no lead id each count once, since nothing says they are the same reply.
	 *
	 * @return array<string, array{replies: int, via_webhook: int}>
	 */
	public static function reply_totals( string $from_utc, string $to_utc ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, platform, lead_id, via FROM {$table} WHERE kind = %s AND created_at >= %s AND created_at < %s",
				self::KIND_REPLY,
				$from_utc,
				$to_utc
			),
			ARRAY_A
		);

		$replies  = array();
		$webhooks = array();
		foreach ( (array) $rows as $row ) {
			$key = '' !== $row['lead_id'] ? 'lead:' . $row['lead_id'] : 'event:' . $row['id'];
			$replies[ $row['platform'] ][ $key ] = true;
			if ( self::VIA_WEBHOOK === $row['via'] ) {
				$webhooks[ $row['platform'] ][ $key ] = true;
			}
		}

		$totals = array();
		foreach ( $replies as $platform => $keys ) {
			$totals[ $platform ] = array(
				'replies'     => count( $keys ),
				'via_webhook' => count( $webhooks[ $platform ] ?? array() ),
			);
		}
		return $totals;
	}

	/** A known platform id, or 'other'. */
	public static function clean_platform( $platform ): string {
		$platform = is_string( $platform ) ? sanitize_key( $platform ) : '';
		return in_array( $platform, self::PLATFORMS, true ) ? $platform : self::OTHER_PLATFORM;
	}

	private static function clean_lead_id( $lead_id ): string {
		return is_string( $lead_id ) && 1 === preg_match( self::LEAD_ID_PATTERN, $lead_id ) ? $lead_id : '';
	}

	/**
	 * Counting must never break the request it rides on: a failed insert is dropped, and wpdb's own
	 * error output (which WP_DEBUG_DISPLAY could print into a JSON response) is held back.
	 */
	private static function insert( string $kind, $platform, int $item_count, string $lead_id, string $via ): void {
		global $wpdb;
		self::maybe_install();

		$previous = $wpdb->suppress_errors( true );
		$wpdb->insert(
			self::table(),
			array(
				'kind'       => $kind,
				'platform'   => self::clean_platform( $platform ),
				'item_count' => max( 0, $item_count ),
				'lead_id'    => $lead_id,
				'via'        => $via,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous );
	}
}
