<?php
/**
 * Iowa insurance producer registry tier.
 *
 * Reads newly licensed life insurance producers from the Iowa Insurance Division's open dataset
 * "Insurance Producers Licensed in Iowa" on the Iowa Data Hub (data.iowa.gov, dataset 669) and emits
 * leads in the same array shape as Freshmints_Registry_Producers (Texas), so callers do not change.
 * Freshmints_Registry_Producers::fetch() sends state 'IA' here. Dataset access is
 * Freshmints_Registry_Producer_IA_Export (download and parse) and Freshmints_Registry_Producer_IA_Rows (cache
 * and windows); this class maps their people to leads.
 *
 * What the dataset has (checked against the live export, 2026-10-12; 232,650 rows, one per person):
 *   - `first_active_date`: when the person first became active in Iowa, one date per person. Renewals do not
 *     reset it (dates go back to 1959) and adding a line does not move it: people with older dates hold
 *     more lines (mean 1.8 lines for those first active in the last 30 days, 3.9 for those before 2010).
 *   - `iowa_resident` ('Y'/'N') and the mailing-address `state`.
 *   - `loa_has_*` Yes/No flags per line of authority. `loa_has_life` is the life line, which covers life
 *     insurance and annuities (Iowa Code 522B.6(2)(a)); `loa_has_variable` adds variable life and annuity
 *     products and requires an active life line (IAC 191-10.4(8)).
 *   - name, mailing address, business phone and email address, as the State publishes them.
 * It has no licence number, no qualification text and no per-line issue date.
 * Licence: Creative Commons Attribution 4.0 (CC BY 4.0), refreshed daily. There is no query API: only
 * whole-file exports, so the export class downloads the file and the rows class caches what it keeps.
 *
 * "Newly licensed" here means a RESIDENT producer, with an Iowa address, who holds the life line and whose
 * first_active_date falls inside the window. Differences from Texas:
 *   - Texas checks each candidate's earliest licence of any kind across the whole dataset because its
 *     rows are per licence. Iowa's rows are per person and first_active_date is already the earliest date,
 *     so the check is one date comparison and needs no second lookup.
 *   - first_active_date is the first date in IOWA, not the first licence anywhere. Residents are used
 *     because their Iowa date is normally their first licence, while a non-resident's is only the date they
 *     added Iowa. It is not exact: a person who moved to Iowa and converted a licence from elsewhere looks
 *     new. Cross-check on 2026-10-12: of the 139 residents active in the last 365 days who also appear in
 *     the Texas dataset, 9 (6.5%) held a Texas licence older than their Iowa date.
 *   - $first_license_only has no effect: the dataset cannot say when a second line was added, so the answer
 *     is the same either way (a person is in the window or not).
 *   - annuityCapable (decision on #6021) is true when the person holds the life line. Iowa has no limited
 *     or temporary life class in this file (credit life is its own line and is not counted).
 *
 * Contact data: email and business phone are in the dataset itself, so leads carry them under
 * `registryContact` (never in skipTraceData, which stays null and 'Not Traced': nothing was traced or
 * verified). Having them is not consent. Consent is captured before any contact, and CAN-SPAM and TCPA/DNC
 * apply to how they are used. The filter `freshmints_registry_producers_ia_include_contact` can turn the
 * block off.
 *
 * Terms: CC BY 4.0 allows commercial reuse with attribution, so ATTRIBUTION must stay with the data (it is
 * in every lead's registry block, and Freshmints_Registry_Producers::describe() hands it to the response).
 * No restriction on solicitation was found in the dataset metadata, the Iowa Data Hub pages, or
 * iowa.gov/policies. That page's sentence about marketing databases describes how the State treats data it
 * collects from visitors to its own sites; it does not govern reuse of an open dataset. Have the owner
 * confirm with data@iowa.gov if a written answer is wanted.
 *
 * Other states that publish licensee data: apps/fresh-mints/docs/research/state-licensee-open-data.md.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-registry-producer-ia-rows.php';

class Freshmints_Registry_Producers_IA {

	const SOURCE_LABEL        = 'Iowa Insurance Division Producer Registry (IA)';
	const ISSUER_LABEL        = 'Iowa Insurance Division';
	const SUPPORTED_STATE     = 'IA';
	const PROFESSION          = 'insurance';
	const LICENSE_LABEL       = 'CC BY 4.0';
	const TIME_BUDGET_SECONDS = 45;
	const ATTRIBUTION         = 'Source: Iowa Insurance Division, "Insurance Producers Licensed in Iowa", Iowa Data Hub (https://data.iowa.gov/catalog/dataset/669), licensed CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/). Filtered and reformatted by Fresh Mints.';

	/** @var array<string,int|bool|string> Counters from the most recent fetch(); see last_stats(). */
	private static $last_stats = array();

	/**
	 * @param string $state              Two letter state; only IA is served by this dataset.
	 * @param int    $limit              Maximum leads to return.
	 * @param string $date_window        Days back to include as a digit string; '', '0' or 'all' means no window.
	 * @param bool   $first_license_only Accepted so the signature matches Texas. It changes nothing for Iowa: the
	 *                                   dataset has one first_active_date per person, so the result is always the
	 *                                   people first active in Iowa inside the window.
	 * @return array[] Leads, newest first, or an empty array when the state is unsupported or the dataset is unavailable.
	 */
	public static function fetch( string $state, int $limit, string $date_window, bool $first_license_only = true ): array {
		unset( $first_license_only );
		self::$last_stats = array();

		$state = strtoupper( trim( $state ) );
		if ( $state !== self::SUPPORTED_STATE || $limit < 1 ) {
			return array();
		}

		/** Same filter as the Texas tier: seconds one fetch() may spend before it returns what it has. */
		$budget = max( 1.0, (float) apply_filters( 'freshmints_registry_producers_time_budget', self::TIME_BUDGET_SECONDS ) );
		$source = new Freshmints_Registry_Producer_IA_Rows( microtime( true ) + $budget );
		$since  = self::window_start( $date_window );
		$people = $source->window_rows( $limit, $since !== '' ? substr( $since, 0, 10 ) : '' );

		self::$last_stats = $source->stats();

		return self::build_leads( $people, $state, $limit );
	}

	/**
	 * What the last fetch() did, for a caller that needs to know whether it got everything. Same keys as the
	 * Texas tier (requests, rows_read, rows_without_npn, people_checked, people_first_licensed, complete) plus
	 * source_rows, from_cache, horizon_days, dataset_modified and error; see Freshmints_Registry_Producer_IA_Rows::stats().
	 * Empty when the last fetch() read nothing.
	 *
	 * @return array<string,int|bool|string>
	 */
	public static function last_stats(): array {
		return self::$last_stats;
	}

	/**
	 * Midnight UTC at the start of the window, formatted like the Texas tier ('Y-m-d\T00:00:00'). '' when there is no window.
	 */
	public static function window_start( string $date_window ): string {
		$days = ctype_digit( $date_window ) ? (int) $date_window : 0;
		return $days > 0 ? gmdate( 'Y-m-d\T00:00:00', time() - ( $days * DAY_IN_SECONDS ) ) : '';
	}

	/**
	 * Map people to leads, one per NPN.
	 *
	 * @param array[] $people
	 * @return array[]
	 */
	private static function build_leads( array $people, string $state, int $limit ): array {
		$api   = new Freshmints_API();
		$leads = array();
		$seen  = array();

		foreach ( $people as $person ) {
			if ( count( $leads ) >= $limit ) {
				break;
			}

			$lead = self::build_lead( $person, $state, $api );
			if ( $lead === null || isset( $seen[ $lead['id'] ] ) ) {
				continue;
			}

			$seen[ $lead['id'] ] = true;
			$leads[]             = $lead;
		}

		return $leads;
	}

	/**
	 * @return array|null The lead, or null when the person has no name or no NPN.
	 */
	private static function build_lead( array $person, string $state, Freshmints_API $api ): ?array {
		$f         = Freshmints_Registry_Producer_IA_Rows::fields( $person );
		$full_name = self::format_name( $f['name'] );
		if ( $full_name === '' || $f['npn'] === '' ) {
			return null;
		}

		$city       = self::format_name( $f['city'] );
		$title      = $f['lines'] ? implode( ', ', $f['lines'] ) : 'Insurance Producer';
		$annuity    = in_array( 'Life', $f['lines'], true );
		$id         = strtolower( $state ) . '-npn-' . sanitize_title( $f['npn'] );
		$slug       = $api->generate_preview_slug( $full_name, $id );
		$deal_value = $api->get_estimated_deal_value( self::PROFESSION );
		$grad_year  = $f['issue_date'] !== '' ? (int) substr( $f['issue_date'], 0, 4 ) : 0;
		$subheading = $city !== '' ? "Insurance services in {$city}, {$state}." : "Insurance services in {$state}.";

		$lead = array(
			'id'                 => $id,
			'fullName'           => $full_name,
			'profession'         => self::PROFESSION,
			'professionTitle'    => $title,
			'state'              => $state,
			'city'               => $city,
			'licenseNumber'      => '',
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
				'tagline'         => 'Verified Iowa Insurance License',
				'previewUrl'      => home_url( "/fresh-mints/#/preview/{$slug}" ),
				'offerPrice'      => $deal_value,
			),
			'estimatedDealValue' => $deal_value,
			'createdAt'          => gmdate( 'c' ),
			'annuityCapable'     => $annuity,
			// Same block the Texas tier adds for the Agent Finder sync, with the Iowa-specific values.
			'registry'           => array(
				'npn'               => $f['npn'],
				'licenseType'       => 'Resident Producer',
				'qualification'     => $title,
				'postalCode'        => $f['postal'],
				'annuityCapable'    => $annuity,
				'variableAuthority' => in_array( 'Variable Life & Annuity', $f['lines'], true ),
				'linesOfAuthority'  => $f['lines'],
				'sourceLabel'       => self::SOURCE_LABEL,
				'license'           => self::LICENSE_LABEL,
				'attribution'       => self::ATTRIBUTION,
			),
		);

		/** Whether leads carry the email, phone and mailing address the State publishes. */
		if ( apply_filters( 'freshmints_registry_producers_ia_include_contact', true ) ) {
			$contact = array_filter(
				array(
					'email'   => $f['email'],
					'emails'  => $f['emails'],
					'phone'   => $f['phone'],
					'address' => $f['address'],
				)
			);
			if ( $contact ) {
				$lead['registryContact'] = $contact + array( 'source' => self::ISSUER_LABEL );
			}
		}

		return $lead;
	}

	/**
	 * 'Newly Issued' within a year of the first active date; otherwise (or with no date) 'Active Board Pass'.
	 */
	private static function license_status( string $issue_date ): string {
		$issued_at = $issue_date !== '' ? strtotime( $issue_date ) : false;
		$is_recent = $issued_at !== false && ( time() - $issued_at ) <= YEAR_IN_SECONDS;

		return $is_recent ? 'Newly Issued' : 'Active Board Pass';
	}

	/**
	 * The dataset stores names in capitals: 'JANE DE NICOLA' becomes 'Jane De Nicola'. Same rule as the Texas tier.
	 */
	private static function format_name( string $raw ): string {
		return ucwords( strtolower( $raw ), " -'" );
	}
}
