<?php

class Xophz_Compass_Freshmints_Public {

	private $plugin_name;
	private $version;
	protected $dev_proxy;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		if ( class_exists( 'Xophz_Compass_Dev_Proxy' ) ) {
			$this->dev_proxy = new Xophz_Compass_Dev_Proxy( array(
				'slug'                 => 'fresh-mints',
				'default_slug'         => 'fresh-mints',
				'dev_port'             => 8091,
				'query_var'            => 'xophz_compass_freshmints',
				'plugin_path'          => XOPHZ_COMPASS_FRESHMINTS_PATH,
				'plugin_url'           => XOPHZ_COMPASS_FRESHMINTS_URL,
				'version'              => $this->version,
				'candidate_dist_paths' => array(
					XOPHZ_COMPASS_FRESHMINTS_PATH . 'public/dist/index.html',
				),
				'extra_settings'       => array(
					'hasBombBag'   => true,
					'hasQuestbook' => true,
				),
			) );

			add_filter( 'xophz_compass_dev_proxy_fresh-mints_api_settings', array( $this, 'filter_api_settings' ), 10, 2 );
			add_filter( 'xophz_compass_dev_proxy_settings', array( $this, 'filter_api_settings' ), 10, 2 );
		}
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
			set_query_var( 'xophz_compass_freshmints', '1' );
			if ( $this->dev_proxy ) {
				$this->dev_proxy->handle_template_redirect();
			}
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
		if ( isset( $_GET['prod'] ) ) {
			return false;
		}
		if ( isset( $_GET['dev'] ) ) {
			return true;
		}
		return ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	public function filter_api_settings( array $payload, string $slug ): array {
		if ( 'fresh-mints' !== $slug && 'freshmints' !== $slug ) {
			return $payload;
		}

		global $wp_query;
		$isRouteMatch     = isset( $wp_query->query_vars['xophz_compass_freshmints'] );
		$previewSlugQuery = $wp_query->query_vars['fm_preview_slug'] ?? null;
		$subdomain        = $this->resolve_subdomain();
		$preview_slug     = $previewSlugQuery ?: $subdomain;

		$app_base = $this->resolve_app_base( $isRouteMatch );
		$app_base_slash = $app_base ? '/' . trim( $app_base, '/' ) . '/' : '/';

		$payload['appBase']      = $app_base_slash;
		$payload['previewSlug']  = $preview_slug ? sanitize_title( $preview_slug ) : '';
		$payload['hasBombBag']   = true;
		$payload['hasQuestbook'] = true;

		return $payload;
	}

	public function get_dev_proxy() {
		return $this->dev_proxy;
	}
}
