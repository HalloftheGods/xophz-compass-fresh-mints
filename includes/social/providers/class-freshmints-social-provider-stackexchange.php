<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Provider_Stackexchange {

	const ENDPOINT          = 'https://api.stackexchange.com/2.3/search/advanced';
	const SITE              = 'money';
	const SITE_HOST         = 'money.stackexchange.com';
	const RESULT_LIMIT      = 15;
	const TIMEOUT_SECONDS   = 10;
	const MAX_KEYWORDS      = 20;
	const MAX_KEYWORD_LEN   = 100;
	const MAX_REQUESTS      = 3;
	const MAX_BACKOFF       = 600;
	const THROTTLE_PAUSE    = 60;
	const BACKOFF_TRANSIENT = 'xophz_compass_freshmints_stackexchange_backoff';

	public static function fetch_posts( array $rule ): array {
		$keywords = self::clean_keywords( $rule['keywords'] ?? array() );
		if ( empty( $keywords ) ) {
			return array( 'status' => 'ok', 'posts' => array() );
		}

		$pause = self::pause_remaining();
		if ( $pause > 0 ) {
			return self::failed( "Stack Exchange asked for a pause; try again in {$pause} seconds." );
		}

		$key   = Freshmints_Connectors::get_key( 'stackexchange_api_key' );
		$posts = array();
		foreach ( array_slice( $keywords, 0, self::MAX_REQUESTS ) as $keyword ) {
			$result = self::search( $keyword, $key );
			if ( $result['status'] !== 'ok' ) {
				return $result;
			}

			foreach ( $result['posts'] as $post ) {
				$posts[ $post['id'] ] = $post;
			}

			if ( $result['backoff'] > 0 ) {
				self::remember_pause( $result['backoff'] );
				break;
			}
		}

		return array( 'status' => 'ok', 'posts' => self::newest_first( $posts ) );
	}

	/**
	 * One question search. Returns a failed or blocked outcome, or an ok shape
	 * carrying the mapped posts and the API's requested backoff in seconds.
	 */
	private static function search( string $keyword, string $key ): array {
		$response = wp_remote_get( self::build_url( $keyword, $key ), array(
			'timeout'    => self::TIMEOUT_SECONDS,
			'decompress' => true,
			'headers'    => array(
				'Accept'     => 'application/json',
				'User-Agent' => self::user_agent(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'blocked',
				'reason' => 'Stack Exchange is unreachable from the server (' . $response->get_error_message() . ').',
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( self::inflate( wp_remote_retrieve_body( $response ) ), true );
		if ( $code !== 200 ) {
			return self::refused( $code, $payload );
		}

		$posts = self::parse_posts( $payload );
		if ( $posts === null ) {
			return self::failed( 'Stack Exchange returned a response that was not a valid question listing.' );
		}

		return array( 'status' => 'ok', 'posts' => $posts, 'backoff' => self::backoff_seconds( $payload ) );
	}

	private static function refused( int $code, $payload ): array {
		$name = self::error_name( $payload );
		if ( $name === 'throttle_violation' ) {
			self::remember_pause( self::THROTTLE_PAUSE );
		}

		$detail = $name !== '' ? ", {$name}" : '';
		return self::failed( "Stack Exchange refused the request (HTTP {$code}{$detail})." );
	}

	private static function failed( string $reason ): array {
		return array( 'status' => 'failed', 'reason' => $reason );
	}

	private static function user_agent(): string {
		return 'xophz-compass-fresh-mints/' . XOPHZ_COMPASS_FRESHMINTS_VERSION . ' (+' . home_url() . ')';
	}

	/**
	 * The API always gzips. WordPress inflates it; this only catches a body
	 * that still carries the gzip magic bytes.
	 */
	private static function inflate( string $body ): string {
		$is_gzip = strncmp( $body, "\x1f\x8b", 2 ) === 0;
		if ( ! $is_gzip || ! function_exists( 'gzdecode' ) ) {
			return $body;
		}

		$inflated = gzdecode( $body );
		return $inflated === false ? $body : $inflated;
	}

	private static function error_name( $payload ): string {
		$name = is_array( $payload ) ? ( $payload['error_name'] ?? '' ) : '';
		return is_string( $name ) && preg_match( '/^[a-z_]{1,64}$/', $name ) ? $name : '';
	}

	private static function backoff_seconds( $payload ): int {
		$backoff = is_array( $payload ) ? ( $payload['backoff'] ?? 0 ) : 0;
		return is_numeric( $backoff ) ? max( 0, min( self::MAX_BACKOFF, (int) $backoff ) ) : 0;
	}

	private static function remember_pause( int $seconds ): void {
		set_transient( self::BACKOFF_TRANSIENT, time() + $seconds, $seconds );
	}

	private static function pause_remaining(): int {
		$until = get_transient( self::BACKOFF_TRANSIENT );
		return is_numeric( $until ) ? max( 0, (int) $until - time() ) : 0;
	}

	private static function clean_keywords( $keywords ): array {
		if ( ! is_array( $keywords ) ) {
			return array();
		}

		$clean = array();
		foreach ( $keywords as $keyword ) {
			if ( ! is_string( $keyword ) ) {
				continue;
			}
			$keyword = trim( wp_strip_all_tags( $keyword ) );
			if ( $keyword === '' ) {
				continue;
			}
			$clean[] = mb_substr( $keyword, 0, self::MAX_KEYWORD_LEN );
			if ( count( $clean ) >= self::MAX_KEYWORDS ) {
				break;
			}
		}

		return $clean;
	}

	private static function build_url( string $keyword, string $key ): string {
		$params = array(
			'order'    => 'desc',
			'sort'     => 'creation',
			'q'        => $keyword,
			'site'     => self::SITE,
			'pagesize' => self::RESULT_LIMIT,
			'filter'   => 'withbody',
		);
		if ( $key !== '' ) {
			$params['key'] = $key;
		}

		return self::ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	private static function newest_first( array $posts ): array {
		$ordered = array_values( $posts );
		usort( $ordered, function ( array $a, array $b ): int {
			return strcmp( $b['timestamp'], $a['timestamp'] );
		} );

		return $ordered;
	}

	/**
	 * @param mixed $payload
	 * @return array[]|null
	 */
	private static function parse_posts( $payload ): ?array {
		$items = is_array( $payload ) ? ( $payload['items'] ?? null ) : null;
		$is_error_object = is_array( $payload ) && isset( $payload['error_id'] );
		if ( ! is_array( $items ) || $is_error_object ) {
			return null;
		}

		$posts = array();
		foreach ( $items as $item ) {
			$post = self::map_post( $item );
			if ( $post !== null ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	private static function map_post( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$question_id = $data['question_id'] ?? null;
		if ( ! is_numeric( $question_id ) || (int) $question_id <= 0 ) {
			return null;
		}
		$id = (string) (int) $question_id;

		$owner   = is_array( $data['owner'] ?? null ) ? $data['owner'] : array();
		$title   = self::decode( self::text( $data, 'title' ) );
		$body    = self::plain_text( self::text( $data, 'body' ) );
		$created = $data['creation_date'] ?? null;

		return array(
			'id'                 => 'se-' . $id,
			'platform'           => 'stack_exchange',
			'externalId'         => $id,
			'author'             => self::decode( self::text( $owner, 'display_name' ) ),
			'title'              => $title,
			'content'            => $body !== '' ? $body : $title,
			'url'                => self::question_url( $data ),
			'timestamp'          => is_numeric( $created ) ? gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $created ) : '',
			'score'              => is_numeric( $data['score'] ?? null ) ? (int) $data['score'] : 0,
			'commentsCount'      => is_numeric( $data['answer_count'] ?? null ) ? (int) $data['answer_count'] : 0,
			'subredditOrChannel' => self::SITE_HOST,
		);
	}

	private static function question_url( array $data ): string {
		$link  = self::text( $data, 'link' );
		$parts = wp_parse_url( $link );
		$parts = is_array( $parts ) ? $parts : array();

		$is_https = ( $parts['scheme'] ?? '' ) === 'https';
		$is_site  = ( $parts['host'] ?? '' ) === self::SITE_HOST;

		return $is_https && $is_site ? $link : '';
	}

	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Question bodies arrive as HTML. Block ends become newlines so paragraphs
	 * stay apart, tags are stripped first, then entities decoded, so escaped
	 * angle brackets in the text survive as literal characters.
	 */
	private static function plain_text( string $html ): string {
		$html = str_replace( "\r", '', $html );
		$html = preg_replace( '#</(p|li|ul|ol|h[1-6]|pre|blockquote|div|tr|table)>|<br\s*/?>#i', "\n", $html );
		$text = self::decode( wp_strip_all_tags( (string) $html ) );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	private static function text( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
