<?php
/**
 * Reddit provider for the Social Radar feed.
 *
 * Searches subreddits through Reddit's official Data API with app-only OAuth,
 * server-side, so the client secret and the token never reach the browser. The
 * token, the User-Agent and the rate limits live in Freshmints_Social_Reddit_Api.
 *
 * The provider is on when the reddit_client_id, reddit_client_secret and
 * reddit_username connectors are set, and answers "not configured" otherwise.
 * There is no fallback to the public www.reddit.com JSON.
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

	const DEFAULT_CHANNELS = 'smallbusiness+entrepreneur+webdev+sales';
	const RESULT_LIMIT     = 15;
	const MAX_KEYWORDS     = 20;
	const MAX_KEYWORD_LEN  = 100;
	const MAX_CHANNELS     = 10;

	/**
	 * @param array $rule { keywords: string[], targetChannels: string[] }
	 * @return array Outcome (see file header).
	 */
	public static function fetch_posts( array $rule ): array {
		$unconfigured = Freshmints_Social_Reddit_Api::configuration_error();
		if ( $unconfigured !== null ) {
			return $unconfigured;
		}

		$keywords = self::clean_keywords( $rule['keywords'] ?? array() );
		if ( empty( $keywords ) ) {
			return array( 'status' => 'ok', 'posts' => array() );
		}

		$channels = self::clean_channels( $rule['targetChannels'] ?? array() );
		$result   = Freshmints_Social_Reddit_Api::get(
			self::search_path( $channels ),
			self::search_query( $keywords )
		);
		if ( $result['status'] !== 'ok' ) {
			return $result;
		}

		$posts = self::parse_posts( $result['body'] );
		if ( $posts === null ) {
			return self::failed( 'Reddit returned a response that was not a valid search listing.' );
		}

		return array( 'status' => 'ok', 'posts' => $posts );
	}

	private static function failed( string $reason ): array {
		return array( 'status' => 'failed', 'reason' => $reason );
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
	 * @param string[] $channels Already cleaned; may be empty, in which case the defaults apply.
	 */
	private static function search_path( array $channels ): string {
		$joined = empty( $channels ) ? self::DEFAULT_CHANNELS : implode( '+', $channels );

		return '/r/' . $joined . '/search';
	}

	/**
	 * raw_json=1 asks Reddit not to HTML-escape &, < and > in titles and bodies.
	 *
	 * @param string[] $keywords Non-empty, already cleaned.
	 */
	private static function search_query( array $keywords ): array {
		return array(
			'q'           => implode( ' OR ', $keywords ),
			'sort'        => 'new',
			'restrict_sr' => 'on',
			'limit'       => self::RESULT_LIMIT,
			'raw_json'    => 1,
		);
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
