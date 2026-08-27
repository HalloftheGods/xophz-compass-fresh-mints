<?php
/**
 * Plugin Name:       Xophz Fresh Mints
 * Description:       Turnkey lead discovery, license registry audit, skip-tracing, and practice website launcher platform integrated with Questbook CRM and WP Connectors API.
 * Version:           26.8.18
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-freshmints
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_FRESHMINTS_VERSION', '26.8.18' );
define( 'XOPHZ_COMPASS_FRESHMINTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_FRESHMINTS_URL', plugin_dir_url( __FILE__ ) );

// Autoload plugin classes
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'admin/class-xophz-compass-freshmints-admin.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'public/class-xophz-compass-freshmints-public.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/class-freshmints-api.php';

function run_xophz_compass_freshmints() {
	$admin = new Xophz_Compass_Freshmints_Admin( 'xophz-compass-freshmints', XOPHZ_COMPASS_FRESHMINTS_VERSION );
	add_action( 'admin_menu', array( $admin, 'add_plugin_admin_menu' ) );
	add_action( 'admin_init', array( $admin, 'register_settings' ) );
	add_action( 'update_option_xophz_compass_freshmints_load_mode', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );
	add_action( 'update_option_xophz_compass_freshmints_custom_slug', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );
	add_action( 'update_option_xophz_compass_freshmints_load_page_id', array( $admin, 'flush_rewrites_on_save' ), 10, 2 );

	$public = new Xophz_Compass_Freshmints_Public( 'xophz-compass-freshmints', XOPHZ_COMPASS_FRESHMINTS_VERSION );
	add_action( 'init', array( $public, 'register_endpoints' ) );
	add_filter( 'query_vars', array( $public, 'register_query_vars' ) );
	add_action( 'template_redirect', array( $public, 'template_redirect' ) );

	// REST API Controller
	add_action( 'rest_api_init', function() {
		$api = new Freshmints_API();
		$api->register_routes();
	} );

	// Register with WP Connectors API
	add_action( 'wp_connectors_init', function( $registry ) {
		if ( method_exists( $registry, 'register' ) ) {
			$registry->register(
				'yelp_api_key',
				array(
					'name'           => 'Yelp Fusion API Key',
					'description'    => 'API key for Yelp Fusion (used for non-medical leads).',
					'type'           => 'api_key',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => 'compass_yelp_api_key',
					),
				)
			);
			$registry->register(
				'google_places_api_key',
				array(
					'name'           => 'Google Places API Key',
					'description'    => 'API key for Google Places (used for website check and skip tracing).',
					'type'           => 'api_key',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => 'compass_google_places_api_key',
					),
				)
			);
		}
	} );
}

add_action( 'plugins_loaded', 'run_xophz_compass_freshmints' );

function xophz_compass_freshmints_activate() {
	$public = new Xophz_Compass_Freshmints_Public( 'xophz-compass-freshmints', XOPHZ_COMPASS_FRESHMINTS_VERSION );
	$public->register_endpoints();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_compass_freshmints_activate' );

function xophz_compass_freshmints_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'xophz_compass_freshmints_deactivate' );

function xophz_compass_freshmints_action_links( $links ) {
	$settings_link = '<a href="options-general.php?page=xophz-compass-freshmints">' . __( 'Settings', 'xophz-compass-freshmints' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_compass_freshmints_action_links' );
