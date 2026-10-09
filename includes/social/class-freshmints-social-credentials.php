<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Server-side credential lookup for the Social Radar providers.
 *
 * Resolution order per key: the WordPress option, then a PHP constant, then
 * $_ENV. Values are read here and handed straight to the provider that needs
 * them. They are never returned by a route, logged or placed in a reason.
 */
class Freshmints_Social_Credentials {

	const OPTION_PREFIX   = 'xophz_compass_freshmints_social_';
	const CONSTANT_PREFIX = 'XOPHZ_COMPASS_FRESHMINTS_SOCIAL_';
	const RECOGNIZED_KEYS = array( 'youtube_api_key', 'stackexchange_key' );

	public static function get( string $key ): string {
		if ( ! in_array( $key, self::RECOGNIZED_KEYS, true ) ) {
			return '';
		}

		$name = self::CONSTANT_PREFIX . strtoupper( $key );

		$from_option = self::as_string( get_option( self::OPTION_PREFIX . $key, '' ) );
		if ( $from_option !== '' ) {
			return $from_option;
		}

		$from_constant = defined( $name ) ? self::as_string( constant( $name ) ) : '';
		if ( $from_constant !== '' ) {
			return $from_constant;
		}

		return self::as_string( $_ENV[ $name ] ?? '' );
	}

	private static function as_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
