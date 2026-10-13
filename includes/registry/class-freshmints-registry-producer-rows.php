<?php
/**
 * Row access for the Texas Department of Insurance licensee dataset (data.texas.gov, Socrata SODA, kxv3-diwf).
 *
 * The dataset has a row per licence and line of authority. This class turns a state, a window start and a
 * limit into raw dataset rows, and reads fields out of those rows. It knows nothing about leads; that is
 * Freshmints_Registry_Producers.
 *
 * Two ways to ask for rows:
 *   - recent_rows():        any life licence issued on or after the window start, newest first.
 *   - first_license_rows(): one row per PERSON whose earliest Texas licence of any kind falls in the window.
 *
 * first_license_rows() works in three steps:
 *   1. candidates: Texas address, life qualification, issued on or after the window start, paged;
 *   2. for the candidates' NPNs, min( license_issue_date ) over every row of that NPN, in chunks;
 *   3. keep the NPNs whose first issue date is inside the window, with their earliest life row in it.
 * A row without an NPN cannot be tied to a person, so it is counted and skipped.
 *
 * Every request has a timeout, a request that fails is retried once, and the run stops at MAX_WINDOW_ROWS
 * window rows or when the time budget runs out. stats() says whether a run finished cleanly.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Registry_Producer_Rows {

	const DATASET_URL        = 'https://data.texas.gov/resource/kxv3-diwf.json';
	const TIMEOUT_SECONDS    = 15;
	const FETCH_MULTIPLIER   = 3;
	const PAGE_ROWS          = 1000;
	const NPN_CHUNK          = 150;
	const MAX_WINDOW_ROWS    = 20000;
	const RETRY_PAUSE_MICROS = 500000;

	/** Columns a lead is built from. */
	const ROW_FIELDS = 'npn,license_number,name,qualification,license_issue_date,city,pstl_cd';

	/** Newest first, with tie-breaks so paging by offset never repeats or drops a row. */
	const ORDER_NEWEST = 'license_issue_date DESC, npn DESC, license_number DESC';

	/** Exact `qualification` values for life lines of authority (verified against the live dataset). */
	const LIFE_QUALIFICATIONS = array(
		'Life, Accident, Health & HMO',
		'Life Agent/Agency',
		'Life Ins Not Exceeding $25,000',
		'Temp. Life,  A&H, HMO',
		'Temporary Life Agent',
		'LSBR - Life Agent',
	);

	/** @var string */
	private $state;

	/** @var float Unix time (microtime) after which no further request is started. */
	private $deadline;

	/** @var array<string,int|bool> */
	private $stats = array(
		'requests'              => 0,
		'rows_read'             => 0,
		'rows_without_npn'      => 0,
		'people_checked'        => 0,
		'people_first_licensed' => 0,
		'complete'              => true,
	);

	public function __construct( string $state, float $deadline ) {
		$this->state    = $state;
		$this->deadline = $deadline;
	}

	/**
	 * SODA requests made, window rows read, rows skipped for having no NPN, people sent through the
	 * first-license check and people kept, and `complete`: false when a request failed or the row cap or
	 * the time budget stopped the run before the limit was met or the window was read.
	 *
	 * @return array<string,int|bool>
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Any life licence issued on or after $since (or the newest ones when $since is ''), newest first.
	 * Reads limit x FETCH_MULTIPLIER rows, paged, so collapsing repeats still leaves enough leads.
	 *
	 * @return array[]
	 */
	public function recent_rows( int $limit, string $since ): array {
		$want  = min( $limit * self::FETCH_MULTIPLIER, self::MAX_WINDOW_ROWS );
		$where = $this->build_where( $since );
		$rows  = array();

		while ( count( $rows ) < $want ) {
			if ( $this->out_of_time() ) {
				break;
			}

			$size = min( self::PAGE_ROWS, $want - count( $rows ) );
			$page = $this->soda( array(
				'$select' => self::ROW_FIELDS,
				'$where'  => $where,
				'$order'  => self::ORDER_NEWEST,
				'$limit'  => $size,
				'$offset' => count( $rows ),
			) );
			if ( $page === null ) {
				break;
			}

			$rows                    = array_merge( $rows, $page );
			$this->stats['rows_read'] = count( $rows );
			if ( count( $page ) < $size ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * One row per person whose earliest Texas licence of any kind is on or after $since, newest first,
	 * at most $limit of them. $since must not be ''.
	 *
	 * Window rows are read a page at a time, newest first, and each page's new NPNs go through the
	 * first-issue check, so a small limit stops after a page or two. Pages run newest to oldest, so a later
	 * row for an NPN already seen is an earlier licence and replaces it. When the whole window was read
	 * that already is each person's earliest life row. When the run stopped early it may not be, so the
	 * kept people's earliest rows are fetched directly: the row (and the lead id built from it) is the
	 * same whatever the limit.
	 *
	 * @return array[]
	 */
	public function first_license_rows( int $limit, string $since ): array {
		$since_day = substr( $since, 0, 10 );
		$where     = $this->build_where( $since );
		$people    = array(); // npn => earliest window row read so far.
		$checked   = array(); // npn => true once queued for the first-issue check.
		$kept      = array(); // NPNs confirmed as first-licensed in the window, newest first.
		$offset    = 0;
		$exhausted = false;

		while ( count( $kept ) < $limit ) {
			if ( $offset >= self::MAX_WINDOW_ROWS || $this->out_of_time() ) {
				$this->stats['complete'] = false;
				break;
			}

			$page = $this->soda( array(
				'$select' => self::ROW_FIELDS,
				'$where'  => $where,
				'$order'  => self::ORDER_NEWEST,
				'$limit'  => self::PAGE_ROWS,
				'$offset' => $offset,
			) );
			if ( $page === null ) {
				break;
			}

			$pending = array();
			foreach ( $page as $row ) {
				$npn = self::npn( $row );
				if ( $npn === '' ) {
					++$this->stats['rows_without_npn'];
					continue;
				}
				$people[ $npn ] = $row;
				if ( ! isset( $checked[ $npn ] ) ) {
					$checked[ $npn ] = true;
					$pending[]       = $npn;
				}
			}

			$this->stats['rows_read'] += count( $page );
			$kept                      = array_merge( $kept, $this->first_licensed( $pending, $since_day, $limit - count( $kept ) ) );
			$offset                   += self::PAGE_ROWS;

			if ( count( $page ) < self::PAGE_ROWS ) {
				$exhausted = true;
				break;
			}
		}

		$kept = array_slice( $kept, 0, $limit );
		$this->stats['people_first_licensed'] = count( $kept );

		if ( ! $exhausted ) {
			$people = array_replace( $people, $this->earliest_rows( $since, $kept ) );
		}

		$rows = array();
		foreach ( $kept as $npn ) {
			$rows[] = $people[ $npn ];
		}

		return $rows;
	}

	/**
	 * The NPNs, in the order given, whose first issue date across every row of that NPN (any address
	 * state, any licence type) is on or after $since_day. At most $room are returned. An NPN whose chunk
	 * could not be checked is left out rather than passed unchecked.
	 *
	 * @param string[] $npns
	 * @return string[]
	 */
	private function first_licensed( array $npns, string $since_day, int $room ): array {
		$kept = array();

		foreach ( array_chunk( $npns, self::NPN_CHUNK ) as $chunk ) {
			if ( count( $kept ) >= $room ) {
				break;
			}
			if ( $this->out_of_time() ) {
				$this->stats['complete'] = false;
				break;
			}

			$found = $this->soda( array(
				'$select' => 'npn, min(license_issue_date) as first_issue',
				'$where'  => 'npn in(' . implode( ',', $chunk ) . ')',
				'$group'  => 'npn',
				'$limit'  => count( $chunk ),
			) );
			if ( $found === null ) {
				continue;
			}

			$this->stats['people_checked'] += count( $chunk );
			$first                          = array();
			foreach ( $found as $row ) {
				$first[ self::text( $row, 'npn' ) ] = self::issue_date( self::text( $row, 'first_issue' ) );
			}

			foreach ( $chunk as $npn ) {
				$first_day = $first[ $npn ] ?? '';
				if ( $first_day !== '' && $first_day >= $since_day && count( $kept ) < $room ) {
					$kept[] = $npn;
				}
			}
		}

		return $kept;
	}

	/**
	 * Each NPN's earliest life licence row on or after $since, fetched directly. An NPN whose chunk fails is
	 * simply absent from the result, so the caller keeps the row it already has (still a real licence in
	 * the window, just possibly not the earliest).
	 *
	 * @param string[] $npns
	 * @return array<string,array> npn => row.
	 */
	private function earliest_rows( string $since, array $npns ): array {
		$earliest = array();
		$where    = $this->build_where( $since );

		foreach ( array_chunk( $npns, self::NPN_CHUNK ) as $chunk ) {
			if ( $this->out_of_time() ) {
				$this->stats['complete'] = false;
				break;
			}

			$rows = $this->soda( array(
				'$select' => self::ROW_FIELDS,
				'$where'  => $where . ' AND npn in(' . implode( ',', $chunk ) . ')',
				'$order'  => 'license_issue_date ASC, license_number ASC',
				'$limit'  => self::PAGE_ROWS * 2,
			) );
			if ( $rows === null ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$npn = self::npn( $row );
				if ( $npn !== '' && ! isset( $earliest[ $npn ] ) ) {
					$earliest[ $npn ] = $row;
				}
			}
		}

		return $earliest;
	}

	private function out_of_time(): bool {
		if ( microtime( true ) < $this->deadline ) {
			return false;
		}

		$this->stats['complete'] = false;
		return true;
	}

	private static function soql_quote( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	private function build_where( string $since ): string {
		$clauses = array(
			'state = ' . self::soql_quote( $this->state ),
			'qualification in(' . implode( ',', array_map( array( __CLASS__, 'soql_quote' ), self::LIFE_QUALIFICATIONS ) ) . ')',
		);
		if ( $since !== '' ) {
			$clauses[] = 'license_issue_date >= ' . self::soql_quote( $since );
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * One SODA request. A request that fails in transport or answers 429 or 5xx is tried once more;
	 * anything else that is not a JSON list of rows is a failure too, and marks the run incomplete.
	 *
	 * @return array[]|null Row objects, or null when the dataset did not answer.
	 */
	private function soda( array $args ): ?array {
		$url = self::DATASET_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			++$this->stats['requests'];
			$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT_SECONDS ) );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( $code === 200 ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $decoded ) && ( $decoded === array() || isset( $decoded[0] ) ) ) {
					return array_values( array_filter( $decoded, 'is_array' ) );
				}
				break;
			}
			if ( $code !== 0 && $code !== 429 && $code < 500 ) {
				break;
			}
			usleep( self::RETRY_PAUSE_MICROS );
		}

		$this->stats['complete'] = false;
		return null;
	}

	/**
	 * A row's fields, each cleaned and with '' for anything missing or malformed.
	 *
	 * @return array{npn:string,license:string,name:string,qualification:string,issue_date:string,city:string,postal:string}
	 */
	public static function fields( array $row ): array {
		return array(
			'npn'           => self::token( $row, 'npn' ),
			'license'       => self::token( $row, 'license_number' ),
			'name'          => self::text( $row, 'name' ),
			'qualification' => self::text( $row, 'qualification' ),
			'issue_date'    => self::issue_date( self::text( $row, 'license_issue_date' ) ),
			'city'          => self::text( $row, 'city' ),
			'postal'        => self::text( $row, 'pstl_cd' ),
		);
	}

	/**
	 * SODA floating timestamp ( 2026-10-08T00:00:00.000 ) to Y-m-d, or '' when it is not a real date.
	 */
	private static function issue_date( string $raw ): string {
		$date   = substr( $raw, 0, 10 );
		$parts  = explode( '-', $date );
		$is_ymd = count( $parts ) === 3 && ctype_digit( implode( '', $parts ) );

		return ( $is_ymd && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) ? $date : '';
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

	/**
	 * The row's NPN as digits only (it goes straight into a SoQL list), or '' when it has none.
	 */
	private static function npn( array $row ): string {
		$value = self::text( $row, 'npn' );
		return preg_match( '/^[0-9]{1,12}$/', $value ) ? $value : '';
	}
}
