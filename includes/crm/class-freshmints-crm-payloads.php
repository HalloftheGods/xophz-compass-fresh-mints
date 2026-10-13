<?php
/**
 * What the HighLevel webhook receives for the two server-side events. Pure functions: no HTTP, no
 * database, no options, so what leaves the site can be read here in one place.
 *
 *   agent_finder_new_agent  one newly licensed agent from the Agent Finder sync
 *   lead_magnet_opt_in      one captured Lead Magnet lead
 *
 * Consent is part of every payload. Agent Finder people never agreed to calls or texts, so they go out
 * with consent_given false, no contact channels and the tag `no_consent_yet`; HighLevel workflows must
 * not auto-call or auto-text a contact carrying it. A lead goes out with exactly the channels it
 * consented to (`contact_channels` as stored) and one `consent_*` tag per channel, never an extra one.
 * Tags travel inside `data.tags`, next to the contact fields.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Crm_Payloads {

	const EVENT_AGENT = 'agent_finder_new_agent';
	const EVENT_LEAD  = 'lead_magnet_opt_in';

	/** The only contact channels a lead can carry. Anything else in a stored record is dropped. */
	const CHANNELS = array( 'email', 'phone', 'sms' );

	/** The attribution keys the Lead Magnet capture stores from the landing URL. */
	const ATTRIBUTION = array( 'utm_source', 'utm_medium', 'utm_campaign', 'fm_thread', 'fm_reply' );

	/**
	 * @param array $row A row of the Agent Finder agents table.
	 * @return array The `data` object of an agent_finder_new_agent event.
	 */
	public static function agent( array $row ): array {
		$annuity = (int) ( $row['annuity_capable'] ?? 0 ) === 1;

		return array(
			'agent_id'         => (int) ( $row['id'] ?? 0 ),
			'name'             => (string) ( $row['name'] ?? '' ),
			'npn'              => (string) ( $row['npn'] ?? '' ),
			'license_type'     => (string) ( $row['license_type'] ?? '' ),
			'qualification'    => (string) ( $row['qualification'] ?? '' ),
			'annuity_capable'  => $annuity,
			'first_issue_date' => (string) ( $row['first_issue_date'] ?? '' ),
			'city'             => (string) ( $row['city'] ?? '' ),
			'state'            => (string) ( $row['state'] ?? '' ),
			'zip'              => (string) ( $row['zip'] ?? '' ),
			'source_label'     => (string) ( $row['source_label'] ?? '' ),
			'consent_given'    => false,
			'contact_channels' => array(),
			'tags'             => array( 'agent_finder', $annuity ? 'annuity_capable' : 'not_annuity_capable', 'no_consent_yet' ),
		);
	}

	/**
	 * @param array $lead A stored Lead Magnet record (Lead_Magnet_Leads::get()).
	 * @return array The `data` object of a lead_magnet_opt_in event.
	 */
	public static function lead( array $lead ): array {
		$channels = self::channels( $lead['contact_channels'] ?? array() );
		$email    = (string) ( $lead['email'] ?? '' );
		$offer    = (string) ( $lead['offer'] ?? '' );
		$can_call = in_array( 'phone', $channels, true ) || in_array( 'sms', $channels, true );

		$data = array(
			'lead_id'          => (int) ( $lead['id'] ?? 0 ),
			'name'             => (string) ( $lead['name'] ?? '' ),
			'email'            => $email,
			// A number is sent only with a phone or text channel. Without one, none goes to the CRM.
			'phone'            => $can_call ? (string) ( $lead['phone'] ?? '' ) : '',
			'state'            => (string) ( $lead['state'] ?? '' ),
			'question'         => (string) ( $lead['question'] ?? '' ),
			'offer'            => $offer,
			'consent_given'    => ! empty( $lead['consent_given'] ),
			'contact_channels' => $channels,
			'consent_text'     => (string) ( $lead['consent_text'] ?? '' ),
			'consent_version'  => (string) ( $lead['consent_version'] ?? '' ),
			'captured_at'      => (string) ( $lead['captured_at'] ?? '' ),
		);

		foreach ( self::ATTRIBUTION as $key ) {
			$data[ $key ] = (string) ( $lead[ $key ] ?? '' );
		}

		$data['tags'] = self::lead_tags( $email, $offer, $channels );

		return $data;
	}

	/**
	 * lead_magnet and the offer, consent_email whenever there is an email, consent_phone and consent_sms
	 * only for a channel the lead agreed to.
	 *
	 * The offer comes from the posted form, so a visitor chooses its text. It is tagged `offer_<slug>`:
	 * a bare slug could be made to read `consent_sms` and switch on texting the lead never agreed to.
	 *
	 * @param string[] $channels Already filtered through channels().
	 * @return string[]
	 */
	private static function lead_tags( string $email, string $offer, array $channels ): array {
		$tags = array( 'lead_magnet' );
		if ( $offer !== '' ) {
			$tags[] = 'offer_' . $offer;
		}
		if ( $email !== '' ) {
			$tags[] = 'consent_email';
		}
		foreach ( array( 'phone', 'sms' ) as $channel ) {
			if ( in_array( $channel, $channels, true ) ) {
				$tags[] = 'consent_' . $channel;
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/** The stored channels in their stored order, keeping only the known ones. */
	private static function channels( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( $stored, static function ( $channel ) {
			return is_string( $channel ) && in_array( $channel, self::CHANNELS, true );
		} ) ) );
	}
}
