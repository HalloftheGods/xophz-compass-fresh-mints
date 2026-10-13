<?php
/**
 * Reddit Data API access for the Social Radar Reddit provider.
 *
 * App-only OAuth (grant_type=client_credentials): the server trades the app's
 * client id and secret for a bearer token, caches it until shortly before it
 * expires, and calls https://oauth.reddit.com with it. There is no fallback to
 * the public www.reddit.com JSON: without credentials the answer is "not configured".
 *
 * Reddit's rules this class follows (github.com/reddit-archive/reddit/wiki/API and
 * /OAuth2): every request carries a descriptive User-Agent in the form
 * "<platform>:<app ID>:<version> (by /u/<username>)", and the X-Ratelimit-Used,
 * X-Ratelimit-Remaining and X-Ratelimit-Reset headers describe the quota window.
 *
 * The client secret and the token exist only in memory, in the token transient and
 * in the Authorization header of outbound calls. Neither is ever put in a reason.
 *
 * Outcome shapes, exactly one of:
 *   array( 'status' => 'ok',      'body'   => array )
 *   array( 'status' => 'blocked', 'reason' => string )
 *   array( 'status' => 'failed',  'reason' => string )
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Reddit_Api {

	const TOKEN_ENDPOINT  = 'https://www.reddit.com/api/v1/access_token';
	const API_BASE        = 'https://oauth.reddit.com';
	const TOKEN_TRANSIENT = 'xophz_compass_freshmints_reddit_token';
	const PAUSE_TRANSIENT = 'xophz_compass_freshmints_reddit_pause';
	const TIMEOUT_SECONDS = 10;
	const TOKEN_SKEW      = 120;
	const MIN_TOKEN_TTL   = 30;
	const DEFAULT_PAUSE   = 60;
	const MAX_PAUSE       = 600;
	const LOW_REMAINING   = 2;
	const APP_ID          = 'TopicRadar';

	/**
	 * The failed outcome to return when the app is not set up, or null when it is.
	 */
	public static function configuration_error(): ?array {
		if ( self::credentials() !== null ) {
			return null;
		}

		return self::failed( 'Reddit is not configured.' );
	}

	/**
	 * GET one Data API listing with the app-only bearer token.
	 *
	 * @param string $path  Root-relative API path, e.g. /r/webdev/search.
	 * @param array  $query Query parameters.
	 * @return array Outcome (see file header).
	 */
	public static function get( string $path, array $query ): array {
		$credentials = self::credentials();
		if ( $credentials === null ) {
			return self::failed( 'Reddit is not configured.' );
		}

		$pause = self::pause_remaining();
		if ( $pause > 0 ) {
			return self::failed( "Reddit asked for a pause; try again in {$pause} seconds." );
		}

		return self::authorized_get( $credentials, self::url( $path, $query ), true );
	}

	/**
	 * One call with the bearer token. A 401 means the token was revoked or expired
	 * early, so the cached token is dropped and the call repeats once with a new one.
	 */
	private static function authorized_get( array $credentials, string $url, bool $may_renew ): array {
		$token = self::token( $credentials );
		if ( $token['status'] !== 'ok' ) {
			return $token;
		}

		$response = wp_remote_get( $url, array(
			'timeout'     => self::TIMEOUT_SECONDS,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'bearer ' . $token['token'],
				'Accept'        => 'application/json',
				'User-Agent'    => self::user_agent( $credentials['username'] ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return self::unreachable( $response );
		}

		self::note_rate_limit( $response );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 401 && $may_renew ) {
			self::forget_token();
			return self::authorized_get( $credentials, $url, false );
		}
		if ( $code === 429 ) {
			return self::throttled( $response );
		}
		if ( $code !== 200 ) {
			return self::failed( "Reddit refused the request (HTTP {$code})." );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return self::failed( 'Reddit returned a response that was not valid JSON.' );
		}

		return array( 'status' => 'ok', 'body' => $body );
	}

	/**
	 * @return array|null The id, secret and username, or null when any is missing or malformed.
	 */
	private static function credentials(): ?array {
		$id       = Freshmints_Connectors::get_key( 'reddit_client_id' );
		$secret   = Freshmints_Connectors::get_key( 'reddit_client_secret' );
		$username = self::clean_username( Freshmints_Connectors::get_key( 'reddit_username' ) );

		$id_ok = preg_match( '/^[A-Za-z0-9_-]+$/', $id ) === 1;
		if ( ! $id_ok || $secret === '' || $username === '' ) {
			return null;
		}

		return array( 'id' => $id, 'secret' => $secret, 'username' => $username );
	}

	/**
	 * Reddit usernames are 3 to 20 letters, digits, underscores or hyphens.
	 * A pasted "u/name" or "/u/name" is accepted.
	 */
	private static function clean_username( string $username ): string {
		$username = preg_replace( '#^/?u/#i', '', $username );
		return preg_match( '/^[A-Za-z0-9_-]{3,20}$/', $username ) === 1 ? $username : '';
	}

	/**
	 * The app is the shared Topic Radar (Fresh Mints and YouMeOS use one Reddit app),
	 * so the app id is "TopicRadar" rather than a plugin name.
	 */
	private static function user_agent( string $username ): string {
		return 'wordpress:' . self::APP_ID . ':v' . XOPHZ_COMPASS_FRESHMINTS_VERSION . ' (by /u/' . $username . ')';
	}

	private static function url( string $path, array $query ): string {
		return self::API_BASE . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * The cached bearer token, or a fresh one from the token endpoint.
	 *
	 * @return array ok shape carrying 'token', or a blocked or failed outcome.
	 */
	private static function token( array $credentials ): array {
		$cached = self::cached_token( $credentials['id'] );
		if ( $cached !== '' ) {
			return array( 'status' => 'ok', 'token' => $cached );
		}

		return self::request_token( $credentials );
	}

	private static function request_token( array $credentials ): array {
		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'timeout'     => self::TIMEOUT_SECONDS,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $credentials['id'] . ':' . $credentials['secret'] ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'User-Agent'    => self::user_agent( $credentials['username'] ),
			),
			'body'        => array( 'grant_type' => 'client_credentials' ),
		) );

		if ( is_wp_error( $response ) ) {
			return self::unreachable( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 429 ) {
			return self::throttled( $response );
		}
		if ( $code === 401 || $code === 403 ) {
			return self::failed( "Reddit rejected the configured client id and secret (HTTP {$code})." );
		}
		if ( $code !== 200 ) {
			return self::failed( "Reddit refused the token request (HTTP {$code})." );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$token   = is_array( $payload ) && is_string( $payload['access_token'] ?? null ) ? trim( $payload['access_token'] ) : '';
		if ( $token === '' ) {
			$name   = self::error_name( $payload );
			$detail = $name !== '' ? " ({$name})" : '';
			return self::failed( "Reddit did not issue an access token{$detail}." );
		}

		self::remember_token( $credentials['id'], $token, $payload['expires_in'] ?? 0 );

		return array( 'status' => 'ok', 'token' => $token );
	}

	/**
	 * The transient is tied to the client id, so replacing the app's credentials
	 * never reuses a token that belongs to the old app.
	 */
	private static function cached_token( string $client_id ): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( ! is_array( $cached ) || ! is_string( $cached['token'] ?? null ) || ! is_string( $cached['client'] ?? null ) ) {
			return '';
		}

		return hash_equals( self::client_fingerprint( $client_id ), $cached['client'] ) ? $cached['token'] : '';
	}

	/**
	 * Cache until TOKEN_SKEW seconds before expiry. A token with less life than
	 * that serves this call only.
	 *
	 * @param mixed $expires_in Seconds of life, as Reddit reported them.
	 */
	private static function remember_token( string $client_id, string $token, $expires_in ): void {
		$ttl = is_numeric( $expires_in ) ? (int) $expires_in - self::TOKEN_SKEW : 0;
		if ( $ttl < self::MIN_TOKEN_TTL ) {
			return;
		}

		set_transient( self::TOKEN_TRANSIENT, array(
			'client' => self::client_fingerprint( $client_id ),
			'token'  => $token,
		), $ttl );
	}

	private static function forget_token(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	private static function client_fingerprint( string $client_id ): string {
		return hash( 'sha256', $client_id );
	}

	/**
	 * Park the provider when the quota window is nearly spent.
	 */
	private static function note_rate_limit( $response ): void {
		$remaining = self::header_number( $response, 'x-ratelimit-remaining' );
		$reset     = self::header_number( $response, 'x-ratelimit-reset' );
		if ( $remaining === null || $reset === null || $remaining >= self::LOW_REMAINING ) {
			return;
		}

		self::remember_pause( (int) ceil( $reset ) );
	}

	/**
	 * A 429 pauses the provider for as long as Reddit asks.
	 */
	private static function throttled( $response ): array {
		$seconds = self::header_number( $response, 'retry-after' );
		if ( $seconds === null ) {
			$seconds = self::header_number( $response, 'x-ratelimit-reset' );
		}

		$pause = $seconds === null ? self::DEFAULT_PAUSE : (int) ceil( $seconds );
		self::remember_pause( $pause );

		return self::failed( 'Reddit rate limit reached; try again in ' . self::pause_remaining() . ' seconds.' );
	}

	/**
	 * @return float|null The header as a non-negative number, or null when absent or not numeric.
	 */
	private static function header_number( $response, string $name ): ?float {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_string( $value ) && is_numeric( $value ) && (float) $value >= 0 ? (float) $value : null;
	}

	private static function remember_pause( int $seconds ): void {
		$seconds = max( 1, min( self::MAX_PAUSE, $seconds ) );
		set_transient( self::PAUSE_TRANSIENT, time() + $seconds, $seconds );
	}

	private static function pause_remaining(): int {
		$until = get_transient( self::PAUSE_TRANSIENT );
		return is_numeric( $until ) ? max( 0, (int) $until - time() ) : 0;
	}

	/**
	 * @param mixed $payload
	 */
	private static function error_name( $payload ): string {
		$name = is_array( $payload ) ? ( $payload['error'] ?? '' ) : '';
		return is_string( $name ) && preg_match( '/^[a-z_]{1,64}$/', $name ) === 1 ? $name : '';
	}

	private static function unreachable( WP_Error $error ): array {
		return array(
			'status' => 'blocked',
			'reason' => 'Reddit is unreachable from the server (' . $error->get_error_message() . ').',
		);
	}

	private static function failed( string $reason ): array {
		return array( 'status' => 'failed', 'reason' => $reason );
	}
}
