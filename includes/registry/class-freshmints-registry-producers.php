<?php
/**
 * Insurance producer registry tier.
 *
 * Reads newly licensed life insurance producers from the Texas Department of
 * Insurance open dataset on data.texas.gov (Socrata SODA, dataset kxv3-diwf)
 * and emits leads in the same array shape as the NY Socrata branch of
 * Freshmints_API::handle_fetch_live_registry, so the response envelope
 * ( leads, source, groundingNotes ) is unchanged.
 *
 * What the dataset does not have: phone, email, street address and NPN-less
 * rows for the newest day or so. Nothing is invented for a missing value:
 * a row with no name is skipped, a missing licence number or issue date is
 * left empty, and contact fields stay empty (skipTraceData is null).
 *
 * Texas has no separate annuity qualification, so annuity authority rides on
 * the life qualifications listed in LIFE_QUALIFICATIONS.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Registry_Producers {

	const DATASET_URL      = 'https://data.texas.gov/resource/kxv3-diwf.json';
	const SOURCE_LABEL     = 'Texas Department of Insurance Licensee Registry (TX)';
	const ISSUER_LABEL     = 'Texas Department of Insurance';
	const SUPPORTED_STATE  = 'TX';
	const PROFESSION       = 'insurance';
	const TIMEOUT_SECONDS  = 15;
	const MAX_FETCH_ROWS   = 200;
	const FETCH_MULTIPLIER = 3;

	/** Exact `qualification` values for life lines of authority (verified against the live dataset). */
	const LIFE_QUALIFICATIONS = array(
		'Life, Accident, Health & HMO',
		'Life Agent/Agency',
		'Life Ins Not Exceeding $25,000',
		'Temp. Life,  A&H, HMO',
		'Temporary Life Agent',
		'LSBR - Life Agent',
	);

	/**
	 * @param string $state       Two letter state; only TX is served by this dataset.
	 * @param int    $limit       Maximum leads to return.
	 * @param string $date_window Days back to include as a digit string; '' , '0' or 'all' means no window.
	 * @return array[] Leads, or an empty array when the state is unsupported or the dataset is unavailable.
	 */
	public static function fetch( string $state, int $limit, string $date_window ): array {
		$state = strtoupper( trim( $state ) );
		if ( $state !== self::SUPPORTED_STATE || $limit < 1 ) {
			return array();
		}

		$rows = self::request_rows( $state, $limit, self::window_start( $date_window ) );

		return self::build_leads( $rows, $state, $limit );
	}

	/**
	 * Midnight UTC at the start of the window, as a SODA floating timestamp. '' when there is no window.
	 */
	private static function window_start( string $date_window ): string {
		$days = ctype_digit( $date_window ) ? (int) $date_window : 0;
		return $days > 0 ? gmdate( 'Y-m-d\T00:00:00', time() - ( $days * DAY_IN_SECONDS ) ) : '';
	}

	private static function soql_quote( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	private static function build_where( string $state, string $since ): string {
		$clauses = array(
			'state = ' . self::soql_quote( $state ),
			'qualification in(' . implode( ',', array_map( array( __CLASS__, 'soql_quote' ), self::LIFE_QUALIFICATIONS ) ) . ')',
		);
		if ( $since !== '' ) {
			$clauses[] = 'license_issue_date >= ' . self::soql_quote( $since );
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Query the dataset and return only well-formed row objects.
	 *
	 * @return array[]
	 */
	private static function request_rows( string $state, int $limit, string $since ): array {
		$fetch_limit = min( $limit * self::FETCH_MULTIPLIER, self::MAX_FETCH_ROWS );
		$query       = http_build_query( array(
			'$where' => self::build_where( $state, $since ),
			'$order' => 'license_issue_date DESC',
			'$limit' => $fetch_limit,
		), '', '&', PHP_QUERY_RFC3986 );

		$response = wp_remote_get( self::DATASET_URL . '?' . $query, array( 'timeout' => self::TIMEOUT_SECONDS ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( $decoded, 'is_array' ) );
	}

	/**
	 * Map rows to leads, one per producer: the dataset has a row per licence held,
	 * so rows that resolve to the same lead id are collapsed to the newest one.
	 *
	 * @param array[] $rows
	 * @return array[]
	 */
	private static function build_leads( array $rows, string $state, int $limit ): array {
		$api   = new Freshmints_API();
		$leads = array();
		$seen  = array();

		foreach ( $rows as $row ) {
			if ( count( $leads ) >= $limit ) {
				break;
			}

			$lead = self::build_lead( $row, $state, $api );
			if ( $lead === null || isset( $seen[ $lead['id'] ] ) ) {
				continue;
			}

			$seen[ $lead['id'] ] = true;
			$leads[]             = $lead;
		}

		return $leads;
	}

	/**
	 * @return array|null The lead, or null when the row has no name.
	 */
	private static function build_lead( array $row, string $state, Freshmints_API $api ): ?array {
		$full_name = self::format_name( self::text( $row, 'name' ) );
		if ( $full_name === '' ) {
			return null;
		}

		$license    = self::token( $row, 'license_number' );
		$npn        = self::token( $row, 'npn' );
		$issue_date = self::issue_date( self::text( $row, 'license_issue_date' ) );
		$city       = self::format_name( self::text( $row, 'city' ) );
		$title      = self::text( $row, 'qualification' ) ?: 'Insurance Producer';
		$id         = self::lead_id( $state, $license, $npn, array( $full_name, $city, self::text( $row, 'pstl_cd' ), $title, $issue_date ) );
		$slug       = $api->generate_preview_slug( $full_name, $id );
		$deal_value = $api->get_estimated_deal_value( self::PROFESSION );
		$grad_year  = $issue_date !== '' ? (int) substr( $issue_date, 0, 4 ) : 0;
		$subheading = $city !== '' ? "Insurance services in {$city}, {$state}." : "Insurance services in {$state}.";

		return array(
			'id'                 => $id,
			'fullName'           => $full_name,
			'profession'         => self::PROFESSION,
			'professionTitle'    => $title,
			'state'              => $state,
			'city'               => $city,
			'licenseNumber'      => $license !== '' ? "{$state}-{$license}" : '',
			'issueDate'          => $issue_date,
			'collegeOrSchool'    => self::ISSUER_LABEL,
			'graduationYear'     => $grad_year,
			'licenseStatus'      => self::license_status( $issue_date ),
			'skipTraceStatus'    => 'Not Traced',
			'skipTraceData'      => null,
			'outreachStatus'     => 'Uncontacted',
			'websiteConfig'      => array(
				'previewSlug'     => $slug,
				'heroHeadline'    => "{$full_name} - Licensed Insurance Producer",
				'heroSubheadline' => $subheading,
				'tagline'         => 'Verified Texas Insurance License',
				'previewUrl'      => home_url( "/fresh-mints/#/preview/{$slug}" ),
				'offerPrice'      => $deal_value,
			),
			'estimatedDealValue' => $deal_value,
			'createdAt'          => gmdate( 'c' ),
		);
	}

	/**
	 * Stable id from the licence number, else the NPN, else a hash of the row's own
	 * real fields (so the same row always gets the same id). Never random.
	 *
	 * @param string[] $identity_parts Real field values used only when there is no licence number or NPN.
	 */
	private static function lead_id( string $state, string $license, string $npn, array $identity_parts ): string {
		$prefix = strtolower( $state );
		if ( $license !== '' ) {
			return $prefix . '-' . sanitize_title( $license );
		}
		if ( $npn !== '' ) {
			return $prefix . '-npn-' . sanitize_title( $npn );
		}

		return $prefix . '-' . substr( md5( strtolower( implode( '|', $identity_parts ) ) ), 0, 12 );
	}

	/**
	 * 'Newly Issued' within a year of the issue date; otherwise (or with no date) 'Active Board Pass'.
	 */
	private static function license_status( string $issue_date ): string {
		$issued_at = $issue_date !== '' ? strtotime( $issue_date ) : false;
		$is_recent = $issued_at !== false && ( time() - $issued_at ) <= YEAR_IN_SECONDS;

		return $is_recent ? 'Newly Issued' : 'Active Board Pass';
	}

	/**
	 * SODA floating timestamp ( 2026-10-08T00:00:00.000 ) to Y-m-d, or '' when it is not a real date.
	 */
	private static function issue_date( string $raw ): string {
		$date  = substr( $raw, 0, 10 );
		$parts = explode( '-', $date );
		$is_ymd = count( $parts ) === 3 && ctype_digit( implode( '', $parts ) );

		return ( $is_ymd && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) ? $date : '';
	}

	/**
	 * The dataset stores names in capitals: 'JANE DE NICOLA' becomes 'Jane De Nicola'.
	 */
	private static function format_name( string $raw ): string {
		return ucwords( strtolower( $raw ), " -'" );
	}

	/**
	 * Read a scalar field as a trimmed string with runs of whitespace collapsed; anything else is an empty string.
	 */
	private static function text( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) ) : '';
	}

	/**
	 * Read an identifier field; anything that is not a short alphanumeric token is treated as missing.
	 */
	private static function token( array $row, string $key ): string {
		$value = self::text( $row, $key );
		return preg_match( '/^[A-Za-z0-9-]{1,30}$/', $value ) ? $value : '';
	}
}
