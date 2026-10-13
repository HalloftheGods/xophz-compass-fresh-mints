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
 * "Newly licensed" means a person whose EARLIEST Texas licence of any kind was
 * issued inside the window: a producer who has held a Texas licence for years
 * and has just added a life line is not new. Freshmints_Registry_Producer_Rows
 * does the dataset work (paging, the per-NPN first-issue check, timeouts);
 * this class maps its rows to leads. With no window ('all') every licence age
 * is wanted, so no first-license test applies.
 *
 * What the dataset does not have: phone, email and street address. Nothing is
 * invented for a missing value: a row with no name is skipped, a missing licence
 * number or issue date is left empty, and contact fields stay empty
 * (skipTraceData is null).
 *
 * Texas has no separate annuity qualification, so annuity authority rides on
 * the life qualifications listed in Freshmints_Registry_Producer_Rows::LIFE_QUALIFICATIONS.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-registry-producer-rows.php';

class Freshmints_Registry_Producers {

	const SOURCE_LABEL        = 'Texas Department of Insurance Licensee Registry (TX)';
	const ISSUER_LABEL        = 'Texas Department of Insurance';
	const SUPPORTED_STATE     = 'TX';
	const PROFESSION          = 'insurance';
	const TIME_BUDGET_SECONDS = 45;

	/** @var array<string,int|bool> Counters from the most recent fetch(); see last_stats(). */
	private static $last_stats = array();

	/**
	 * @param string $state              Two letter state; only TX is served by this dataset.
	 * @param int    $limit              Maximum leads to return.
	 * @param string $date_window        Days back to include as a digit string; '' , '0' or 'all' means no window.
	 * @param bool   $first_license_only True (default): only people whose earliest Texas licence of any kind falls in
	 *                                   the window, one lead per NPN. False: any life licence issued in the window.
	 *                                   Ignored when there is no window.
	 * @return array[] Leads, newest first, or an empty array when the state is unsupported or the dataset is unavailable.
	 */
	public static function fetch( string $state, int $limit, string $date_window, bool $first_license_only = true ): array {
		self::$last_stats = array();

		$state = strtoupper( trim( $state ) );
		if ( $state !== self::SUPPORTED_STATE || $limit < 1 ) {
			return array();
		}

		/**
		 * Seconds one fetch() may spend on dataset requests before it returns what it has.
		 * A background sync can raise this; a request from the browser should not.
		 */
		$budget = max( 1.0, (float) apply_filters( 'freshmints_registry_producers_time_budget', self::TIME_BUDGET_SECONDS ) );
		$source = new Freshmints_Registry_Producer_Rows( $state, microtime( true ) + $budget );
		$since  = self::window_start( $date_window );

		if ( $first_license_only && $since !== '' ) {
			$rows = $source->first_license_rows( $limit, $since );
		} else {
			$rows = $source->recent_rows( $limit, $since );
		}

		self::$last_stats = $source->stats();

		return self::build_leads( $rows, $state, $limit );
	}

	/**
	 * What the last fetch() did, for a caller that needs to know whether it got everything: SODA requests,
	 * window rows read, rows skipped for having no NPN, people checked and kept, and `complete`, which is
	 * false when a request failed or the row cap or time budget stopped the run early. Empty when the last
	 * fetch() queried nothing.
	 *
	 * @return array<string,int|bool>
	 */
	public static function last_stats(): array {
		return self::$last_stats;
	}

	/**
	 * Midnight UTC at the start of the window, as a SODA floating timestamp. '' when there is no window.
	 * Public so a caller can record the exact window a fetch() with the same $date_window will use.
	 */
	public static function window_start( string $date_window ): string {
		$days = ctype_digit( $date_window ) ? (int) $date_window : 0;
		return $days > 0 ? gmdate( 'Y-m-d\T00:00:00', time() - ( $days * DAY_IN_SECONDS ) ) : '';
	}

	/**
	 * Map rows to leads, one per producer: the dataset has a row per licence held,
	 * so rows that resolve to the same lead id are collapsed to the first one given.
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
		$f         = Freshmints_Registry_Producer_Rows::fields( $row );
		$full_name = self::format_name( $f['name'] );
		if ( $full_name === '' ) {
			return null;
		}

		$city       = self::format_name( $f['city'] );
		$title      = $f['qualification'] ?: 'Insurance Producer';
		$id         = self::lead_id( $state, $f['license'], $f['npn'], array( $full_name, $city, $f['postal'], $title, $f['issue_date'] ) );
		$slug       = $api->generate_preview_slug( $full_name, $id );
		$deal_value = $api->get_estimated_deal_value( self::PROFESSION );
		$grad_year  = $f['issue_date'] !== '' ? (int) substr( $f['issue_date'], 0, 4 ) : 0;
		$subheading = $city !== '' ? "Insurance services in {$city}, {$state}." : "Insurance services in {$state}.";

		return array(
			'id'                 => $id,
			'fullName'           => $full_name,
			'profession'         => self::PROFESSION,
			'professionTitle'    => $title,
			'state'              => $state,
			'city'               => $city,
			'licenseNumber'      => $f['license'] !== '' ? "{$state}-{$f['license']}" : '',
			'issueDate'          => $f['issue_date'],
			'collegeOrSchool'    => self::ISSUER_LABEL,
			'graduationYear'     => $grad_year,
			'licenseStatus'      => self::license_status( $f['issue_date'] ),
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
			// The registry's own values, unformatted, for callers that store the person (Agent Finder sync).
			'registry'           => array(
				'npn'           => $f['npn'],
				'licenseType'   => $f['license_type'],
				'qualification' => $f['qualification'],
				'postalCode'    => $f['postal'],
			),
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
	 * The dataset stores names in capitals: 'JANE DE NICOLA' becomes 'Jane De Nicola'.
	 */
	private static function format_name( string $raw ): string {
		return ucwords( strtolower( $raw ), " -'" );
	}
}
