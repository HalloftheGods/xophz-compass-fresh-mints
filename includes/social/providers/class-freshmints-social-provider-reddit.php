<?php
/**
 * Reddit provider for the Social Radar feed.
 *
 * Fetches public Reddit search results server-side, because www.reddit.com
 * sends no CORS headers and the browser cannot call it directly.
 *
 * Reddit's free tier excludes commercial use, so this provider ships behind
 * the option `xophz_compass_freshmints_social_reddit_enabled` (default '0').
 *
 * Outcome shapes, exactly one of:
 *   array( 'status' => 'ok',      'posts'  => array )
 *   array( 'status' => 'blocked', 'reason' => string )
 *   array( 'status' => 'failed',  'reason' => string )
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Provider_Reddit {

	const ENABLED_OPTION   = 'xophz_compass_freshmints_social_reddit_enabled';
	const DEFAULT_CHANNELS = 'smallbusiness+entrepreneur+webdev+sales';
	const ENDPOINT_BASE    = 'https://www.reddit.com/r/';
	const RESULT_LIMIT     = 15;
	const TIMEOUT_SECONDS  = 10;
	const MAX_KEYWORDS     = 20;
	const MAX_KEYWORD_LEN  = 100;
	const MAX_CHANNELS     = 10;

	/**
	 * @param array $rule { keywords: string[], targetChannels: string[] }
	 * @return array Outcome (see file header).
	 */
	public static function fetch_posts( array $rule ): array {
		if ( ! self::is_enabled() ) {
			return self::failed( 'Reddit is disabled pending commercial API terms.' );
		}

		$keywords = self::clean_keywords( $rule['keywords'] ?? array() );
		if ( empty( $keywords ) ) {
			return array( 'status' => 'ok', 'posts' => array() );
		}

		$channels = self::clean_channels( $rule['targetChannels'] ?? array() );
		$url      = self::build_url( $keywords, $channels );

		$response = wp_remote_get( $url, array(
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => self::user_agent(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'blocked',
				'reason' => 'Reddit is unreachable from the server (' . $response->get_error_message() . ').',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return self::failed( "Reddit refused the request (HTTP {$code})." );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$posts   = self::parse_posts( $payload );
		if ( $posts === null ) {
			return self::failed( 'Reddit returned a response that was not a valid search listing.' );
		}

		return array( 'status' => 'ok', 'posts' => $posts );
	}

	private static function is_enabled(): bool {
		return (string) get_option( self::ENABLED_OPTION, '0' ) === '1';
	}

	private static function failed( string $reason ): array {
		return array( 'status' => 'failed', 'reason' => $reason );
	}

	private static function user_agent(): string {
		return 'xophz-compass-fresh-mints/' . XOPHZ_COMPASS_FRESHMINTS_VERSION . ' (+' . home_url() . ')';
	}

	/**
	 * Trim, drop empties and bound the keyword list.
	 *
	 * @param mixed $keywords
	 * @return string[]
	 */
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

	/**
	 * Accept channel names as separate entries or already joined with + or commas,
	 * strip a leading "r/", and keep only valid subreddit names.
	 *
	 * @param mixed $channels
	 * @return string[]
	 */
	private static function clean_channels( $channels ): array {
		$entries = is_array( $channels ) ? $channels : array();
		$names   = array();
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			foreach ( preg_split( '/[+,\s]+/', $entry, -1, PREG_SPLIT_NO_EMPTY ) as $name ) {
				$name = preg_replace( '#^/?r/#i', '', $name );
				if ( preg_match( '/^[A-Za-z0-9_]{2,21}$/', $name ) ) {
					$names[] = $name;
				}
			}
		}

		return array_slice( array_values( array_unique( $names ) ), 0, self::MAX_CHANNELS );
	}

	/**
	 * @param string[] $keywords Non-empty, already cleaned.
	 * @param string[] $channels May be empty, in which case the defaults apply.
	 */
	private static function build_url( array $keywords, array $channels ): string {
		$joined = empty( $channels ) ? self::DEFAULT_CHANNELS : implode( '+', $channels );
		$query  = rawurlencode( implode( ' OR ', $keywords ) );

		return self::ENDPOINT_BASE . $joined . '/search.json?q=' . $query
			. '&sort=new&restrict_sr=on&limit=' . self::RESULT_LIMIT;
	}

	/**
	 * Validate the listing and map each child to a RawSocialPost.
	 * Returns null when the payload is not a Reddit listing at all.
	 *
	 * @param mixed $payload
	 * @return array[]|null
	 */
	private static function parse_posts( $payload ): ?array {
		$children = is_array( $payload ) ? ( $payload['data']['children'] ?? null ) : null;
		if ( ! is_array( $children ) ) {
			return null;
		}

		$posts = array();
		foreach ( $children as $child ) {
			$post = self::map_post( is_array( $child ) ? ( $child['data'] ?? null ) : null );
			if ( $post !== null ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * @param mixed $data One child's "data" object.
	 * @return array|null The RawSocialPost, or null when the child has no usable id.
	 */
	private static function map_post( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$id = self::text( $data, 'id' );
		if ( $id === '' ) {
			return null;
		}

		$title     = self::text( $data, 'title' );
		$selftext  = self::text( $data, 'selftext' );
		$author    = self::text( $data, 'author' );
		$subreddit = self::text( $data, 'subreddit' );
		$permalink = self::permalink( $data );
		$created   = $data['created_utc'] ?? null;

		return array(
			'id'                 => 'reddit-' . $id,
			'platform'           => 'reddit',
			'externalId'         => $id,
			'author'             => $author !== '' ? 'u/' . $author : '',
			'title'              => $title,
			'content'            => $selftext !== '' ? $selftext : $title,
			'url'                => $permalink !== '' ? 'https://www.reddit.com' . $permalink : '',
			'timestamp'          => is_numeric( $created ) ? gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $created ) : '',
			'score'              => is_numeric( $data['score'] ?? null ) ? (int) $data['score'] : 0,
			'commentsCount'      => is_numeric( $data['num_comments'] ?? null ) ? (int) $data['num_comments'] : 0,
			'subredditOrChannel' => $subreddit !== '' ? 'r/' . $subreddit : '',
		);
	}

	/**
	 * Only a root-relative path is safe to append to the Reddit origin.
	 */
	private static function permalink( array $data ): string {
		$permalink = self::text( $data, 'permalink' );
		return strpos( $permalink, '/' ) === 0 ? $permalink : '';
	}

	/**
	 * Read a scalar field as a trimmed string; anything else is an empty string.
	 */
	private static function text( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
