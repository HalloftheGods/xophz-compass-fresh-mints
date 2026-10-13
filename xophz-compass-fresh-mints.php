<?php
/**
 * Plugin Name:       Xophz Fresh Mints
 * Plugin URI:        https://freshmints.worldwidewebwork.com
 * Description:       Turnkey lead discovery, license registry audit, skip-tracing, and practice website launcher platform integrated with Questbook CRM and WP Connectors API.
 * Version:           26.10.13-268
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-fresh-mints
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_FRESHMINTS_VERSION', '26.10.13-268' );
define( 'XOPHZ_COMPASS_FRESHMINTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_FRESHMINTS_URL', plugin_dir_url( __FILE__ ) );

// Autoload plugin classes
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'admin/class-xophz-compass-freshmints-admin.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'public/class-xophz-compass-freshmints-public.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/class-freshmints-connectors.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/class-freshmints-api.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/registry/class-freshmints-registry-producers.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/registry/class-freshmints-agent-finder-rest.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/providers/class-freshmints-social-reddit-api.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/providers/class-freshmints-social-provider-reddit.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/providers/class-freshmints-social-provider-stackexchange.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/providers/class-freshmints-social-provider-youtube.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/class-freshmints-social-rest.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/class-freshmints-social-retention.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/social/class-freshmints-social-dispatch-rest.php';
require_once XOPHZ_COMPASS_FRESHMINTS_PATH . 'includes/crm/class-freshmints-crm-webhook-rest.php';

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
	add_action( 'template_redirect', array( $public, 'template_redirect' ), 5 );

	// Agent Finder weekly sync: tables and schedule on init, run on the weekly hook
	( new Freshmints_Agent_Finder_Sync() )->register();

	// REST API Controller
	add_action( 'rest_api_init', function() {
		$api = new Freshmints_API();
		$api->register_routes();

		$social = new Freshmints_Social_Rest();
		$social->register_routes();

		$retention = new Freshmints_Social_Retention();
		$retention->register_routes();

		$dispatch = new Freshmints_Social_Dispatch_Rest();
		$dispatch->register_routes();

		$crm_webhook = new Freshmints_Crm_Webhook_Rest();
		$crm_webhook->register_routes();

		$agent_finder = new Freshmints_Agent_Finder_Rest();
		$agent_finder->register_routes();
	} );

	// Register with Event Horizon / YouMeOS Spark Registry
	add_filter( 'xophz_register_sparks', function( $sparks ) {
		$sparks['fresh-mints'] = array(
			'id'          => 'fresh-mints',
			'title'       => 'Fresh Mints',
			'description' => 'Practice launch & skip-trace intelligence platform',
			'icon'        => 'fal fa-sparkles',
			'color'       => '#10b981',
			'url'         => '/fresh-mints',
			'category'    => 'productivity',
			'type'        => 'webspark',
			'status'      => 'pi',
			'weight'      => 100,
			'active'      => true,
			'version'     => XOPHZ_COMPASS_FRESHMINTS_VERSION,
		);
		return $sparks;
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
			if ( ! wp_is_connector_registered( 'youtube_api_key' ) ) {
				$registry->register(
					'youtube_api_key',
					array(
						'name'           => 'YouTube Data API Key',
						'description'    => 'API key for the YouTube Data API (powers YouTube comment search in Social Radar).',
						'type'           => 'api_key',
						'authentication' => array(
							'method'          => 'api_key',
							'setting_name'    => 'compass_youtube_api_key',
							'constant_name'   => 'COMPASS_YOUTUBE_API_KEY',
							'env_var_name'    => 'COMPASS_YOUTUBE_API_KEY',
							'credentials_url' => 'https://console.cloud.google.com/apis/credentials',
						),
					)
				);
			}
			if ( ! wp_is_connector_registered( 'stackexchange_api_key' ) ) {
				$registry->register(
					'stackexchange_api_key',
					array(
						'name'           => 'Stack Exchange API Key',
						'description'    => 'Optional API key for Stack Exchange (raises the Social Radar quota from 300 to 10,000 requests a day).',
						'type'           => 'api_key',
						'authentication' => array(
							'method'          => 'api_key',
							'setting_name'    => 'compass_stackexchange_api_key',
							'constant_name'   => 'COMPASS_STACKEXCHANGE_API_KEY',
							'env_var_name'    => 'COMPASS_STACKEXCHANGE_API_KEY',
							'credentials_url' => 'https://stackapps.com/apps/oauth/register',
						),
					)
				);
			}
			// The Reddit app is shared (Topic Radar in Fresh Mints and YouMeOS), so each connector
			// registers only when nobody else has, and core or another plugin may own it later.
			if ( ! wp_is_connector_registered( 'reddit_client_id' ) ) {
				$registry->register(
					'reddit_client_id',
					array(
						'name'           => 'Reddit Client ID',
						'description'    => 'Client ID of the Reddit "script" or web app that powers Reddit search in Social Radar and Topic Radar (the short string under the app name).',
						'type'           => 'api_key',
						'authentication' => array(
							'method'          => 'api_key',
							'setting_name'    => 'compass_reddit_client_id',
							'constant_name'   => 'REDDIT_CLIENT_ID',
							'env_var_name'    => 'REDDIT_CLIENT_ID',
							'credentials_url' => 'https://www.reddit.com/prefs/apps',
						),
					)
				);
			}
			if ( ! wp_is_connector_registered( 'reddit_client_secret' ) ) {
				$registry->register(
					'reddit_client_secret',
					array(
						'name'           => 'Reddit Client Secret',
						'description'    => 'Client secret of the Reddit app. The server trades it for a short-lived app-only token, so it never reaches the browser.',
						'type'           => 'api_key',
						'authentication' => array(
							'method'          => 'api_key',
							'setting_name'    => 'compass_reddit_client_secret',
							'constant_name'   => 'REDDIT_CLIENT_SECRET',
							'env_var_name'    => 'REDDIT_CLIENT_SECRET',
							'credentials_url' => 'https://www.reddit.com/prefs/apps',
						),
					)
				);
			}
			if ( ! wp_is_connector_registered( 'reddit_username' ) ) {
				$registry->register(
					'reddit_username',
					array(
						'name'           => 'Reddit Username',
						'description'    => 'Reddit account that owns the app. Reddit requires every API request to name it in the User-Agent, as "(by /u/username)".',
						'type'           => 'api_key',
						'authentication' => array(
							'method'          => 'api_key',
							'setting_name'    => 'compass_reddit_username',
							'constant_name'   => 'REDDIT_USERNAME',
							'env_var_name'    => 'REDDIT_USERNAME',
							'credentials_url' => 'https://www.reddit.com/prefs/apps',
						),
					)
				);
			}
			if ( ! wp_is_connector_registered( Freshmints_Social_Dispatch_Rest::CONNECTOR_ID ) ) {
				$registry->register(
					Freshmints_Social_Dispatch_Rest::CONNECTOR_ID,
					array(
						'name'           => 'Social Dispatch Webhook URL',
						'description'    => 'Incoming webhook URL (Slack, Discord or Zapier) that receives each reply you dispatch from Social Radar. The URL is a credential: anyone holding it can post to the channel, so it is stored here and used only by the server.',
						'type'           => 'api_key',
						'authentication' => array(
							'method'        => 'api_key',
							'setting_name'  => 'compass_social_dispatch_webhook_url',
							'constant_name' => 'COMPASS_SOCIAL_DISPATCH_WEBHOOK_URL',
							'env_var_name'  => 'COMPASS_SOCIAL_DISPATCH_WEBHOOK_URL',
						),
					)
				);
			}
			if ( ! wp_is_connector_registered( Freshmints_Crm_Webhook_Rest::CONNECTOR_ID ) ) {
				$registry->register(
					Freshmints_Crm_Webhook_Rest::CONNECTOR_ID,
					array(
						'name'           => 'CRM Webhook URL',
						'description'    => 'Incoming webhook URL (Zapier, Make, HubSpot or GoHighLevel) that receives the leads you send from Fresh Mints. The URL is a credential: anyone holding it can write into the CRM, so it is stored here and used only by the server.',
						'type'           => 'api_key',
						'authentication' => array(
							'method'        => 'api_key',
							'setting_name'  => 'compass_crm_webhook_url',
							'constant_name' => 'COMPASS_CRM_WEBHOOK_URL',
							'env_var_name'  => 'COMPASS_CRM_WEBHOOK_URL',
						),
					)
				);
			}
		}
	} );
}

add_action( 'plugins_loaded', 'run_xophz_compass_freshmints' );

function xophz_compass_freshmints_activate() {
	$public = new Xophz_Compass_Freshmints_Public( 'xophz-compass-freshmints', XOPHZ_COMPASS_FRESHMINTS_VERSION );
	$public->register_endpoints();
	flush_rewrite_rules();
	Freshmints_Agent_Finder_Sync::activate();
}
register_activation_hook( __FILE__, 'xophz_compass_freshmints_activate' );

function xophz_compass_freshmints_deactivate() {
	flush_rewrite_rules();
	Freshmints_Agent_Finder_Sync::deactivate();
}
register_deactivation_hook( __FILE__, 'xophz_compass_freshmints_deactivate' );

function xophz_compass_freshmints_action_links( $links ) {
	foreach ( $links as $link ) {
		if ( stripos( $link, '>Settings<' ) !== false ) {
			return $links;
		}
	}
	$settings_link = '<a href="options-general.php?page=xophz-compass-freshmints">' . __( 'Settings', 'xophz-compass-freshmints' ) . '</a>';
	$new_links     = array( 'settings' => $settings_link );
	foreach ( $links as $key => $value ) {
		$new_links[ $key ] = $value;
	}
	return $new_links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_compass_freshmints_action_links' );
