<?php
/**
 * Reddit data handling for Social Radar: the live check and the retention window.
 *
 * What the Reddit Data API terms ask of us is made true here and in the app:
 *   - A post that was deleted or removed on Reddit is dropped. The app asks this class which of
 *     its stored post ids are still live before it shows or dispatches a lead.
 *   - Posts are kept for a short window only. The window (days) lives in one setting that the app
 *     reads; the app purges leads older than it.
 *
 * Nothing here stores a post. This class keeps no post, no author and no listing: it receives
 * ids, asks Reddit about them through Freshmints_Social_Reddit_Api, and returns the verdicts.
 * (The plugin keeps no server-side cache of social posts at all; the only Reddit state on the
 * server is the OAuth token and the rate-limit pause, neither of which holds post data.)
 *
 * POST /wp-json/xophz-freshmints/v1/social/live-check
 *   body:     { ids: string[] }  up to 100 Reddit post ids, bare ("abc123") or "t3_abc123"
 *   response: { status: 'ok', live: string[], gone: [{ id, reason }], invalid: string[] }
 *             reason is 'deleted', 'removed' or 'not_returned' (Reddit did not serve the post)
 *             or, when Reddit could not be asked and nothing is known about any id:
 *             { status: 'failed' | 'blocked', code, reason, retryAfterSeconds? }
 *             code is 'not_configured', 'rate_limited' (with retryAfterSeconds), 'unreachable'
 *             or 'failed'. No verdict is ever guessed when Reddit was not reached.
 *   errors:   freshmints_live_check_invalid (400), freshmints_live_check_too_many (400)
 *
 * GET /wp-json/xophz-freshmints/v1/social/retention
 *   response: { retentionDays: int }
 *
 * Both routes use the permission callback of /social/fetch.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Retention {

	const REST_NAMESPACE = 'xophz-freshmints/v1';
	const OPTION_DAYS    = 'xophz_compass_freshmints_social_retention_days';
	const DEFAULT_DAYS   = 30;
	const MIN_DAYS       = 1;
	const MAX_DAYS       = 90;
	const MAX_IDS        = 100;
	const INFO_PATH      = '/api/info';
	const POST_KIND      = 't3';
	const ID_PATTERN     = '/^[a-z0-9]{1,10}$/';
	const MAX_ECHO_LEN   = 32;

	/**
	 * How many days the app may keep a social lead. Always within MIN_DAYS and MAX_DAYS.
	 */
	public static function retention_days(): int {
		return self::sanitize_days( get_option( self::OPTION_DAYS, self::DEFAULT_DAYS ) );
	}

	/**
	 * Settings sanitize callback. Anything that is not a number falls back to the default;
	 * a number is clamped into the allowed range.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_days( $value ): int {
		$days = is_numeric( $value ) ? (int) $value : self::DEFAULT_DAYS;

		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	public function register_routes() {
		$permission = array( new Freshmints_Social_Rest(), 'check_permissions' );

		register_rest_route( self::REST_NAMESPACE, '/social/live-check', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_live_check' ),
				'permission_callback' => $permission,
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/social/retention', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_retention' ),
				'permission_callback' => $permission,
			),
		) );
	}

	public function handle_retention() {
		return rest_ensure_response( array( 'retentionDays' => self::retention_days() ) );
	}

	public function handle_live_check( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$raw  = $body['ids'] ?? null;

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return new WP_Error(
				'freshmints_live_check_invalid',
				'Send "ids": a list of Reddit post ids.',
				array( 'status' => 400 )
			);
		}
		if ( count( $raw ) > self::MAX_IDS ) {
			return new WP_Error(
				'freshmints_live_check_too_many',
				'Send at most ' . self::MAX_IDS . ' post ids per request.',
				array( 'status' => 400 )
			);
		}

		$cleaned = self::clean_ids( $raw );
		if ( empty( $cleaned['valid'] ) ) {
			return rest_ensure_response( self::verdicts( array(), array(), $cleaned['invalid'] ) );
		}

		$unconfigured = Freshmints_Social_Reddit_Api::configuration_error();
		if ( $unconfigured !== null ) {
			return rest_ensure_response( self::unavailable( 'failed', 'not_configured', $unconfigured['reason'] ) );
		}

		$result = Freshmints_Social_Reddit_Api::get( self::INFO_PATH, array(
			'id'       => implode( ',', self::fullnames( $cleaned['valid'] ) ),
			'raw_json' => 1,
		) );
		if ( $result['status'] !== 'ok' ) {
			return rest_ensure_response( self::unavailable_from( $result ) );
		}

		$children = $result['body']['data']['children'] ?? null;
		if ( ! is_array( $children ) ) {
			return rest_ensure_response( self::unavailable(
				'failed',
				'failed',
				'Reddit returned a response that was not a valid listing.'
			) );
		}

		$verdicts = self::classify( $cleaned['valid'], $children );

		return rest_ensure_response( self::verdicts( $verdicts['live'], $verdicts['gone'], $cleaned['invalid'] ) );
	}

	/**
	 * Lowercase, strip a "t3_" prefix, keep only plausible base36 post ids, once each.
	 *
	 * @param array $entries
	 * @return array { valid: string[], invalid: string[] } invalid holds the rejected strings, shortened.
	 */
	private static function clean_ids( array $entries ): array {
		$valid   = array();
		$invalid = array();

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$id = preg_replace( '/^t3_/', '', strtolower( trim( $entry ) ) );
			if ( preg_match( self::ID_PATTERN, $id ) === 1 ) {
				$valid[ $id ] = $id;
				continue;
			}

			$invalid[] = mb_substr( sanitize_text_field( $entry ), 0, self::MAX_ECHO_LEN );
		}

		return array( 'valid' => array_values( $valid ), 'invalid' => $invalid );
	}

	/**
	 * @param string[] $ids
	 * @return string[]
	 */
	private static function fullnames( array $ids ): array {
		$names = array();
		foreach ( $ids as $id ) {
			$names[] = self::POST_KIND . '_' . $id;
		}

		return $names;
	}

	/**
	 * Sort every requested id into live or gone using the listing Reddit returned.
	 * An id Reddit did not return is gone: Reddit no longer serves that post to this app.
	 *
	 * @param string[] $ids      Requested ids, already cleaned.
	 * @param array    $children Reddit listing children.
	 * @return array { live: string[], gone: array[] }
	 */
	private static function classify( array $ids, array $children ): array {
		$posts = self::posts_by_id( $children );
		$live  = array();
		$gone  = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $posts[ $id ] ) ) {
				$gone[] = array( 'id' => $id, 'reason' => 'not_returned' );
				continue;
			}

			$reason = self::gone_reason( $posts[ $id ] );
			if ( $reason === '' ) {
				$live[] = $id;
				continue;
			}
			$gone[] = array( 'id' => $id, 'reason' => $reason );
		}

		return array( 'live' => $live, 'gone' => $gone );
	}

	/**
	 * @param array $children Reddit listing children.
	 * @return array[] Post data keyed by lowercase post id; only link ("t3") things count.
	 */
	private static function posts_by_id( array $children ): array {
		$posts = array();

		foreach ( $children as $child ) {
			$is_post = is_array( $child ) && ( $child['kind'] ?? '' ) === self::POST_KIND;
			$data    = $is_post ? ( $child['data'] ?? null ) : null;
			$id      = is_array( $data ) && is_string( $data['id'] ?? null ) ? strtolower( $data['id'] ) : '';
			if ( $id !== '' ) {
				$posts[ $id ] = $data;
			}
		}

		return $posts;
	}

	/**
	 * Why a post is no longer live, or '' when it is.
	 *
	 * Reddit marks a post deleted or removed in any of three places: removed_by_category is set,
	 * the author reads "[deleted]", or the body reads "[removed]" (or "[deleted]").
	 *
	 * @param array $data One post's data object.
	 */
	private static function gone_reason( array $data ): string {
		$removed_by = $data['removed_by_category'] ?? null;
		if ( $removed_by !== null && $removed_by !== false && $removed_by !== '' ) {
			$by_author = is_string( $removed_by ) && in_array( $removed_by, array( 'deleted', 'author' ), true );
			return $by_author ? 'deleted' : 'removed';
		}

		if ( self::text( $data, 'author' ) === '[deleted]' ) {
			return 'deleted';
		}

		$selftext = self::text( $data, 'selftext' );
		if ( $selftext === '[removed]' ) {
			return 'removed';
		}

		return $selftext === '[deleted]' ? 'deleted' : '';
	}

	private static function text( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function verdicts( array $live, array $gone, array $invalid ): array {
		return array(
			'status'  => 'ok',
			'live'    => $live,
			'gone'    => $gone,
			'invalid' => $invalid,
		);
	}

	/**
	 * Map a failed or blocked transport outcome to a response that says why nothing is known.
	 *
	 * @param array $outcome Transport outcome with status 'failed' or 'blocked' and a reason.
	 */
	private static function unavailable_from( array $outcome ): array {
		if ( $outcome['status'] === 'blocked' ) {
			return self::unavailable( 'blocked', 'unreachable', $outcome['reason'] );
		}

		$pause = Freshmints_Social_Reddit_Api::pause_remaining();
		if ( $pause > 0 ) {
			return self::unavailable( 'failed', 'rate_limited', $outcome['reason'], $pause );
		}

		return self::unavailable( 'failed', 'failed', $outcome['reason'] );
	}

	private static function unavailable( string $status, string $code, string $reason, int $retry_after = 0 ): array {
		$response = array(
			'status' => $status,
			'code'   => $code,
			'reason' => $reason,
		);
		if ( $retry_after > 0 ) {
			$response['retryAfterSeconds'] = $retry_after;
		}

		return $response;
	}
}
