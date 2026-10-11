<?php
/**
 * Server-side relay for the Social Radar dispatch webhook (Slack, Discord, Zapier).
 *
 * A webhook URL is a bearer credential: anyone holding it can post to the channel. It is
 * resolved here through the WordPress Connectors API and never sent to, or stored in, the
 * browser. The browser only says "dispatch this reply"; this class makes the one POST.
 *
 * POST /wp-json/xophz-freshmints/v1/social/dispatch
 *   body:     { platform, threadUrl, prospect, intentScore, matchedKeyword, replyBody }
 *   success:  { success: true, message, status }
 *   errors:   freshmints_webhook_not_configured (400), freshmints_dispatch_invalid (400),
 *             freshmints_webhook_unreachable (502), freshmints_webhook_rejected (502)
 *
 * Error responses carry only the connector id, an error code and the webhook's HTTP status.
 * They never carry the webhook URL or the text of a transport error.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Dispatch_Rest {

	const REST_NAMESPACE = 'xophz-freshmints/v1';
	const CONNECTOR_ID   = 'social_dispatch_webhook_url';
	const EVENT          = 'social_lead_reply_dispatched';
	const TIMEOUT        = 15;
	const MAX_FIELD_LEN  = 2000;
	const MAX_REPLY_LEN  = 10000;

	public function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/social/dispatch', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_dispatch' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function handle_dispatch( WP_REST_Request $request ) {
		$webhook_url = Freshmints_Connectors::get_key( self::CONNECTOR_ID );
		if ( $webhook_url === '' ) {
			return new WP_Error(
				'freshmints_webhook_not_configured',
				'No dispatch webhook is configured. Add the Social Dispatch Webhook URL under Settings > Connectors.',
				array( 'status' => 400, 'connector' => self::CONNECTOR_ID )
			);
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$payload = $this->build_payload( $body );
		if ( $payload === null ) {
			return new WP_Error(
				'freshmints_dispatch_invalid',
				'A dispatch needs a reply body.',
				array( 'status' => 400 )
			);
		}

		// wp_safe_remote_post refuses loopback and private-network hosts, so the stored URL
		// cannot be pointed at an internal service.
		$response = wp_safe_remote_post( $webhook_url, array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'freshmints_webhook_unreachable',
				'The dispatch webhook could not be reached.',
				array( 'status' => 502, 'transport' => $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'freshmints_webhook_rejected',
				"The dispatch webhook responded with HTTP {$status}.",
				array( 'status' => 502, 'webhook_status' => $status )
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Pitch dispatched to connected funnel webhook (Slack/Discord/Zapier).',
			'status'  => $status,
		) );
	}

	/**
	 * The event the webhook receives, built from validated fields only.
	 *
	 * @return array|null null when there is no reply body to send
	 */
	private function build_payload( array $body ) {
		$reply = $this->text( $body['replyBody'] ?? '', self::MAX_REPLY_LEN );
		if ( $reply === '' ) {
			return null;
		}

		$score = $body['intentScore'] ?? 0;

		return array(
			'event'          => self::EVENT,
			'platform'       => $this->text( $body['platform'] ?? '', 64 ),
			'threadUrl'      => esc_url_raw( $this->text( $body['threadUrl'] ?? '', self::MAX_FIELD_LEN ) ),
			'prospect'       => $this->text( $body['prospect'] ?? '', 200 ),
			'intentScore'    => is_numeric( $score ) ? (float) $score : 0,
			'matchedKeyword' => $this->text( $body['matchedKeyword'] ?? '', 200 ),
			'replyBody'      => $reply,
			'timestamp'      => gmdate( 'c' ),
		);
	}

	private function text( $value, int $max_length ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return mb_substr( trim( $value ), 0, $max_length );
	}
}
