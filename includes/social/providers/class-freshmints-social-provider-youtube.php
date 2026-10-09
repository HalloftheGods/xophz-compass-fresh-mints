<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * YouTube comments as a Social Radar source.
 *
 * Quota budget per scan: ONE search.list (100 units) plus one commentThreads
 * call (1 unit) for each of at most MAX_VIDEOS videos. The API key travels in
 * the X-Goog-Api-Key header, never in a URL, and never appears in a reason.
 */
class Freshmints_Social_Provider_Youtube {

	const SEARCH_ENDPOINT    = 'https://www.googleapis.com/youtube/v3/search';
	const COMMENTS_ENDPOINT  = 'https://www.googleapis.com/youtube/v3/commentThreads';
	const WATCH_URL          = 'https://www.youtube.com/watch';
	const MAX_VIDEOS         = 5;
	const COMMENTS_PER_VIDEO = 20;
	const TIMEOUT_SECONDS    = 10;
	const MAX_KEYWORDS       = 20;
	const MAX_KEYWORD_LEN    = 100;
	const SKIPPABLE_CAUSES   = array( 'commentsDisabled', 'videoNotFound' );

	public static function fetch_posts( array $rule ): array {
		$api_key = Freshmints_Social_Credentials::get( 'youtube_api_key' );
		if ( $api_key === '' ) {
			return self::failed( 'YouTube is not configured.' );
		}

		$keywords = self::clean_keywords( $rule['keywords'] ?? array() );
		if ( empty( $keywords ) ) {
			return array( 'status' => 'ok', 'posts' => array() );
		}

		list( $videos, $search_error ) = self::search_videos( $keywords, $api_key );
		if ( $search_error !== null ) {
			return self::outcome( $search_error );
		}

		return self::collect_comments( $videos, $api_key );
	}

	/**
	 * Comments for each video. A video that cannot show comments is skipped.
	 * Any other failure ends the loop, since quota and transport errors repeat
	 * for every remaining video. Partial posts still return as ok; a scan that
	 * found nothing and hit a real failure reports that failure.
	 */
	private static function collect_comments( array $videos, string $api_key ): array {
		$posts   = array();
		$failure = null;
		foreach ( array_slice( $videos, 0, self::MAX_VIDEOS ) as $video ) {
			list( $comments, $error ) = self::fetch_comments( $video, $api_key );
			if ( $error === null ) {
				$posts = array_merge( $posts, $comments );
				continue;
			}
			if ( in_array( $error['cause'], self::SKIPPABLE_CAUSES, true ) ) {
				continue;
			}
			$failure = $error;
			break;
		}

		if ( empty( $posts ) && $failure !== null ) {
			return self::outcome( $failure );
		}

		return array( 'status' => 'ok', 'posts' => $posts );
	}

	/**
	 * @return array{0: array[], 1: ?array} videos and an error, one of them empty
	 */
	private static function search_videos( array $keywords, string $api_key ): array {
		list( $payload, $error ) = self::get( self::SEARCH_ENDPOINT, array(
			'part'              => 'snippet',
			'type'              => 'video',
			'q'                 => implode( '|', $keywords ),
			'order'             => 'date',
			'maxResults'        => self::MAX_VIDEOS,
			'relevanceLanguage' => 'en',
		), $api_key, 'search' );
		if ( $error !== null ) {
			return array( array(), $error );
		}

		$videos = self::parse_videos( $payload );
		if ( $videos === null ) {
			return array( array(), self::error( 'failed', 'YouTube returned a response that was not a valid search listing.' ) );
		}

		return array( $videos, null );
	}

	/**
	 * @return array{0: array[], 1: ?array} posts and an error, one of them empty
	 */
	private static function fetch_comments( array $video, string $api_key ): array {
		list( $payload, $error ) = self::get( self::COMMENTS_ENDPOINT, array(
			'part'       => 'snippet',
			'videoId'    => $video['id'],
			'maxResults' => self::COMMENTS_PER_VIDEO,
			'order'      => 'time',
			'textFormat' => 'plainText',
		), $api_key, 'comment request' );
		if ( $error !== null ) {
			return array( array(), $error );
		}

		$items = is_array( $payload ) ? ( $payload['items'] ?? null ) : null;
		if ( ! is_array( $items ) ) {
			return array( array(), self::error( 'failed', 'YouTube returned a response that was not a valid comment listing.' ) );
		}

		$posts = array();
		foreach ( $items as $thread ) {
			$post = self::map_comment( $thread, $video );
			if ( $post !== null ) {
				$posts[] = $post;
			}
		}

		return array( $posts, null );
	}

	/**
	 * One authenticated GET. The key rides in a header, so neither a URL nor a
	 * transport error message can carry it.
	 *
	 * @return array{0: mixed, 1: ?array} decoded payload and an error, one of them empty
	 */
	private static function get( string $endpoint, array $params, string $api_key, string $what ): array {
		$url      = $endpoint . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_get( $url, array(
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Accept'         => 'application/json',
				'User-Agent'     => self::user_agent(),
				'X-Goog-Api-Key' => $api_key,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( null, self::error(
				'blocked',
				'YouTube is unreachable from the server (' . $response->get_error_message() . ').'
			) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) {
			$cause  = self::google_reason( $payload );
			$detail = $cause !== '' ? ", {$cause}" : '';
			return array( null, self::error( 'failed', "YouTube refused the {$what} (HTTP {$code}{$detail}).", $cause ) );
		}

		return array( $payload, null );
	}

	private static function error( string $status, string $reason, string $cause = '' ): array {
		return array( 'status' => $status, 'reason' => $reason, 'cause' => $cause );
	}

	private static function outcome( array $error ): array {
		return array( 'status' => $error['status'], 'reason' => $error['reason'] );
	}

	private static function failed( string $reason ): array {
		return array( 'status' => 'failed', 'reason' => $reason );
	}

	private static function user_agent(): string {
		return 'xophz-compass-fresh-mints/' . XOPHZ_COMPASS_FRESHMINTS_VERSION . ' (+' . home_url() . ')';
	}

	/**
	 * Google's machine-readable reason, such as quotaExceeded, restricted to a
	 * safe character set so nothing free-form is ever echoed.
	 */
	private static function google_reason( $payload ): string {
		$errors = self::section( self::section( $payload, 'error' ), 'errors' );
		$reason = self::text( self::section( $errors, '0' ), 'reason' );

		return preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $reason ) ? $reason : '';
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
			$keyword = trim( str_replace( '|', ' ', wp_strip_all_tags( $keyword ) ) );
			$keyword = trim( ltrim( $keyword, '-' ) );
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
	 * @param mixed $payload
	 * @return array[]|null
	 */
	private static function parse_videos( $payload ): ?array {
		$items = is_array( $payload ) ? ( $payload['items'] ?? null ) : null;
		if ( ! is_array( $items ) ) {
			return null;
		}

		$videos = array();
		foreach ( $items as $item ) {
			$video_id = self::text( self::section( $item, 'id' ), 'videoId' );
			if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $video_id ) ) {
				continue;
			}
			$snippet  = self::section( $item, 'snippet' );
			$videos[] = array(
				'id'      => $video_id,
				'title'   => self::decode( self::text( $snippet, 'title' ) ),
				'channel' => self::decode( self::text( $snippet, 'channelTitle' ) ),
			);
		}

		return array_slice( $videos, 0, self::MAX_VIDEOS );
	}

	private static function map_comment( $thread, array $video ): ?array {
		$comment = self::section( self::section( $thread, 'snippet' ), 'topLevelComment' );
		$snippet = self::section( $comment, 'snippet' );

		$comment_id = self::text( $comment, 'id' );
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,128}$/', $comment_id ) ) {
			return null;
		}

		$content = self::text( $snippet, 'textOriginal' );
		$content = $content !== '' ? $content : self::text( $snippet, 'textDisplay' );
		if ( $content === '' ) {
			return null;
		}

		$published = self::text( $snippet, 'publishedAt' );
		$replies   = self::section( $thread, 'snippet' )['totalReplyCount'] ?? null;

		return array(
			'id'                 => 'yt-' . $comment_id,
			'platform'           => 'youtube',
			'externalId'         => $comment_id,
			'author'             => self::text( $snippet, 'authorDisplayName' ),
			'title'              => $video['title'],
			'content'            => $content,
			'url'                => self::WATCH_URL . '?v=' . rawurlencode( $video['id'] ) . '&lc=' . rawurlencode( $comment_id ),
			'timestamp'          => strtotime( $published ) !== false ? $published : '',
			'score'              => is_numeric( $snippet['likeCount'] ?? null ) ? (int) $snippet['likeCount'] : 0,
			'commentsCount'      => is_numeric( $replies ) ? (int) $replies : 0,
			'subredditOrChannel' => $video['channel'],
		);
	}

	private static function section( $data, string $key ): array {
		return is_array( $data ) && is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();
	}

	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function text( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
