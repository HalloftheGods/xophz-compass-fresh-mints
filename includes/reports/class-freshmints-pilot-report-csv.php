<?php
/**
 * The billable-leads CSV for the invoice: name, email, phone, state, created, source platform,
 * thread URL, one row per billable lead.
 *
 * Every lead field is text a visitor typed or a URL parameter someone controls, and the owner opens
 * this file in a spreadsheet. A cell that starts with =, +, - or @ (or a tab or carriage return) can
 * run as a formula, so it is written with a leading quote and the spreadsheet shows it as text. The
 * one exception is a phone number that already matches +1 and ten digits, which cannot hold a
 * formula and must stay a phone number.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Pilot_Report_Csv {

	const COLUMNS = array( 'name', 'email', 'phone', 'state', 'created', 'source_platform', 'thread_url' );

	/**
	 * @param array<int, array<string, string>> $leads Rows from Freshmints_Pilot_Report_Leads::summarize()['billable'].
	 */
	public static function build( array $leads ): string {
		$stream = fopen( 'php://temp', 'r+' );
		fputcsv( $stream, self::COLUMNS, ',', '"', '' );

		foreach ( $leads as $lead ) {
			$row = array();
			foreach ( self::COLUMNS as $column ) {
				$row[] = self::cell( $lead[ $column ] ?? '', 'phone' === $column );
			}
			fputcsv( $stream, $row, ',', '"', '' );
		}

		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream );

		return $csv;
	}

	/**
	 * One cell, with formula-leading text neutralized.
	 *
	 * @param mixed $value
	 */
	public static function cell( $value, bool $is_phone = false ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( $is_phone && 1 === preg_match( Freshmints_Pilot_Report_Leads::PHONE_PATTERN, $value ) ) {
			return $value;
		}

		$first    = $value[0];
		$trimmed  = ltrim( $value, " \t\r\n" );
		$triggers = array( '=', '+', '-', '@' );
		$is_risk  = in_array( $first, array( "\t", "\r" ), true ) || ( '' !== $trimmed && in_array( $trimmed[0], $triggers, true ) );

		return $is_risk ? "'" . $value : $value;
	}
}
