<?php
/**
 * POST /gemini/generate (manage_options): outreach copy through Gemini, and skip-trace enrichment
 * from Google Places, Yelp and the practice website.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';
require_once __DIR__ . '/class-freshmints-api-website-scraper.php';

class Freshmints_Api_Gemini extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/gemini/generate', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_gemini_generate' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	/**
	 * Call Gemini Generative AI REST API directly
	 */
	private function call_gemini_api( $prompt, $system_instruction = '', $tools = array(), $model = 'gemini-3.7-flash' ) {
		$api_key = $this->get_gemini_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_gemini_key', 'Gemini API key is not configured in WP Connectors or environment.' );
		}

		// The key rides in the x-goog-api-key header, never in the URL, so it cannot reach
		// server, proxy or access logs through the request line.
		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

		$payload = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
		);

		if ( empty( $tools ) ) {
			$payload['generationConfig'] = array(
				'responseMimeType' => 'application/json',
			);
		}

		if ( ! empty( $system_instruction ) ) {
			$payload['system_instruction'] = array(
				'parts' => array(
					array( 'text' => $system_instruction ),
				),
			);
		}

		if ( ! empty( $tools ) ) {
			$payload['tools'] = $tools;
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$raw_text = trim( $body['candidates'][0]['content']['parts'][0]['text'] );
			$clean    = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw_text );
			if ( preg_match( '/\{[\s\S]*\}/', $clean, $matches ) ) {
				$decoded = json_decode( $matches[0], true );
				if ( $decoded ) {
					return $decoded;
				}
			}
			return json_decode( $clean, true ) ?: $raw_text;
		}

		return $body;
	}

	/**
	 * Handle AI Generation (Outreach Pitch, SMS, Skip Trace Grounding, Site Copy)
	 */
	public function handle_gemini_generate( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$action  = $body['action'] ?? '';
		$payload = $body['payload'] ?? array();

		if ( $action === 'generateOutreach' ) {
			$name         = $payload['name'] ?? 'Professional';
			$profession   = $payload['profession'] ?? 'Licensed Professional';
			$city         = $payload['city'] ?? 'Metro Area';
			$state        = $payload['state'] ?? 'CA';
			$licenseDate  = $payload['licenseDate'] ?? 'Recently Issued Active Board Pass';
			$clientType   = $payload['clientType'] ?? 'ideal local clients and patients';
			$offerPrice   = $payload['offerPrice'] ?? '1650';
			$monthlyRate  = $payload['monthlyRate'] ?? '34.99';
			$websiteUrl   = $payload['websiteUrl'] ?? 'https://worldwidewebwork.com/preview';

			$prompt = "You are a senior practice launch advisor at \"My Compass Consulting\", reaching out to a newly licensed practitioner.
Generate an industry-tailored cold email pitch and matching SMS text message for:
- Professional Name: {$name}
- Industry / Profession: {$profession}
- Location: {$city}, {$state}
- License Registry Date: {$licenseDate}
- Target Client Audience: {$clientType}
- Turnkey Website Package: Flat \${$offerPrice} total (covers build, custom .com domain, and 24 FULL MONTHS of high-speed w4 cloud hosting & SSL encryption with \$0 monthly hosting bills for 2 full years)
- Continuity: Continues at standard base rate of \${$monthlyRate}/mo with no lock-in contracts
- Domain Equity Buyout Clause: Guaranteed unencumbered \$999 lease-to-own domain transfer option
- Live Preview URL: {$websiteUrl}

Return JSON strictly matching this structure:
{
  \"subject\": \"String\",
  \"emailBody\": \"String formatted with natural paragraph breaks\",
  \"smsBody\": \"String under 160 characters\",
  \"suggestedFollowUpDays\": 3,
  \"industryAngleSummary\": \"One sentence summary of the pitch hook\"
}";

			$result = $this->call_gemini_api( $prompt );
			if ( is_wp_error( $result ) ) {
				return rest_ensure_response( array( 'success' => false, 'error' => $result->get_error_message() ) );
			}
			return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
		}

		if ( $action === 'skipTraceEnrichment' ) {
			$name          = $payload['name'] ?? '';
			$profession    = $payload['profession'] ?? '';
			$city          = $payload['city'] ?? '';
			$state         = $payload['state'] ?? '';
			$licenseNumber = $payload['licenseNumber'] ?? '';

			$phone            = '';
			$phone_type       = 'Unknown';
			$phone_source     = '';
			$email            = '';
			$secondary_email  = '';
			$linkedin         = '';
			$website          = '';
			$address          = "{$city}, {$state}";
			$sources_found    = array();
			$extracted_emails = array();
			$extracted_phones = array();

			// Tier 1: Authoritative Google Places API (Text Search + Place Details)
			$google_key = $this->get_api_key( 'google' );
			if ( ! empty( $google_key ) ) {
				$query = urlencode( "{$name} {$profession} {$city} {$state}" );
				$url   = "https://maps.googleapis.com/maps/api/place/textsearch/json?query={$query}&key={$google_key}";
				$res   = wp_remote_get( $url, array( 'timeout' => 15 ) );

				if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
					$data  = json_decode( wp_remote_retrieve_body( $res ), true );
					$place = $data['results'][0] ?? null;

					if ( $place ) {
						$place_id    = $place['place_id'];
						$details_url = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields=formatted_phone_number,website,url,formatted_address&key={$google_key}";
						$det_res     = wp_remote_get( $details_url, array( 'timeout' => 15 ) );

						if ( ! is_wp_error( $det_res ) && wp_remote_retrieve_response_code( $det_res ) === 200 ) {
							$det_data = json_decode( wp_remote_retrieve_body( $det_res ), true );
							$details  = $det_data['result'] ?? array();

							if ( ! empty( $details['formatted_phone_number'] ) ) {
								$phone           = $details['formatted_phone_number'];
								$phone_type      = 'Google Business Line';
								$phone_source    = 'Google Places';
								$sources_found[] = 'Google Places API';
							}

							if ( ! empty( $details['website'] ) ) {
								$raw_site = trim( $details['website'] );
								// Exclude directories from standalone website classification
								$blacklist = array( 'yelp.com', 'healthgrades.com', 'zocdoc.com', 'linkedin.com', 'facebook.com', 'vitals.com', 'webmd.com', 'realtor.com', 'zillow.com' );
								$is_dir    = false;
								foreach ( $blacklist as $b_dom ) {
									if ( strpos( strtolower( $raw_site ), $b_dom ) !== false ) {
										$is_dir = true;
										break;
									}
								}
								if ( ! $is_dir ) {
									$website = $raw_site;
								}
							}

							if ( ! empty( $details['formatted_address'] ) ) {
								$address = $details['formatted_address'];
							}
						}
					}
				}
			}

			// Tier 2: Yelp Fusion API Directory Redundancy Fallback
			$yelp_key = $this->get_api_key( 'yelp' );
			if ( empty( $phone ) && ! empty( $yelp_key ) ) {
				$term     = urlencode( "{$name} {$profession}" );
				$location = urlencode( "{$city}, {$state}" );
				$yelp_url = "https://api.yelp.com/v3/businesses/search?term={$term}&location={$location}&limit=1";
				$yelp_res = wp_remote_get( $yelp_url, array(
					'headers' => array( 'Authorization' => "Bearer {$yelp_key}" ),
					'timeout' => 15,
				) );

				if ( ! is_wp_error( $yelp_res ) && wp_remote_retrieve_response_code( $yelp_res ) === 200 ) {
					$yelp_data = json_decode( wp_remote_retrieve_body( $yelp_res ), true );
					$biz       = $yelp_data['businesses'][0] ?? null;

					if ( $biz && ( ! empty( $biz['display_phone'] ) || ! empty( $biz['phone'] ) ) ) {
						$phone           = ! empty( $biz['display_phone'] ) ? $biz['display_phone'] : $biz['phone'];
						$phone_type      = 'Yelp Directory Line';
						$phone_source    = 'Yelp';
						$sources_found[] = 'Yelp Fusion API';

						if ( ! empty( $biz['location']['address1'] ) ) {
							$address = trim( ( $biz['location']['address1'] ?? '' ) . ', ' . ( $biz['location']['city'] ?? $city ) . ', ' . ( $biz['location']['state'] ?? $state ) );
						}
					}
				}
			}

			// Tier 3: Website Crawl & Contact Scraper
			$email_permutations = array();
			$mx_valid           = false;
			$mx_records         = array();
			$schema_data        = null;

			if ( ! empty( $website ) ) {
				$scraped            = Freshmints_Api_Website_Scraper::scrape_website_contact_info( $website, $name );
				$extracted_emails   = $scraped['emails'] ?? array();
				$extracted_phones   = $scraped['phones'] ?? array();
				$email_permutations = $scraped['emailPermutations'] ?? array();
				$mx_valid           = $scraped['mxValid'] ?? false;
				$mx_records         = $scraped['mxRecords'] ?? array();
				$schema_data        = $scraped['schemaOrgData'] ?? null;

				if ( ! empty( $scraped['socialProfilesFound'] ) ) {
					$sources_found = array_merge( $sources_found, $scraped['socialProfilesFound'] );
				}

				if ( ! empty( $scraped['primaryEmail'] ) ) {
					$email            = $scraped['primaryEmail'];
					$secondary_email  = $scraped['emails'][1] ?? '';
					$sources_found[]  = 'Website Contact Scraper (' . ( parse_url( $website, PHP_URL_HOST ) ?: $website ) . ')';
				}

				if ( empty( $phone ) && ! empty( $scraped['primaryPhone'] ) ) {
					$phone           = $scraped['primaryPhone'];
					$phone_type      = 'Website Scraped Line';
					$phone_source    = 'Website (' . ( parse_url( $website, PHP_URL_HOST ) ?: $website ) . ')';
					$sources_found[] = 'Website Phone Scraper';
				}
			}

			$notes = ! empty( $sources_found )
				? ( 'Contact details found via ' . implode( ' + ', array_unique( $sources_found ) ) . '. Not independently verified.' )
				: 'No contact record found in the sources checked. (Deterministic API lookup - zero synthetic generation).';

			return rest_ensure_response( array(
				'success' => true,
				'data'    => array(
					'verifiedPhone'     => $phone,
					'phoneType'         => ! empty( $phone ) ? $phone_type : 'Unknown',
					'phoneSource'       => ! empty( $phone ) ? $phone_source : '',
					// No DNC or carrier lookup runs here, so the status is always unknown.
					'dncStatus'         => 'Unknown',
					'primaryEmail'      => $email,
					'secondaryEmail'    => $secondary_email,
					'websiteUrl'        => $website,
					'extractedEmails'   => $extracted_emails,
					'extractedPhones'   => $extracted_phones,
					'emailPermutations' => $email_permutations,
					'mxValid'           => $mx_valid,
					'mxRecords'         => $mx_records,
					'schemaOrgData'     => $schema_data,
					'emailValidation'   => ! empty( $email ) ? 'Scraped from website, unverified' : '',
					'linkedInUrl'       => $linkedin,
					'currentAddress'    => $address,
					'enrichmentNotes'   => $notes,
				),
			) );
		}

		return new WP_Error( 'invalid_action', 'Invalid generation action specified.', array( 'status' => 400 ) );
	}
}
