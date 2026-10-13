<?php
/**
 * In-app sign-in for the Fresh Mints SPA: POST /auth/login, POST /auth/logout, GET /auth/me.
 * All three are public (__return_true); WordPress checks the credentials.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';

class Freshmints_Api_Auth extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/auth/login', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_login' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/auth/logout', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_logout' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/auth/me', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_me' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Authenticate User via In-App Login
	 */
	public function handle_login( $request ) {
		$params   = $request->get_json_params() ?: array();
		$username = sanitize_text_field( $params['username'] ?? '' );
		$password = trim( $params['password'] ?? '' );
		$remember = ! empty( $params['remember'] );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error( 'missing_credentials', 'Please enter your username/email and password.', array( 'status' => 400 ) );
		}

		// Support logging in with email or username
		if ( is_email( $username ) ) {
			$user_obj = get_user_by( 'email', $username );
			if ( $user_obj ) {
				$username = $user_obj->user_login;
			}
		}

		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'invalid_credentials', 'Invalid username or password. Please try again.', array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		$user_data = array(
			'id'           => 'wp-' . $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'fullName'     => $user->display_name ?: $user->user_login,
			'avatarUrl'    => get_avatar_url( $user->ID ) ?: '',
			'role'         => user_can( $user, 'manage_options' ) ? 'admin' : 'rep',
			'roles'        => (array) $user->roles,
			'registeredAt' => strtotime( $user->user_registered ) * 1000,
		);

		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => true,
			'user'       => $user_data,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Log Out Current User
	 */
	public function handle_logout() {
		wp_logout();
		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => false,
			'user'       => null,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Get Current Authenticated User & Nonce
	 */
	public function handle_get_me() {
		$is_logged_in = is_user_logged_in();
		$user_id      = get_current_user_id();
		$user_data    = null;

		if ( $is_logged_in && $user_id > 0 ) {
			$u = wp_get_current_user();
			$user_data = array(
				'id'           => 'wp-' . $user_id,
				'username'     => $u->user_login,
				'email'        => $u->user_email,
				'fullName'     => $u->display_name ?: $u->user_login,
				'avatarUrl'    => get_avatar_url( $user_id ) ?: '',
				'role'         => current_user_can( 'manage_options' ) ? 'admin' : 'rep',
				'roles'        => (array) $u->roles,
				'registeredAt' => strtotime( $u->user_registered ) * 1000,
			);
		}

		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => $is_logged_in,
			'user'       => $user_data,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}
}
