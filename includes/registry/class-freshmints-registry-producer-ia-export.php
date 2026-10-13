<?php
/**
 * Reads the Iowa Insurance Division producer export ("Insurance Producers Licensed in Iowa", Iowa Data Hub
 * dataset 669, data.iowa.gov, CC BY 4.0).
 *
 * The Iowa Data Hub has no query API. It serves only whole-file exports (rows.csv, rows.json, columns.csv,
 * columns.json; a filter or limit parameter is ignored, other paths return 400 or 404). rows.csv is a zip of
 * about 52 MB holding one CSV of about 232,000 rows, and the portal answers with a redirect to a signed
 * storage URL. This class downloads it to a temp file, streams the CSV out of the zip and keeps the people
 * who can become a lead: iowa_resident = 'Y', an Iowa mailing address (state = 'IA'), the life line of
 * authority (loa_has_life = 'Yes') and a first_active_date inside the horizon (HORIZON_DAYS). Everything
 * else is dropped as it is read, so memory stays small. One row per person is kept: the file repeats an NPN
 * only to list another email address, and those addresses are collected.
 *
 * Fail closed: a transport error, a non-200 answer, a zip or CSV that cannot be read, a header missing a
 * column this class needs, a file with fewer than MIN_SOURCE_ROWS rows, or the time budget running out
 * returns null and sets stats()['error']. A partial read is never returned. A request that fails with a
 * transport error, 429 or 5xx is retried once.
 *
 * Freshmints_Registry_Producer_IA_Rows caches the result and answers windows from it.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Registry_Producer_IA_Export {

	const DOWNLOAD_URL         = 'https://idh-be.iowa.gov/api/v1/datasets/669/rows.csv';
	const DOWNLOAD_TIMEOUT     = 60;
	const MIN_DOWNLOAD_SECONDS = 8;
	const MAX_DOWNLOAD_BYTES   = 262144000;
	const MIN_SOURCE_ROWS      = 10000;
	const HORIZON_DAYS         = 400;
	const RETRY_PAUSE_MICROS   = 500000;
	const TIME_CHECK_EVERY     = 5000;
	const VERSION              = 1;

	/** Columns the parser cannot work without. A header missing one of these fails closed. */
	const REQUIRED_COLUMNS = array(
		'npn',
		'last_name',
		'first_name',
		'address_line_1',
		'city',
		'state',
		'zip_code',
		'first_active_date',
		'iowa_resident',
		'loa_has_life',
	);

	/** Line-of-authority columns and the label each one gives its line. Order is the display order. */
	const LINE_COLUMNS = array(
		'loa_has_life'           => 'Life',
		'loa_has_ah'             => 'Accident & Health',
		'loa_has_variable'       => 'Variable Life & Annuity',
		'loa_has_personal_lines' => 'Personal Lines',
		'loa_has_property'       => 'Property',
		'loa_has_casualty'       => 'Casualty',
		'loa_has_credit'         => 'Credit',
		'loa_has_crop'           => 'Crop',
		'loa_has_surety'         => 'Surety',
		'loa_has_reciprocal'     => 'Reciprocal',
		'loa_has_excess'         => 'Excess & Surplus',
	);

	/** @var float Unix time (microtime) after which no further request or parse step is started. */
	private $deadline;

	/** @var array<string,int|string> */
	private $stats = array(
		'requests'         => 0,
		'source_rows'      => 0,
		'rows_without_npn' => 0,
		'dataset_modified' => '',
		'error'            => '',
	);

	public function __construct( float $deadline ) {
		$this->deadline = $deadline;
	}

	/**
	 * HTTP requests made, rows in the file, qualifying rows skipped for having no NPN, the export's
	 * Last-Modified time as UTC ISO 8601 ('' when unknown) and `error` ('' or a short code).
	 *
	 * @return array<string,int|string>
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Download and read the export.
	 *
	 * @return array{version:int,fetched_at:int,horizon_start:string,source_rows:int,rows_without_npn:int,dataset_modified:string,people:array[]}|null
	 *         The people, newest first, or null when the export could not be read in full.
	 */
	public function fetch(): ?array {
		$tmp = wp_tempnam( 'freshmints-ia-producers.zip' );
		if ( ! $tmp ) {
			return $this->fail( 'no_temp_file' );
		}

		try {
			for ( $attempt = 1; ; $attempt++ ) {
				$left = $this->deadline - microtime( true );
				if ( $left < self::MIN_DOWNLOAD_SECONDS ) {
					return $this->fail( 'time_budget' );
				}

				++$this->stats['requests'];
				$res  = wp_safe_remote_get(
					self::DOWNLOAD_URL,
					array(
						'timeout'             => min( (float) self::DOWNLOAD_TIMEOUT, $left - 1 ),
						'redirection'         => 3,
						'stream'              => true,
						'filename'            => $tmp,
						'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
					)
				);
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

				if ( $code === 200 ) {
					$this->stats['dataset_modified'] = self::http_date_to_iso( (string) wp_remote_retrieve_header( $res, 'last-modified' ) );
					return $this->parse_zip( $tmp );
				}

				$retryable = $code === 0 || $code === 429 || $code >= 500;
				if ( ! $retryable || $attempt >= 2 ) {
					return $this->fail( $code === 0 ? 'transport' : 'http_' . $code );
				}
				usleep( self::RETRY_PAUSE_MICROS );
			}
		} finally {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Stream the CSV inside the zip.
	 */
	private function parse_zip( string $path ): ?array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->fail( 'no_zip_extension' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return $this->fail( 'unreadable_zip' );
		}

		$entry = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( strtolower( substr( $name, -4 ) ) === '.csv' ) {
				$entry = $name;
				break;
			}
		}

		$stream = $entry !== null ? $zip->getStream( $entry ) : false;
		if ( ! is_resource( $stream ) ) {
			$zip->close();
			return $this->fail( 'no_csv_in_zip' );
		}

		try {
			return $this->read_csv( $stream );
		} finally {
			fclose( $stream );
			$zip->close();
		}
	}

	/**
	 * @param resource $stream The CSV inside the zip.
	 */
	private function read_csv( $stream ): ?array {
		$header = fgetcsv( $stream, 0, ',', '"', '' );
		if ( ! is_array( $header ) ) {
			return $this->fail( 'empty_csv' );
		}
		$header[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$col       = array_flip( array_map( 'trim', $header ) );

		foreach ( self::REQUIRED_COLUMNS as $name ) {
			if ( ! isset( $col[ $name ] ) ) {
				return $this->fail( 'schema_changed' );
			}
		}

		$i_resident = $col['iowa_resident'];
		$i_state    = $col['state'];
		$i_life     = $col['loa_has_life'];

		$horizon_start = gmdate( 'Y-m-d', time() - ( self::HORIZON_DAYS * DAY_IN_SECONDS ) );
		$people        = array();
		$read          = 0;
		$no_npn        = 0;

		while ( ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) !== false ) {
			++$read;
			if ( $read % self::TIME_CHECK_EVERY === 0 && microtime( true ) > $this->deadline ) {
				return $this->fail( 'time_budget' );
			}

			if ( ( $row[ $i_resident ] ?? '' ) !== 'Y' || ( $row[ $i_state ] ?? '' ) !== 'IA' || ( $row[ $i_life ] ?? '' ) !== 'Yes' ) {
				continue;
			}

			$npn   = self::cell( $row, $col, 'npn' );
			$first = self::cell( $row, $col, 'first_active_date' );
			if ( $npn === '' ) {
				++$no_npn;
				continue;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first ) ) {
				continue;
			}

			$email = self::cell( $row, $col, 'email' );

			if ( isset( $people[ $npn ] ) ) {
				// A repeated NPN is the same person with another email address: keep one row, collect the emails.
				if ( $email !== '' && ! in_array( $email, $people[ $npn ]['emails'], true ) ) {
					$people[ $npn ]['emails'][] = $email;
				}
				if ( $first < $people[ $npn ]['first_active_date'] ) {
					$people[ $npn ]['first_active_date'] = $first;
				}
				continue;
			}

			$lines = array();
			foreach ( self::LINE_COLUMNS as $name => $label ) {
				if ( self::cell( $row, $col, $name ) === 'Yes' ) {
					$lines[] = $label;
				}
			}

			$people[ $npn ] = array(
				'npn'               => $npn,
				'first_name'        => self::cell( $row, $col, 'first_name' ),
				'last_name'         => self::cell( $row, $col, 'last_name' ),
				'address_line_1'    => self::cell( $row, $col, 'address_line_1' ),
				'address_line_2'    => self::cell( $row, $col, 'address_line_2' ),
				'city'              => self::cell( $row, $col, 'city' ),
				'zip_code'          => self::cell( $row, $col, 'zip_code' ),
				'first_active_date' => $first,
				'business_phone'    => self::cell( $row, $col, 'business_phone' ),
				'emails'            => $email !== '' ? array( $email ) : array(),
				'lines'             => $lines,
			);
		}

		$this->stats['source_rows']      = $read;
		$this->stats['rows_without_npn'] = $no_npn;

		if ( $read < self::MIN_SOURCE_ROWS ) {
			return $this->fail( 'file_too_short' );
		}

		$people = array_values(
			array_filter(
				$people,
				static function ( array $p ) use ( $horizon_start ): bool {
					return $p['first_active_date'] >= $horizon_start;
				}
			)
		);
		usort(
			$people,
			static function ( array $a, array $b ): int {
				return array( $b['first_active_date'], (int) $b['npn'] ) <=> array( $a['first_active_date'], (int) $a['npn'] );
			}
		);

		return array(
			'version'          => self::VERSION,
			'fetched_at'       => time(),
			'horizon_start'    => $horizon_start,
			'source_rows'      => $read,
			'rows_without_npn' => $no_npn,
			'dataset_modified' => (string) $this->stats['dataset_modified'],
			'people'           => $people,
		);
	}

	/**
	 * One cell, trimmed and cleaned of invalid UTF-8; '' when the column or the cell is missing.
	 */
	private static function cell( array $row, array $col, string $name ): string {
		if ( ! isset( $col[ $name ], $row[ $col[ $name ] ] ) ) {
			return '';
		}

		return trim( (string) wp_check_invalid_utf8( (string) $row[ $col[ $name ] ], true ) );
	}

	/** Record why nothing was read. Always returns null so a caller can `return $this->fail( ... )`. */
	private function fail( string $code ): ?array {
		$this->stats['error'] = $code;
		return null;
	}

	/** An HTTP date header as a UTC ISO 8601 string; '' when it is missing or unreadable. */
	private static function http_date_to_iso( string $header ): string {
		$time = $header !== '' ? strtotime( $header ) : false;

		return $time !== false ? gmdate( 'c', $time ) : '';
	}
}
