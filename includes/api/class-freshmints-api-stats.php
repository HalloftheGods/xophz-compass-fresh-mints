<?php
/**
 * GET /stats (manage_options): pipeline counts and settings for the dashboard.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';

class Freshmints_Api_Stats extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/stats', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	/**
	 * Get Fresh Mints Pipeline Stats
	 */
	public function handle_get_stats() {
		global $wpdb;
		$total_contacts = 0;
		if ( post_type_exists( 'questbook_contact' ) ) {
			$counts         = (array) wp_count_posts( 'questbook_contact' );
			$total_contacts = (int) ( $counts['publish'] ?? 0 );
		}

		$bomb_subscribers = 0;
		$subscribers_table = $wpdb->prefix . 'bomb_bag_subscribers';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscribers_table ) ) === $subscribers_table ) {
			$bomb_subscribers = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$subscribers_table} WHERE source = 'Fresh Mints' OR custom_fields LIKE '%Fresh Mints%'" );
		}

		$bomb_lists = 0;
		$lists_table = $wpdb->prefix . 'bomb_bag_lists';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table ) ) === $lists_table ) {
			$bomb_lists = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$lists_table}" );
		}

		return rest_ensure_response( array(
			'success'            => true,
			'questbookContacts'  => $total_contacts,
			'bombBagSubscribers' => $bomb_subscribers,
			'bombBagLists'       => $bomb_lists,
			'loadMode'           => get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' ),
			'customSlug'         => get_option( 'xophz_compass_freshmints_custom_slug', 'fresh-mints' ),
			'hasGeminiConnector' => ! empty( $this->get_gemini_api_key() ),
		) );
	}
}
