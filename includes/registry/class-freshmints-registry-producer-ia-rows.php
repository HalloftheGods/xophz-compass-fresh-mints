<?php
/**
 * Row access for the Iowa Insurance Division producer dataset ("Insurance Producers Licensed in Iowa",
 * Iowa Data Hub dataset 669, data.iowa.gov, CC BY 4.0, refreshed daily).
 *
 * Freshmints_Registry_Producer_IA_Export downloads and reads the whole-file export (the Data Hub has no
 * query API). This class caches the small set of people it keeps and answers windows from that cache. It
 * knows nothing about leads; that is Freshmints_Registry_Producers_IA.
 *
 *   - one row per person (the file repeats an NPN only to list a second email address);
 *   - `first_active_date` is the date the person first became active in Iowa. Renewals and added lines
 *     do not move it (see Freshmints_Registry_Producers_IA), so "first licensed in the window" is a plain
 *     date comparison and needs no second lookup (Texas needs a per-NPN check; Iowa does not);
 *   - `iowa_resident` = 'Y' marks resident producers; `state` is the mailing-address state;
 *   - `loa_has_life` = 'Yes' is the life line of authority, which by Iowa Code 522B.6(2)(a) covers life
 *     insurance and annuities. Credit life is a separate line (`loa_has_credit`) and does not count.
 *
 * A person is cached when iowa_resident = 'Y', state = 'IA', loa_has_life = 'Yes' and first_active_date is
 * inside the export's horizon (400 days). A window longer than the horizon, or an 'all' call that asks for
 * more people than the cache holds, is answered with what the cache has and stats()['complete'] is false.
 *
 * Fail closed: when the export cannot be read in full, no rows are returned, complete is false, and
 * nothing is cached. The cache is a transient, kept for six hours by default.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-registry-producer-ia-export.php';

class Freshmints_Registry_Producer_IA_Rows {

	const CACHE_TTL_SECONDS = 21600;
	const CACHE_KEY         = 'freshmints_ia_producers_v1';

	/** @var float Unix time (microtime) after which no further request or parse step is started. */
	private $deadline;

	/** @var array<string,int|bool|string> */
	private $stats = array(
		'requests'              => 0,
		'rows_read'             => 0,
		'rows_without_npn'      => 0,
		'people_checked'        => 0,
		'people_first_licensed' => 0,
		'complete'              => true,
		'source_rows'           => 0,
		'from_cache'            => false,
		'horizon_days'          => Freshmints_Registry_Producer_IA_Export::HORIZON_DAYS,
		'dataset_modified'      => '',
		'error'                 => '',
	);

	public function __construct( float $deadline ) {
		$this->deadline = $deadline;
	}

	/**
	 * Same keys as the Texas rows class, read for Iowa:
	 *   requests               HTTP requests made (0 when the cache answered);
	 *   rows_read              people in the window before the limit;
	 *   rows_without_npn       qualifying rows skipped for having no NPN (whole file, not the window);
	 *   people_checked         people whose first_active_date was compared with the window;
	 *   people_first_licensed  people returned;
	 *   complete               false when the export could not be read, the window is longer than the horizon,
	 *                          or an 'all' call wanted more people than the cache holds;
	 * and these Iowa extras: source_rows (rows in the file), from_cache, horizon_days, dataset_modified
	 * (the export's Last-Modified time, '' when unknown) and error ('' or a short code).
	 *
	 * @return array<string,int|bool|string>
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * People who first became active in Iowa on or after $since, newest first, at most $limit.
	 *
	 * @param string $since Y-m-d (UTC) window start, or '' for no window (the newest people the cache holds).
	 * @return array[] Person records (see Freshmints_Registry_Producer_IA_Export); empty when the dataset could not be read.
	 */
	public function window_rows( int $limit, string $since ): array {
		$data = $this->load();
		if ( $data === null ) {
			return array();
		}

		$people = $data['people'];
		if ( $since !== '' ) {
			if ( $since < $data['horizon_start'] ) {
				$this->stats['complete'] = false;
				$this->stats['error']    = 'window_beyond_horizon';
			}
			$people = array_values(
				array_filter(
					$people,
					static function ( array $p ) use ( $since ): bool {
						return $p['first_active_date'] >= $since;
					}
				)
			);
		} elseif ( count( $people ) < $limit ) {
			$this->stats['complete'] = false;
			$this->stats['error']    = 'fewer_than_limit_in_horizon';
		}

		$this->stats['rows_read']      = count( $people );
		$this->stats['people_checked'] = count( $people );

		$people = array_slice( $people, 0, max( 0, $limit ) );

		$this->stats['people_first_licensed'] = count( $people );

		return $people;
	}

	/**
	 * A person record as the fields a lead is built from. Missing values are '' (or an empty list).
	 *
	 * @return array{npn:string,name:string,city:string,postal:string,issue_date:string,email:string,emails:string[],phone:string,address:string,lines:string[]}
	 */
	public static function fields( array $person ): array {
		$digits = (string) preg_replace( '/\D+/', '', (string) ( $person['business_phone'] ?? '' ) );
		$phone  = strlen( $digits ) === 10
			? sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) )
			: '';

		$zip    = (string) preg_replace( '/\D+/', '', (string) ( $person['zip_code'] ?? '' ) );
		$postal = strlen( $zip ) >= 5 ? substr( $zip, 0, 5 ) : '';

		$emails = array();
		foreach ( (array) ( $person['emails'] ?? array() ) as $raw ) {
			$email = sanitize_email( (string) $raw );
			if ( $email !== '' && ! in_array( $email, $emails, true ) ) {
				$emails[] = $email;
			}
		}

		$street  = trim( (string) ( $person['address_line_1'] ?? '' ) . ' ' . (string) ( $person['address_line_2'] ?? '' ) );
		$city    = (string) ( $person['city'] ?? '' );
		$address = $street !== ''
			? implode( ', ', array_filter( array( $street, $city, trim( 'IA ' . $postal ) ), 'strlen' ) )
			: '';

		return array(
			'npn'        => (string) ( $person['npn'] ?? '' ),
			'name'       => trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) ),
			'city'       => $city,
			'postal'     => $postal,
			'issue_date' => (string) ( $person['first_active_date'] ?? '' ),
			'email'      => $emails[0] ?? '',
			'emails'     => $emails,
			'phone'      => $phone,
			'address'    => $address,
			'lines'      => array_values( array_map( 'strval', (array) ( $person['lines'] ?? array() ) ) ),
		);
	}

	/**
	 * The cached people, or a fresh read of the export. Null when the export could not be read.
	 *
	 * @return array{version:int,fetched_at:int,horizon_start:string,people:array[]}|null
	 */
	private function load(): ?array {
		/**
		 * Seconds the filtered Iowa people are kept before the export is downloaded again. The portal
		 * refreshes the file once a day. 0 turns the cache off (every fetch then downloads about 52 MB).
		 */
		$ttl = (int) apply_filters( 'freshmints_registry_producers_ia_cache_ttl', self::CACHE_TTL_SECONDS );

		if ( $ttl > 0 ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ( $cached['version'] ?? 0 ) === Freshmints_Registry_Producer_IA_Export::VERSION
				&& isset( $cached['horizon_start'], $cached['people'] ) && is_array( $cached['people'] ) ) {
				$this->stats['from_cache']       = true;
				$this->stats['source_rows']      = (int) ( $cached['source_rows'] ?? 0 );
				$this->stats['rows_without_npn'] = (int) ( $cached['rows_without_npn'] ?? 0 );
				$this->stats['dataset_modified'] = (string) ( $cached['dataset_modified'] ?? '' );
				return $cached;
			}
		}

		$export = new Freshmints_Registry_Producer_IA_Export( $this->deadline );
		$data   = $export->fetch();

		$this->stats = array_merge( $this->stats, $export->stats() );

		if ( $data === null ) {
			$this->stats['complete'] = false;
			return null;
		}

		if ( $ttl > 0 ) {
			set_transient( self::CACHE_KEY, $data, $ttl );
		}

		return $data;
	}
}
