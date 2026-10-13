<?php
/**
 * One calendar month of the pilot report, bounded in the site's timezone.
 *
 * "October" means October on the owner's clock, not in UTC, so the window runs from midnight on
 * the 1st to midnight on the 1st of the next month in the site timezone (Settings > General). The
 * window is then converted to UTC, because the stored times are UTC: events as DATETIME, Lead
 * Magnet captures as ISO 8601 strings ending in Z.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Report_Range {

	const MONTH_PATTERN = '/^(\d{4})-(0[1-9]|1[0-2])$/';
	const MIN_YEAR      = 2020;
	const MAX_YEAR      = 2100;

	/** 'YYYY-MM'. */
	public $month;

	/** First instant of the month, site timezone. */
	public $start;

	/** First instant of the next month, site timezone. Exclusive. */
	public $end;

	private function __construct( string $month, DateTimeImmutable $start ) {
		$this->month = $month;
		$this->start = $start;
		$this->end   = $start->modify( '+1 month' );
	}

	/**
	 * @param mixed $value 'YYYY-MM', or null/'' for the current month on the site's clock.
	 * @return self|null null when the value is not a month in the supported years.
	 */
	public static function parse( $value ): ?self {
		$zone = wp_timezone();
		if ( null === $value || '' === $value ) {
			$value = ( new DateTimeImmutable( 'now', $zone ) )->format( 'Y-m' );
		}

		$matches = array();
		if ( ! is_string( $value ) || 1 !== preg_match( self::MONTH_PATTERN, $value, $matches ) ) {
			return null;
		}

		$year = (int) $matches[1];
		if ( $year < self::MIN_YEAR || $year > self::MAX_YEAR ) {
			return null;
		}

		return new self( $value, new DateTimeImmutable( $value . '-01 00:00:00', $zone ) );
	}

	/** Inclusive start as UTC 'Y-m-d H:i:s', the form the events table stores. */
	public function from_utc(): string {
		return $this->utc( $this->start, 'Y-m-d H:i:s' );
	}

	/** Exclusive end as UTC 'Y-m-d H:i:s'. */
	public function to_utc(): string {
		return $this->utc( $this->end, 'Y-m-d H:i:s' );
	}

	/** Inclusive start in the form Lead Magnet stores as captured_at (UTC, ends in Z). */
	public function from_captured(): string {
		return $this->utc( $this->start, 'Y-m-d\TH:i:s\Z' );
	}

	/** Exclusive end in the captured_at form. */
	public function to_captured(): string {
		return $this->utc( $this->end, 'Y-m-d\TH:i:s\Z' );
	}

	/** What the report echoes back so the reader sees exactly which window was counted. */
	public function to_array(): array {
		return array(
			'from'     => $this->start->format( 'c' ),
			'to'       => $this->end->format( 'c' ),
			'timezone' => $this->start->getTimezone()->getName(),
		);
	}

	private function utc( DateTimeImmutable $moment, string $format ): string {
		return $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
	}
}
