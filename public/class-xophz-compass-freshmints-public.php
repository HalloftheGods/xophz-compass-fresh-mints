<?php

class Xophz_Compass_Freshmints_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_endpoints() {
		$load_mode   = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
		$custom_slug = get_option( 'xophz_compass_freshmints_custom_slug', 'fresh-mints' );

		// Always register default route /fresh-mints
		add_rewrite_rule( '^fresh-mints(/.*)?$', 'index.php?xophz_compass_freshmints=1', 'top' );

		// Register clean direct preview route /preview/{slug}
		add_rewrite_rule( '^preview/([^/]+)/?$', 'index.php?xophz_compass_freshmints=1&fm_preview_slug=$matches[1]', 'top' );

		if ( $load_mode === 'custom_slug' && ! empty( $custom_slug ) && $custom_slug !== 'fresh-mints' ) {
			add_rewrite_rule( '^' . preg_quote( $custom_slug, '/' ) . '(/.*)?$', 'index.php?xophz_compass_freshmints=1', 'top' );
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'xophz_compass_freshmints';
		$vars[] = 'fm_preview_slug';
		return $vars;
	}

	/**
	 * Detect if current request is arriving from a custom tenant or practitioner subdomain.
	 */
	public function resolve_subdomain() {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		$host = preg_replace( '/:\d+$/', '', $host );
		$parts = explode( '.', $host );

		if ( count( $parts ) >= 2 ) {
			$sub = strtolower( $parts[0] );
			$reserved = array( 'www', 'localhost', 'mycompass', 'app', 'mail', 'preview', 'freshmints', 'compass', 'api', 'admin' );
			if ( ! in_array( $sub, $reserved, true ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return $sub;
			}
		}
		return null;
	}

	public function template_redirect() {
		global $wp_query;

		// Do not intercept WordPress admin or login routes
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( strpos( $request_uri, '/wp-admin' ) === 0 || strpos( $request_uri, '/wp-login.php' ) === 0 ) {
			return;
		}

		$isRouteMatch          = isset( $wp_query->query_vars['xophz_compass_freshmints'] );
		$previewSlugQuery      = $wp_query->query_vars['fm_preview_slug'] ?? null;
		$subdomain             = $this->resolve_subdomain();
		$isSubdomainMatch      = ! empty( $subdomain );
		$isConfiguredPageMatch = $this->is_configured_page();

		$load_mode             = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
		$isHomepage404Fallback = ( $load_mode === 'homepage' && is_404() );

		if ( $isRouteMatch || $isSubdomainMatch || $isConfiguredPageMatch || $isHomepage404Fallback ) {
			status_header( 200 );
			$wp_query->is_404 = false;

			$app_base = $this->resolve_app_base( $isRouteMatch );
			$resolved_preview_slug = $previewSlugQuery ?: $subdomain;
			$this->render_freshmints_shell( $app_base, $resolved_preview_slug );
			exit;
		}
	}

	private function is_configured_page() {
		$load_mode      = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
		$isHomepageMode = ( $load_mode === 'homepage' && is_front_page() );

		$targetPageId       = (int) get_option( 'xophz_compass_freshmints_load_page_id', 0 );
		$isSpecificPageMode = ( $load_mode === 'specific_page' && $targetPageId > 0 && is_page( $targetPageId ) );

		return $isHomepageMode || $isSpecificPageMode;
	}

	private function resolve_app_base( $isRouteMatch ) {
		if ( $isRouteMatch ) {
			$load_mode   = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
			$custom_slug = get_option( 'xophz_compass_freshmints_custom_slug', 'fresh-mints' );
			$requestPath = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?: '', '/' );

			if ( $load_mode === 'custom_slug' && ! empty( $custom_slug ) && strpos( $requestPath, $custom_slug ) === 0 ) {
				return $custom_slug;
			}
			return 'fresh-mints';
		}

		$load_mode = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
		if ( $load_mode === 'homepage' ) {
			return '';
		}

		return trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?: '', '/' );
	}

	private function is_dev_mode() {
		return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	private function build_user_data() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$u = wp_get_current_user();
		return array(
			'id'           => 'wp-' . $user_id,
			'username'     => $u->user_login,
			'email'        => $u->user_email,
			'fullName'     => $u->display_name ?: $u->user_login,
			'avatarUrl'    => get_avatar_url( $user_id ) ?: '',
			'role'         => in_array( 'administrator', (array) $u->roles, true ) ? 'admin' : 'user',
			'registeredAt' => strtotime( $u->user_registered ) * 1000,
		);
	}

	private function render_freshmints_shell( $app_base, $preview_slug = null ) {
		$is_dev          = $this->is_dev_mode();
		$wp_host         = wp_parse_url( home_url(), PHP_URL_HOST );
		$vite_port       = '8091';
		$vite_url        = '//' . $wp_host . ':' . $vite_port;
		$app_base_slash  = $app_base ? '/' . trim( $app_base, '/' ) . '/' : '/';
		$nonce           = wp_create_nonce( 'wp_rest' );
		$user_id         = get_current_user_id();
		$user_data       = $this->build_user_data();
		$clean_preview_slug = $preview_slug ? sanitize_title( $preview_slug ) : '';

		$wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw( rest_url() ) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw( XOPHZ_COMPASS_FRESHMINTS_URL ) . "', version: '" . esc_js( $this->version ) . "', userId: " . $user_id . ", currentUser: " . wp_json_encode( $user_data ) . ", appBase: '" . esc_js( $app_base_slash ) . "', previewSlug: '" . esc_js( $clean_preview_slug ) . "', hasBombBag: true, hasQuestbook: true };</script>";

		if ( $is_dev ) {
			$dev_hosts = array( 'compass', '127.0.0.1', 'localhost' );
			$dev_html  = false;
			foreach ( $dev_hosts as $host ) {
				$context  = stream_context_create( array( 'http' => array( 'timeout' => 1 ) ) );
				$dev_html = @file_get_contents( "http://{$host}:{$vite_port}/", false, $context );
				if ( $dev_html ) {
					break;
				}
			}

			if ( $dev_html ) {
				// Rewrite relative src/href/import for dev server
				$dev_html = str_replace( 'src="/', 'src="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'href="/', 'href="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'import("/', 'import("' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( 'from "/', 'from="' . $vite_url . '/', $dev_html );
				$dev_html = str_replace( "from '/", "from '" . $vite_url . "/", $dev_html );

				// Inject Vite client if not present
				if ( strpos( $dev_html, '/@vite/client' ) === false ) {
					$vite_client = '<script type="module" src="' . esc_url( $vite_url ) . '/@vite/client"></script>';
					$dev_html    = str_replace( '</head>', $vite_client . "\n</head>", $dev_html );
				}

				$dev_html = str_replace( '</head>', $wp_api_settings . "\n</head>", $dev_html );

				echo $dev_html;
				exit;
			}
		}

		// Production Mode: Load static build output
		$index_file = XOPHZ_COMPASS_FRESHMINTS_PATH . 'public/dist/index.html';

		if ( file_exists( $index_file ) ) {
			$html     = file_get_contents( $index_file );
			$dist_url = XOPHZ_COMPASS_FRESHMINTS_URL . 'public/dist/';

			// Rewrite assets paths
			$html = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $html );
			$html = str_replace( "'/assets/", "'" . $dist_url . "assets/", $html );
			$html = str_replace( '"/vite.svg"', '"' . $dist_url . 'vite.svg"', $html );

			// Inject WP API Settings
			$html = str_replace( '</head>', $wp_api_settings . "\n</head>", $html );

			echo $html;
			exit;
		} else {
			echo '<h2>Fresh Mints build output not found.</h2><p>Please run <code>pnpm build:freshmints</code> in the COMPASS root directory.</p>';
			exit;
		}
	}
}
