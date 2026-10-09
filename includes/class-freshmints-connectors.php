<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Reads an API key VALUE for a connector registered with the WordPress core Connectors API.
 *
 * Core exposes no public function that returns a key's value, so this resolver follows
 * the same precedence as core's _wp_connectors_get_api_key_source(): environment variable,
 * then PHP constant, then the database option. Every name comes from the connector's own
 * `authentication` definition, so this class never hard-codes an option, constant or env name.
 */
class Freshmints_Connectors {

	public static function get_key( string $connector_id ): string {
		if ( ! function_exists( 'wp_get_connector' ) ) {
			return '';
		}

		if ( ! wp_is_connector_registered( $connector_id ) ) {
			return '';
		}

		$connector = wp_get_connector( $connector_id );
		if ( ! is_array( $connector ) ) {
			return '';
		}

		$auth = $connector['authentication'] ?? array();

		$from_env = self::from_env( (string) ( $auth['env_var_name'] ?? '' ) );
		if ( $from_env !== '' ) {
			return $from_env;
		}

		$from_constant = self::from_constant( (string) ( $auth['constant_name'] ?? '' ) );
		if ( $from_constant !== '' ) {
			return $from_constant;
		}

		return self::from_option( (string) ( $auth['setting_name'] ?? '' ) );
	}

	private static function from_env( string $env_var_name ): string {
		if ( $env_var_name === '' ) {
			return '';
		}

		return self::as_string( getenv( $env_var_name ) );
	}

	private static function from_constant( string $constant_name ): string {
		if ( $constant_name === '' || ! defined( $constant_name ) ) {
			return '';
		}

		return self::as_string( constant( $constant_name ) );
	}

	private static function from_option( string $setting_name ): string {
		if ( $setting_name === '' ) {
			return '';
		}

		return self::as_string( get_option( $setting_name, '' ) );
	}

	private static function as_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
