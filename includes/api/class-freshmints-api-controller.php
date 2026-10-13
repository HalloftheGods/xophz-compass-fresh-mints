<?php
/**
 * Base for the Fresh Mints core REST controllers (namespace xophz-freshmints/v1).
 *
 * Holds the helpers more than one route group uses: the admin permission check, connector key
 * lookups, the preview slug and the estimated deal value. Each subclass registers one route group.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

abstract class Freshmints_Api_Controller {

	/**
	 * Register this controller's routes on rest_api_init.
	 */
	abstract public function register_routes();

	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Retrieve API keys for various services.
	 */
	protected function get_api_key( $service ) {
		$connector_ids = array(
			'yelp'   => 'yelp_api_key',
			'google' => 'google_places_api_key',
		);

		if ( ! isset( $connector_ids[ $service ] ) ) {
			return '';
		}

		return Freshmints_Connectors::get_key( $connector_ids[ $service ] );
	}

	/**
	 * Retrieve the API key for Gemini using WP Connectors API and ecosystem settings.
	 */
	protected function get_gemini_api_key() {
		foreach ( array( 'google', 'google_gemini_api_key' ) as $connector_id ) {
			$api_key = Freshmints_Connectors::get_key( $connector_id );
			if ( $api_key !== '' ) {
				return $api_key;
			}
		}

		// Kept on purpose: the Docker stack injects GEMINI_API_KEY into the container environment
		// (.env -> docker-compose.yml) and it is in use on this site. It is not a name the core
		// `google` connector declares (that is GOOGLE_API_KEY), so the resolver cannot see it.
		$env_key = getenv( 'GEMINI_API_KEY' );
		return is_string( $env_key ) ? trim( $env_key ) : '';
	}

	/**
	 * Estimated deal value helper.
	 */
	public function get_estimated_deal_value( $profession ) {
		$values = array(
			'financial_advisor' => 3950,
			'finance'           => 3950,
			'legal'             => 3950,
			'dental'            => 2650,
			'veterinary'        => 2650,
			'trade'             => 2650,
			'architecture'      => 2650,
			'real_estate'       => 1650,
			'chiropractic'      => 1650,
			'therapy'           => 1650,
			'insurance'         => 1650,
			'nursing'           => 1250,
			'beauty'            => 1250,
		);
		return $values[ $profession ] ?? 1650;
	}

	/**
	 * Generate a clean kebab-case preview slug from practitioner full name with collision support.
	 */
	public function generate_preview_slug( $full_name, $id = '', $state = '', $license = '' ) {
		$clean = sanitize_title( $full_name );
		if ( empty( $clean ) ) {
			$clean = ! empty( $id ) ? sanitize_title( $id ) : 'lead-' . wp_rand( 1000, 9999 );
		}

		// If state is provided and needed for disambiguation
		if ( ! empty( $state ) && ( empty( $clean ) || strpos( $clean, 'dr-' ) === 0 ) ) {
			$clean .= '-' . strtolower( sanitize_title( $state ) );
		}

		return $clean;
	}
}
