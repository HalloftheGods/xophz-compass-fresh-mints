<?php
/**
 * Maps one registry lead (Freshmints_Registry_Producers::fetch()) to the row the Agent Finder store keeps.
 *
 * Values are the registry's own; nothing is filled in or invented. The mapper reads the lead's `registry`
 * block, which both state tiers build, and a few top-level lead keys:
 *
 *   - first_issue_date: registry `firstIssueDate` (the person's first licence of any line) when the tier
 *     gave one, else the lead's `issueDate`. Texas leads carry both and they differ for the few people whose
 *     first Texas licence was not a life line; Iowa has one date per person, so `issueDate` is already it.
 *   - annuity_capable: registry `annuityCapable` when present (Iowa decides it from the life line of
 *     authority), else true when any life qualification the person holds in the window is one of
 *     ANNUITY_QUALIFICATIONS (Texas; the #6021 decision). The registry's `qualifications` list is the
 *     person's whole record in the window; the single `qualification` is the one row the lead was built from.
 *   - registry_contact: the contact details the State publishes (Iowa: business email, phone, address) as
 *     JSON. Public business contact is not consent to be contacted.
 *
 * A lead with no NPN (the key a person is stored under) or no name has no agent.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Agent_Finder_Mapper {

	/** The Texas qualifications that carry annuity authority (decision on #6021); every other life line is stored with annuity_capable = 0. */
	const ANNUITY_QUALIFICATIONS = array(
		'Life, Accident, Health & HMO',
		'Life Agent/Agency',
	);

	/**
	 * @param mixed  $lead  A lead from Freshmints_Registry_Producers::fetch().
	 * @param string $state The state that fetch was for; it picks the source label.
	 * @return array<string,mixed>|null
	 */
	public static function agent_from_lead( $lead, string $state ): ?array {
		if ( ! is_array( $lead ) ) {
			return null;
		}
		$registry = is_array( $lead['registry'] ?? null ) ? $lead['registry'] : array();
		$npn      = self::fit( $registry['npn'] ?? '', 32 );
		$name     = self::fit( $lead['fullName'] ?? '', 255 );
		if ( $npn === '' || $name === '' ) {
			return null;
		}

		$described = Freshmints_Registry_Producers::describe( $state );
		$first     = self::fit( $registry['firstIssueDate'] ?? '', 10 );

		return array(
			'npn'              => $npn,
			'name'             => $name,
			'license_type'     => self::fit( $registry['licenseType'] ?? '', 100 ),
			'qualification'    => self::fit( $registry['qualification'] ?? '', 100 ),
			'annuity_capable'  => self::annuity_capable( $registry ),
			'first_issue_date' => $first !== '' ? $first : self::fit( $lead['issueDate'] ?? '', 10 ),
			'city'             => self::fit( $lead['city'] ?? '', 100 ),
			'state'            => self::fit( $lead['state'] ?? '', 2 ),
			'zip'              => self::fit( $registry['postalCode'] ?? '', 16 ),
			'source_label'     => self::fit( $described['source'] ?? '', 191 ),
			'registry_contact' => self::contact_json( $lead['registryContact'] ?? null ),
		);
	}

	private static function annuity_capable( array $registry ): bool {
		if ( is_bool( $registry['annuityCapable'] ?? null ) ) {
			return $registry['annuityCapable'];
		}

		$held = is_array( $registry['qualifications'] ?? null ) ? $registry['qualifications'] : array();
		if ( ! $held ) {
			$held = array( $registry['qualification'] ?? '' );
		}

		foreach ( $held as $qualification ) {
			if ( in_array( self::fit( $qualification, 100 ), self::ANNUITY_QUALIFICATIONS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The published contact details (email, emails, phone, address, source) as JSON, or '' when there are none.
	 * Only those keys and only text; nothing is added.
	 */
	private static function contact_json( $contact ): string {
		if ( ! is_array( $contact ) ) {
			return '';
		}

		$clean = array();
		foreach ( array( 'email', 'phone', 'address', 'source' ) as $key ) {
			$value = self::fit( $contact[ $key ] ?? '', 255 );
			if ( $value !== '' ) {
				$clean[ $key ] = $value;
			}
		}

		$emails = array();
		foreach ( is_array( $contact['emails'] ?? null ) ? $contact['emails'] : array() as $email ) {
			$email = self::fit( $email, 191 );
			if ( $email !== '' ) {
				$emails[] = $email;
			}
		}
		if ( $emails ) {
			$clean['emails'] = $emails;
		}

		$json = $clean ? wp_json_encode( $clean ) : '';
		return is_string( $json ) ? $json : '';
	}

	/** A scalar as a trimmed string cut to $max characters; anything else is ''. */
	private static function fit( $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( trim( (string) $value ), 0, $max ) : '';
	}
}
