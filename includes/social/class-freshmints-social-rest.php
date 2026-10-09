<?php
/**
 * REST relay for the Social Radar feed.
 *
 * POST /wp-json/xophz-freshmints/v1/social/fetch
 *   body:     { platform: 'reddit', keywords: string[], targetChannels: string[] }
 *   response: the provider outcome, one of
 *             { status: 'ok', posts }, { status: 'blocked', reason }, { status: 'failed', reason }
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Social_Rest {

	const REST_NAMESPACE = 'xophz-freshmints/v1';

	public function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/social/fetch', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_fetch' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function handle_fetch( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$platform = isset( $body['platform'] ) && is_string( $body['platform'] ) ? $body['platform'] : '';

		if ( $platform !== 'reddit' ) {
			return rest_ensure_response( array(
				'status' => 'failed',
				'reason' => 'Unsupported platform for the server-side relay.',
			) );
		}

		return rest_ensure_response( Freshmints_Social_Provider_Reddit::fetch_posts( array(
			'keywords'       => $body['keywords'] ?? array(),
			'targetChannels' => $body['targetChannels'] ?? array(),
		) ) );
	}
}
