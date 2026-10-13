<?php
/**
 * Domain checks used by the website scraper: MX records and the usual business address patterns.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Api_Email_Domain {

	/**
	 * Check DNS MX records for domain.
	 *
	 * @param string $domain Target domain
	 * @return array MX status and record list
	 */
	public static function check_domain_mx_records( $domain ) {
		$clean_domain = strtolower( trim( preg_replace( '~^https?://~i', '', $domain ) ) );
		$clean_domain = explode( '/', $clean_domain )[0];
		$clean_domain = preg_replace( '/^www\./', '', $clean_domain );

		if ( empty( $clean_domain ) || ! strpos( $clean_domain, '.' ) ) {
			return array( 'valid' => false, 'records' => array(), 'domain' => $clean_domain );
		}

		$mx_records = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $clean_domain, DNS_MX );
			if ( ! empty( $records ) && is_array( $records ) ) {
				foreach ( $records as $rec ) {
					if ( ! empty( $rec['target'] ) ) {
						$mx_records[] = $rec['target'];
					}
				}
			}
		}

		if ( empty( $mx_records ) && function_exists( 'checkdnsrr' ) ) {
			$has_mx = @checkdnsrr( $clean_domain, 'MX' );
			if ( $has_mx ) {
				$mx_records[] = "mail.{$clean_domain}";
			}
		}

		return array(
			'valid'   => ! empty( $mx_records ),
			'records' => array_values( array_unique( $mx_records ) ),
			'domain'  => $clean_domain,
		);
	}

	/**
	 * Generate standardized business email permutations for a domain based on practitioner name.
	 *
	 * @param string $lead_name Full name of the lead
	 * @param string $domain Website domain
	 * @return array List of candidate email permutations
	 */
	public static function generate_domain_email_permutations( $lead_name, $domain ) {
		$clean_domain = strtolower( trim( preg_replace( '~^https?://~i', '', $domain ) ) );
		$clean_domain = explode( '/', $clean_domain )[0];
		$clean_domain = preg_replace( '/^www\./', '', $clean_domain );

		if ( empty( $clean_domain ) || ! strpos( $clean_domain, '.' ) ) {
			return array();
		}

		$permutations = array();
		$clean_name = strtolower( trim( preg_replace( '/[^a-zA-Z\s]/', '', $lead_name ) ) );
		$parts = array_values( array_filter( explode( ' ', $clean_name ) ) );

		if ( ! empty( $parts ) ) {
			$first = $parts[0];
			$last  = count( $parts ) > 1 ? end( $parts ) : '';

			if ( ! empty( $first ) && ! empty( $last ) ) {
				$first_initial = substr( $first, 0, 1 );

				$permutations[] = "{$first}.{$last}@{$clean_domain}";
				$permutations[] = "{$first}@{$clean_domain}";
				$permutations[] = "{$first_initial}{$last}@{$clean_domain}";
				$permutations[] = "{$first}{$last}@{$clean_domain}";
				$permutations[] = "{$first}_{$last}@{$clean_domain}";
			} elseif ( ! empty( $first ) ) {
				$permutations[] = "{$first}@{$clean_domain}";
			}
		}

		$standard = array( 'info', 'contact', 'office', 'appointments', 'admin', 'hello' );
		foreach ( $standard as $std ) {
			$permutations[] = "{$std}@{$clean_domain}";
		}

		return array_values( array_unique( $permutations ) );
	}
}
