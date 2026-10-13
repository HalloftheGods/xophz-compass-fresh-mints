<?php
/**
 * Relays each captured Lead Magnet lead to HighLevel as the event lead_magnet_opt_in.
 *
 * The Lead Magnet plugin fires `xophz_lead_magnet_lead_captured( array $lead )` once per stored lead. This
 * class marks the lead `pending` on its own record, then sends it from a background action so the visitor's
 * request never waits on HighLevel. The send goes through Freshmints_Crm_Webhook_Rest::send_event(), so the
 * webhook URL stays inside that class. What is sent is built by Freshmints_Crm_Payloads::lead(): the consent
 * the lead gave, the exact wording it saw, and no channel it did not agree to.
 *
 * Delivery state lives on the lead's post under keys of this plugin (the Lead Magnet fields are untouched):
 *   _freshmints_crm_delivery      pending | delivered | failed
 *   _freshmints_crm_attempts      sends tried; automatic retries stop at Freshmints_Crm_Delivery::MAX_ATTEMPTS
 *   _freshmints_crm_delivered_at  UTC, set on success
 *
 * With no webhook configured nothing is sent and the lead stays `pending`; the admin retry sends it later.
 * Leads captured before this class existed carry no state and are never sent.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Crm_Lead_Delivery {

	const HOOK         = 'freshmints_crm_deliver_lead';
	const CAPTURED     = 'xophz_lead_magnet_lead_captured';
	const POST_TYPE    = 'compass_lead';
	const META_STATUS  = '_freshmints_crm_delivery';
	const META_TRIES   = '_freshmints_crm_attempts';
	const META_SENT_AT = '_freshmints_crm_delivered_at';

	/** Seconds before the 2nd, 3rd, 4th and 5th attempt. */
	const BACKOFF = array( 300, 1800, 7200, 21600 );

	public static function register(): void {
		add_action( self::CAPTURED, array( __CLASS__, 'on_captured' ) );
		add_action( self::HOOK, array( __CLASS__, 'deliver' ) );
	}

	/** @param mixed $lead The stored record the capture route passes (an array with its post id under `id`). */
	public static function on_captured( $lead ): void {
		$id = is_array( $lead ) ? (int) ( $lead['id'] ?? 0 ) : 0;
		if ( $id <= 0 || get_post_type( $id ) !== self::POST_TYPE ) {
			return;
		}

		update_post_meta( $id, self::META_STATUS, 'pending' );
		update_post_meta( $id, self::META_TRIES, 0 );

		if ( Freshmints_Crm_Webhook_Rest::is_configured() ) {
			Freshmints_Crm_Delivery::enqueue( self::HOOK, array( $id ) );
		}
	}

	/**
	 * One send attempt for one lead.
	 *
	 * @param mixed $lead_id Lead post id.
	 * @return string delivered, failed, not_configured or skipped (already delivered, attempts used up, no
	 *                state, or not a lead).
	 */
	public static function deliver( $lead_id ): string {
		$id     = (int) $lead_id;
		$status = (string) get_post_meta( $id, self::META_STATUS, true );
		if ( get_post_type( $id ) !== self::POST_TYPE || ! in_array( $status, array( 'pending', 'failed' ), true ) || ! class_exists( 'Lead_Magnet_Leads' ) ) {
			return 'skipped';
		}

		// Scheduled retries and the admin retry can overlap; the cap holds whichever runs.
		if ( (int) get_post_meta( $id, self::META_TRIES, true ) >= Freshmints_Crm_Delivery::MAX_ATTEMPTS ) {
			return 'skipped';
		}

		$lead = Lead_Magnet_Leads::get( $id );
		if ( $lead === null ) {
			return 'skipped';
		}

		$sent = ( new Freshmints_Crm_Webhook_Rest() )->send_event( Freshmints_Crm_Payloads::EVENT_LEAD, Freshmints_Crm_Payloads::lead( $lead ) );
		if ( ! $sent['configured'] ) {
			return 'not_configured';
		}

		$tries = (int) get_post_meta( $id, self::META_TRIES, true ) + 1;
		update_post_meta( $id, self::META_TRIES, $tries );

		if ( $sent['ok'] ) {
			update_post_meta( $id, self::META_STATUS, 'delivered' );
			update_post_meta( $id, self::META_SENT_AT, gmdate( 'Y-m-d\TH:i:s\Z' ) );
			return 'delivered';
		}

		update_post_meta( $id, self::META_STATUS, 'failed' );
		if ( $tries < Freshmints_Crm_Delivery::MAX_ATTEMPTS ) {
			Freshmints_Crm_Delivery::enqueue( self::HOOK, array( $id ), self::BACKOFF[ min( $tries, count( self::BACKOFF ) ) - 1 ] );
		}

		return 'failed';
	}

	/**
	 * Send the pending and failed leads that still have attempts left, oldest first, for the admin retry.
	 *
	 * @return array{delivered:int,failed:int,remaining:int}
	 */
	public static function retry( float $budget_seconds ): array {
		$out     = array( 'delivered' => 0, 'failed' => 0, 'remaining' => 0 );
		$started = microtime( true );

		foreach ( self::retryable_ids( Freshmints_Crm_Delivery::BATCH_SIZE ) as $id ) {
			if ( ( microtime( true ) - $started ) >= $budget_seconds ) {
				break;
			}
			$outcome = self::deliver( $id );
			if ( $outcome === 'not_configured' ) {
				break;
			}
			if ( isset( $out[ $outcome ] ) ) {
				++$out[ $outcome ];
			}
		}

		$out['remaining'] = count( self::retryable_ids( 1000 ) );

		return $out;
	}

	/**
	 * @return array{pending:int,failed:int,delivered:int,exhausted:int} exhausted = failed leads at the attempt cap.
	 */
	public static function counts(): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.meta_value AS status, COUNT(*) AS total, SUM(CAST(t.meta_value AS UNSIGNED) >= %d) AS capped' . self::from() . ' GROUP BY s.meta_value',
				Freshmints_Crm_Delivery::MAX_ATTEMPTS,
				self::META_STATUS,
				self::META_TRIES,
				self::POST_TYPE
			),
			ARRAY_A
		);
		$counts = array( 'pending' => 0, 'failed' => 0, 'delivered' => 0, 'exhausted' => 0 );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
			if ( $row['status'] === 'failed' ) {
				$counts['exhausted'] = (int) $row['capped'];
			}
		}

		return $counts;
	}

	/**
	 * Give every failed lead its attempts back, for the admin retry that overrides the cap. It goes through
	 * update_post_meta, not one UPDATE, so a persistent object cache cannot keep serving the old count.
	 *
	 * @return int Leads changed.
	 */
	public static function reset_failed_attempts(): int {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT p.ID' . self::from() . " AND s.meta_value = 'failed' AND CAST(t.meta_value AS UNSIGNED) > 0",
				self::META_STATUS,
				self::META_TRIES,
				self::POST_TYPE
			)
		);

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			update_post_meta( (int) $id, self::META_TRIES, 0 );
		}

		return count( (array) $ids );
	}

	/** @return int[] Lead post ids, oldest first. */
	private static function retryable_ids( int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID" . self::from() . " AND s.meta_value IN ('pending','failed') AND CAST(t.meta_value AS UNSIGNED) < %d ORDER BY p.ID ASC LIMIT %d",
				self::META_STATUS,
				self::META_TRIES,
				self::POST_TYPE,
				Freshmints_Crm_Delivery::MAX_ATTEMPTS,
				$limit
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * The FROM and WHERE that reach a lead's status (s) and attempts (t) meta without a logged-in user, which
	 * a WP_Query on this private post type would need. Placeholders: status key, attempts key, post type.
	 */
	private static function from(): string {
		global $wpdb;
		return " FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s WHERE p.post_type = %s AND p.post_status <> 'trash'";
	}
}
