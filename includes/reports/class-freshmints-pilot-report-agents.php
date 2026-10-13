<?php
/**
 * Agents delivered per week, from the Agent Finder sync table (Freshmints_Agent_Finder_Store).
 *
 * An agent counts in the week its delivered_at falls in, on the site's clock. Weeks start on Monday
 * and are labeled by that Monday; the first and last week of a month hold only the days that belong
 * to the month. Every week of the month is listed, with 0 where nothing was delivered.
 *
 * When the Agent Finder store is not loaded, its table does not exist, or the table lacks the
 * delivery columns, the answer is "not available" with a reason. A missing source is never
 * reported as zero.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Report_Agents {

	const DELIVERED = 'delivered';

	/**
	 * @return array{available: false, reason: string}|array{available: true, total: int, weeks: array<int, array{week_start: string, delivered: int}>}
	 */
	public static function weekly( Freshmints_Pilot_Report_Range $range ): array {
		$table = self::delivery_table();
		if ( null === $table ) {
			return array(
				'available' => false,
				'reason'    => 'The Agent Finder delivery table does not exist yet.',
			);
		}

		global $wpdb;
		$stamps = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT delivered_at FROM {$table} WHERE delivery_status = %s AND delivered_at >= %s AND delivered_at < %s",
				self::DELIVERED,
				$range->from_utc(),
				$range->to_utc()
			)
		);

		$weeks = self::empty_weeks( $range );
		$utc   = new DateTimeZone( 'UTC' );
		$zone  = $range->start->getTimezone();
		foreach ( (array) $stamps as $stamp ) {
			$moment = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $stamp, $utc );
			if ( false === $moment ) {
				continue;
			}
			$local = $moment->setTimezone( $zone );
			$key   = $local->modify( 'monday this week' )->format( 'Y-m-d' );
			if ( isset( $weeks[ $key ] ) ) {
				$weeks[ $key ]++;
			}
		}

		$rows = array();
		foreach ( $weeks as $week_start => $delivered ) {
			$rows[] = array( 'week_start' => $week_start, 'delivered' => $delivered );
		}

		return array(
			'available' => true,
			'total'     => array_sum( $weeks ),
			'weeks'     => $rows,
		);
	}

	/** The agents table name, or null when the store, the table or the delivery columns are missing. */
	private static function delivery_table(): ?string {
		if ( ! class_exists( 'Freshmints_Agent_Finder_Store' ) ) {
			return null;
		}

		global $wpdb;
		$table = Freshmints_Agent_Finder_Store::agents_table();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return null;
		}

		foreach ( array( 'delivery_status', 'delivered_at' ) as $column ) {
			if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $wpdb->esc_like( $column ) ) ) ) {
				return null;
			}
		}

		return $table;
	}

	/**
	 * Every Monday-start week that touches the month, each at 0.
	 *
	 * @return array<string, int> week start 'Y-m-d' => 0
	 */
	private static function empty_weeks( Freshmints_Pilot_Report_Range $range ): array {
		$weeks = array();
		$week  = $range->start->modify( 'monday this week' );

		while ( $week < $range->end ) {
			$weeks[ $week->format( 'Y-m-d' ) ] = 0;
			$week                              = $week->modify( '+7 days' );
		}

		return $weeks;
	}
}
