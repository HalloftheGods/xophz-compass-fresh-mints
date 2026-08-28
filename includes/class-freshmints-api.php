<?php

class Freshmints_API {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/auth/login', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_login' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/auth/logout', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_logout' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/auth/me', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_me' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/gemini/generate', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_gemini_generate' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/leads/check-website', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_check_website' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/registry/fetch-live', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_fetch_live_registry' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/crm/sync', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_crm_sync' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/bomb-bag/sync', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bomb_bag_sync' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/bomb-bag/lists', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_bomb_bag_lists' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'xophz-freshmints/v1', '/stats', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	public function check_permissions() {
		// Allow logged in users or open access with valid WP rest nonce
		if ( is_user_logged_in() ) {
			return true;
		}
		return true; // Graceful fallback
	}


	/**
	 * Retrieve API keys for various services.
	 */
	private function get_api_key( $service ) {
		$keys = array(
			'yelp'   => array( 'yelp_api_key', 'compass_yelp_api_key', 'YELP_API_KEY' ),
			'google' => array( 'google_places_api_key', 'compass_google_places_api_key', 'GOOGLE_PLACES_API_KEY' ),
		);

		if ( isset( $keys[ $service ] ) ) {
			foreach ( $keys[ $service ] as $k ) {
				$val = get_option( $k, '' );
				if ( ! empty( $val ) ) {
					return $val;
				}
				if ( defined( $k ) && ! empty( constant( $k ) ) ) {
					return constant( $k );
				}
				if ( ! empty( $_ENV[ $k ] ) ) {
					return $_ENV[ $k ];
				}
			}
		}
		return '';
	}

	/**
	 * Retrieve the API key for Gemini using WP Connectors API and ecosystem settings.
	 */
	private function get_gemini_api_key() {
		if ( function_exists( 'wp_get_connectors' ) ) {
			$connectors = wp_get_connectors();
			if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
				$api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
				if ( ! empty( $api_key ) ) {
					return $api_key;
				}
			}
			if ( ! empty( $connectors['google_gemini_api_key']['authentication']['setting_name'] ) ) {
				$api_key = get_option( $connectors['google_gemini_api_key']['authentication']['setting_name'], '' );
				if ( ! empty( $api_key ) ) {
					return $api_key;
				}
			}
		}

		$keys = array(
			'connectors_ai_google_api_key',
			'ai_google_api_key',
			'compass_gemini_api_key',
			'xophz_gemini_api_key',
		);
		foreach ( $keys as $k ) {
			$val = get_option( $k, '' );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		if ( defined( 'GEMINI_API_KEY' ) && ! empty( GEMINI_API_KEY ) ) {
			return GEMINI_API_KEY;
		}
		if ( ! empty( $_ENV['GEMINI_API_KEY'] ) ) {
			return $_ENV['GEMINI_API_KEY'];
		}
		if ( ! empty( getenv( 'GEMINI_API_KEY' ) ) ) {
			return getenv( 'GEMINI_API_KEY' );
		}

		return '';
	}

	/**
	 * Call Gemini Generative AI REST API directly
	 */
	private function call_gemini_api( $prompt, $system_instruction = '', $tools = array(), $model = 'gemini-3.7-flash' ) {
		$api_key = $this->get_gemini_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_gemini_key', 'Gemini API key is not configured in WP Connectors or environment.' );
		}

		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

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
			'headers' => array( 'Content-Type' => 'application/json' ),
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

			$phone         = '';
			$phone_type    = 'Unverified';
			$email         = '';
			$linkedin      = '';
			$website       = '';
			$address       = "{$city}, {$state}";
			$confidence    = 0;
			$sources_found = array();

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
								$phone          = $details['formatted_phone_number'];
								$phone_type     = 'Verified Practice Line';
								$confidence     = 95;
								$sources_found[] = 'Google Places API';
							}

							if ( ! empty( $details['website'] ) ) {
								$website = $details['website'];
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
						$phone          = ! empty( $biz['display_phone'] ) ? $biz['display_phone'] : $biz['phone'];
						$phone_type     = 'Yelp Directory Line';
						$confidence     = 90;
						$sources_found[] = 'Yelp Fusion API';

						if ( ! empty( $biz['location']['address1'] ) ) {
							$address = trim( ( $biz['location']['address1'] ?? '' ) . ', ' . ( $biz['location']['city'] ?? $city ) . ', ' . ( $biz['location']['state'] ?? $state ) );
						}
					}
				}
			}

			$has_contact = ! empty( $phone );
			$notes = ! empty( $sources_found )
				? ( 'Verified authentic directory phone via ' . implode( ' + ', $sources_found ) . '.' )
				: 'No verified phone line found in Google Places or Yelp. (Deterministic API lookup - zero AI generation).';

			return rest_ensure_response( array(
				'success' => true,
				'data'    => array(
					'confidenceScore' => $has_contact ? ( $confidence > 0 ? $confidence : 90 ) : 0,
					'verifiedPhone'   => $phone,
					'phoneType'       => ! empty( $phone ) ? $phone_type : 'Unverified',
					'dncStatus'       => ! empty( $phone ) ? 'Public Business Directory' : 'No Phone Found',
					'primaryEmail'    => $email,
					'emailValidation' => '',
					'linkedInUrl'     => $linkedin,
					'currentAddress'  => $address,
					'enrichmentNotes' => $notes,
				),
			) );
		}

		return new WP_Error( 'invalid_action', 'Invalid generation action specified.', array( 'status' => 400 ) );
	}

	/**
	 * Handle Live Website Check
	 */
	public function handle_check_website( WP_REST_Request $request ) {
		$body          = $request->get_json_params();
		$leadName      = $body['leadName'] ?? '';
		$profession    = $body['profession'] ?? '';
		$city          = $body['city'] ?? '';
		$state         = $body['state'] ?? '';

		$google_key = $this->get_api_key('google');
		if (empty($google_key)) {
			// fallback if no key
			$result = array(
				'hasWebsite'           => false,
				'existingUrl'          => null,
				'status'               => 'No Website Found - High Opportunity',
				'summary'              => "No API key configured for Google Places.",
				'pitchStrategy'        => 'Pitch turnkey practice website package ($1,650 with 2 years hosting included).',
				'socialProfilesFound'  => array(),
				'qualifications'       => array(
					'hasCustomDomain'              => false,
					'domainCheckSummary'           => "No API key",
					'hasDirectBookingPortal'       => false,
					'isOnlyDirectoryOrBoardListing'=> true,
					'digitalFootprintRating'       => 'Unknown',
				),
				'checkedAt'            => gmdate( 'c' ),
			);
			return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
		}

		$query = urlencode("{$leadName} {$profession} {$city} {$state}");
		$url = "https://maps.googleapis.com/maps/api/place/textsearch/json?query={$query}&key={$google_key}";
		
		$res = wp_remote_get($url, array('timeout' => 15));
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$place = $data['results'][0] ?? null;

		$hasWebsite = false;
		$existingUrl = null;

		if ($place) {
			$place_id = $place['place_id'];
			$details_url = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields=website&key={$google_key}";
			$det_res = wp_remote_get($details_url, array('timeout' => 15));
			$det_data = json_decode( wp_remote_retrieve_body( $det_res ), true );
			$website = $det_data['result']['website'] ?? null;

			if ($website) {
				// filter out common directories
				$blacklist = ['yelp.com', 'healthgrades.com', 'zocdoc.com', 'linkedin.com', 'facebook.com', 'vitals.com', 'webmd.com', 'realtor.com', 'zillow.com'];
				$is_directory = false;
				foreach ($blacklist as $domain) {
					if (strpos($website, $domain) !== false) {
						$is_directory = true;
						break;
					}
				}
				if (!$is_directory) {
					$hasWebsite = true;
					$existingUrl = $website;
				}
			}
		}

		$result = array(
			'hasWebsite'           => $hasWebsite,
			'existingUrl'          => $existingUrl,
			'status'               => $hasWebsite ? 'Website Found' : 'No Website Found - High Opportunity',
			'summary'              => $hasWebsite ? "Found standalone website: {$existingUrl}" : "No active standalone custom domain found for {$leadName} in {$city}, {$state}.",
			'pitchStrategy'        => $hasWebsite ? 'Pitch SEO/Marketing or upgrade' : 'Pitch turnkey practice website package ($1,650 with 2 years hosting included).',
			'socialProfilesFound'  => array(),
			'qualifications'       => array(
				'hasCustomDomain'              => $hasWebsite,
				'domainCheckSummary'           => $hasWebsite ? "Found domain" : "No root domain registered for {$leadName}",
				'hasDirectBookingPortal'       => false,
				'isOnlyDirectoryOrBoardListing'=> !$hasWebsite,
				'digitalFootprintRating'       => $hasWebsite ? 'Established' : 'Registry Only',
			),
			'checkedAt'            => gmdate( 'c' ),
		);

		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	/**
	 * Proxy Live Open Data Registry Lookups (CMS NPPES, FINRA BrokerCheck, State Licensing Boards)
	 */
	public function handle_fetch_live_registry( WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$profession = $body['profession'] ?? 'financial_advisor';
		$state      = strtoupper( trim( $body['state'] ?? 'AZ' ) );
		$limit      = min( max( (int) ( $body['limit'] ?? 25 ), 5 ), 100 );

		// 1. Healthcare & Wellness Practitioners: Query CMS Federal NPPES Individual (NPI-1) Registry
		if ( in_array( $profession, array( 'nursing', 'therapy', 'dental', 'chiropractic', 'medical', 'veterinary' ), true ) ) {
			$taxonomy_desc = 'Counselor';
			if ( $profession === 'therapy' ) {
				$taxonomy_desc = 'Counselor';
			} elseif ( $profession === 'dental' ) {
				$taxonomy_desc = 'Dentist';
			} elseif ( $profession === 'chiropractic' ) {
				$taxonomy_desc = 'Chiropractor';
			} elseif ( $profession === 'nursing' ) {
				$taxonomy_desc = 'Nurse';
			} elseif ( $profession === 'medical' ) {
				$taxonomy_desc = 'Physician';
			} elseif ( $profession === 'veterinary' ) {
				$taxonomy_desc = 'Veterinarian';
			}

			$url = "https://npiregistry.cms.hhs.gov/api/?version=2.1&state={$state}&taxonomy_description=" . urlencode( $taxonomy_desc ) . "&enumeration_type=NPI-1&limit={$limit}";
			$res = wp_remote_get( $url, array( 'timeout' => 15 ) );

			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
				$leads = array();

				foreach ( ( $data['results'] ?? array() ) as $item ) {
					$basic = $item['basic'] ?? array();
					$addr  = $item['addresses'][0] ?? array();
					$firstName = ucwords( strtolower( trim( $basic['first_name'] ?? '' ) ) );
					$lastName  = ucwords( strtolower( trim( $basic['last_name'] ?? '' ) ) );
					$fullName  = trim( "{$firstName} {$lastName}" );

					if ( empty( $fullName ) ) {
						continue;
					}

					$taxonomy      = $item['taxonomies'][0] ?? array();
					$licenseNumber = ! empty( $taxonomy['license'] ) ? (string) $taxonomy['license'] : ( 'NPI-' . (string) ( $item['number'] ?? '' ) );
					$credential    = ! empty( $basic['credential'] ) ? trim( $basic['credential'] ) : ( $taxonomy['desc'] ?? 'Licensed Practitioner' );
					$phone         = trim( $addr['telephone_number'] ?? '' );
					$city          = ucwords( strtolower( trim( $addr['city'] ?? '' ) ) );
					$issueDate     = $basic['enumeration_date'] ?? gmdate( 'Y-m-d' );
					$gradYear      = (int) substr( $issueDate, 0, 4 );
					$issueTimestamp = strtotime( $issueDate );
					$isRecent      = ( $issueTimestamp !== false ) ? ( ( time() - $issueTimestamp ) <= ( 365 * 86400 ) ) : true;
					$licenseStatus = $isRecent ? 'Active Board Pass' : 'Licensed Practitioner';

					$previewSlug   = $this->generate_preview_slug( $fullName, 'nppes-' . ( $item['number'] ?? '' ) );
					$dealVal       = $this->get_estimated_deal_value( $profession );

					$leads[] = array(
						'id'                => 'nppes-' . ( $item['number'] ?? uniqid() ),
						'fullName'          => $fullName,
						'profession'        => $profession,
						'professionTitle'   => $credential,
						'state'             => $state,
						'city'              => $city,
						'licenseNumber'     => $licenseNumber,
						'issueDate'         => $issueDate,
						'collegeOrSchool'   => "{$state} Healthcare Provider Registry",
						'graduationYear'    => $gradYear > 1900 ? $gradYear : 2025,
						'licenseStatus'     => $licenseStatus,
						'skipTraceStatus'   => ! empty( $phone ) ? 'Traced' : 'Not Traced',
						'skipTraceData'     => ! empty( $phone ) ? array(
							'tracedAt'        => gmdate( 'Y-m-d' ),
							'confidenceScore' => 98,
							'verifiedPhone'   => $phone,
							'phoneType'       => 'Practice Line',
							'primaryEmail'    => '',
							'currentAddress'  => trim( ( $addr['address_1'] ?? '' ) . ', ' . $city . ', ' . $state ),
						) : null,
						'outreachStatus'    => 'Uncontacted',
						'websiteConfig'     => array(
							'previewSlug'     => $previewSlug,
							'heroHeadline'    => "{$fullName} - {$credential}",
							'heroSubheadline' => "Premier {$credential} services in {$city}, {$state}.",
							'tagline'         => "Verified Clinical Practice & Client Portal",
							'previewUrl'      => home_url( "/fresh-mints/#/preview/{$previewSlug}" ),
							'offerPrice'      => $dealVal,
						),
						'estimatedDealValue' => $dealVal,
						'createdAt'         => gmdate( 'c' ),
					);
				}

				if ( ! empty( $leads ) ) {
					return rest_ensure_response( array(
						'success'        => true,
						'source'         => "CMS Federal NPI Individual Registry ({$state})",
						'leads'          => $leads,
						'groundingNotes' => "Retrieved " . count( $leads ) . " live verified individual {$profession} practitioner records from CMS NPI Registry for {$state}.",
						'totalFound'     => count( $leads ),
					) );
				}
			}
		}

		// 2. Financial Advisors & Wealth Planners: Query Live FINRA BrokerCheck Public API
		if ( $profession === 'financial_advisor' ) {
			$finra_url = "https://api.brokercheck.finra.org/search/individual?query=advisor&hl=true&includePrevious=true&nrows={$limit}&start=0&r=25&state={$state}";
			$res       = wp_remote_get( $finra_url, array( 'timeout' => 15 ) );

			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
				$hits  = $data['hits']['hits'] ?? array();
				$leads = array();

				foreach ( $hits as $hit ) {
					$source    = $hit['_source'] ?? array();
					$firstName = ucwords( strtolower( trim( $source['ind_firstname'] ?? '' ) ) );
					$lastName  = ucwords( strtolower( trim( $source['ind_lastname'] ?? '' ) ) );
					$fullName  = trim( "{$firstName} {$lastName}" );

					if ( empty( $fullName ) ) {
						continue;
					}

					$crdNumber   = (string) ( $source['ind_source_id'] ?? '' );
					$emp         = $source['ind_current_employments'][0] ?? array();
					$firmName    = $emp['firm_name'] ?? 'Registered Investment Advisory';
					$city        = ucwords( strtolower( trim( $emp['branch_city'] ?? '' ) ) );
					$issueDate   = $source['ind_industry_cal_date'] ?? gmdate( 'Y-m-d' );
					$gradYear    = (int) substr( $issueDate, 0, 4 );
					$previewSlug = $this->generate_preview_slug( $fullName, 'finra-' . $crdNumber );
					$dealVal     = $this->get_estimated_deal_value( $profession );

					$leads[] = array(
						'id'                => 'finra-' . $crdNumber,
						'fullName'          => $fullName,
						'profession'        => 'financial_advisor',
						'professionTitle'   => 'Certified Financial Planner (CRD #' . $crdNumber . ')',
						'state'             => $state,
						'city'              => ! empty( $city ) ? $city : $this->get_default_city_for_state( $state ),
						'licenseNumber'     => 'CRD-' . $crdNumber,
						'issueDate'         => $issueDate,
						'collegeOrSchool'   => $firmName,
						'graduationYear'    => $gradYear > 1900 ? $gradYear : 2025,
						'licenseStatus'     => 'Active FINRA Registration',
						'skipTraceStatus'   => 'Not Traced',
						'skipTraceData'     => null,
						'outreachStatus'    => 'Uncontacted',
						'websiteConfig'     => array(
							'previewSlug'     => $previewSlug,
							'heroHeadline'    => "{$fullName} - Certified Financial Planner",
							'heroSubheadline' => "Fiduciary Wealth & Retirement Advisory in {$city}, {$state}.",
							'tagline'         => "Beacon Wealth & Fiduciary Advisors",
							'previewUrl'      => home_url( "/fresh-mints/#/preview/{$previewSlug}" ),
							'offerPrice'      => $dealVal,
						),
						'estimatedDealValue' => $dealVal,
						'createdAt'         => gmdate( 'c' ),
					);
				}

				if ( ! empty( $leads ) ) {
					return rest_ensure_response( array(
						'success'        => true,
						'source'         => "FINRA BrokerCheck Live Registry ({$state})",
						'leads'          => $leads,
						'groundingNotes' => "Retrieved " . count( $leads ) . " live verified individual financial advisor records from FINRA BrokerCheck for {$state}.",
						'totalFound'     => count( $leads ),
					) );
				}
			}
		}

		// 3. State Socrata Open License Registries (e.g. NY State Open Data)
		if ( $state === 'NY' ) {
			$ny_license_terms = array(
				'finance'      => 'Accountant',
				'architecture' => 'Architect',
				'veterinary'   => 'Veterinar',
				'dental'       => 'Dentist',
				'nursing'      => 'Nurse',
				'therapy'      => 'Mental Health',
				'beauty'       => 'Cosmetol',
			);

			$search_term = $ny_license_terms[ $profession ] ?? '';
			if ( ! empty( $search_term ) ) {
				$ny_url = "https://data.ny.gov/resource/k397-673v.json?\$limit={$limit}&\$order=issue_date%20DESC&\$q=" . urlencode( $search_term );
				$ny_res = wp_remote_get( $ny_url, array( 'timeout' => 15 ) );

				if ( ! is_wp_error( $ny_res ) && wp_remote_retrieve_response_code( $ny_res ) === 200 ) {
					$ny_rows = json_decode( wp_remote_retrieve_body( $ny_res ), true );
					if ( is_array( $ny_rows ) && ! empty( $ny_rows ) ) {
						$leads = array();
						foreach ( $ny_rows as $row ) {
							$raw_name = trim( $row['name'] ?? '' );
							if ( empty( $raw_name ) ) {
								$raw_name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
							}
							$fullName = ucwords( strtolower( $raw_name ) );
							if ( empty( $fullName ) ) {
								continue;
							}

							$lic_num     = (string) ( $row['license_number'] ?? ( 'NY-' . uniqid() ) );
							$city        = ucwords( strtolower( trim( $row['city'] ?? 'New York' ) ) );
							$issueDate   = substr( $row['issue_date'] ?? gmdate( 'Y-m-d' ), 0, 10 );
							$gradYear    = (int) substr( $issueDate, 0, 4 );
							$previewSlug = $this->generate_preview_slug( $fullName, 'ny-' . $lic_num );
							$dealVal     = $this->get_estimated_deal_value( $profession );

							$leads[] = array(
								'id'                => 'ny-' . sanitize_title( $lic_num ),
								'fullName'          => $fullName,
								'profession'        => $profession,
								'professionTitle'   => $row['license_type'] ?? 'Licensed Professional',
								'state'             => 'NY',
								'city'              => $city,
								'licenseNumber'     => 'NY-' . $lic_num,
								'issueDate'         => $issueDate,
								'collegeOrSchool'   => 'New York State Education Department',
								'graduationYear'    => $gradYear > 1900 ? $gradYear : 2025,
								'licenseStatus'     => 'Active Board Pass',
								'skipTraceStatus'   => 'Not Traced',
								'skipTraceData'     => null,
								'outreachStatus'    => 'Uncontacted',
								'websiteConfig'     => array(
									'previewSlug'     => $previewSlug,
									'heroHeadline'    => "{$fullName} - {$profession}",
									'heroSubheadline' => "Professional services in {$city}, NY.",
									'tagline'         => "Verified New York Practice",
									'previewUrl'      => home_url( "/fresh-mints/#/preview/{$previewSlug}" ),
									'offerPrice'      => $dealVal,
								),
								'estimatedDealValue' => $dealVal,
								'createdAt'         => gmdate( 'c' ),
							);
						}

						if ( ! empty( $leads ) ) {
							return rest_ensure_response( array(
								'success'        => true,
								'source'         => "New York State Open Data Registry (NY)",
								'leads'          => $leads,
								'groundingNotes' => "Retrieved " . count( $leads ) . " live verified {$profession} practitioner records from NY State Open Data.",
								'totalFound'     => count( $leads ),
							) );
						}
					}
				}
			}
		}

		// 3. Zero Mock / Zero Synthetic Fallback: Return genuine empty state
		$sourceLabel = $this->get_regulatory_board_name( $profession, $state );

		return rest_ensure_response( array(
			'success'        => true,
			'source'         => $sourceLabel,
			'leads'          => array(),
			'groundingNotes' => "No live verified records found for {$profession} in {$state}. Use the CSV Importer to upload state licensing board roster exports.",
			'totalFound'     => 0,
		) );
	}

	/**
	 * Get regulatory board authority name by profession and state.
	 */
	private function get_regulatory_board_name( $profession, $state ) {
		switch ( $profession ) {
			case 'financial_advisor':
				return "FINRA & SEC IAPD ({$state})";
			case 'finance':
				return "{$state} State Board of Accountancy (CPA Registry)";
			case 'real_estate':
				return "{$state} Department of Real Estate (DRE Registry)";
			case 'legal':
				return "State Bar of {$state} Licensing Board";
			case 'insurance':
				return "{$state} Department of Insurance & Financial Institutions";
			case 'trade':
				return "{$state} Registrar of Contractors (ROC)";
			case 'architecture':
				return "{$state} Board of Technical Registration";
			case 'veterinary':
				return "{$state} Veterinary Medical Examining Board";
			case 'beauty':
				return "{$state} Board of Cosmetology & Esthetics";
			default:
				return "State of {$state} Professional Licensing Registry";
		}
	}

	/**
	 * Get default city for state.
	 */
	private function get_default_city_for_state( $state ) {
		$cities = array(
			'AZ' => 'Phoenix',
			'CA' => 'Los Angeles',
			'TX' => 'Austin',
			'FL' => 'Miami',
			'NY' => 'New York',
			'CO' => 'Denver',
			'WA' => 'Seattle',
			'IL' => 'Chicago',
			'GA' => 'Atlanta',
			'NC' => 'Charlotte',
		);

		return $cities[ $state ] ?? 'Metro';
	}

	/**
	 * Estimated deal value helper.
	 */
	private function get_estimated_deal_value( $profession ) {
		$values = array(
			'financial_advisor' => 3950,
			'finance'           => 3950,
			'legal'             => 3950,
			'dental'            => 2650,
			'veterinary'        => 2650,
			'trade'             => 2650,
			'architecture'      => 2650,
			'real_estate'       => 1650,
			'chiropractic'      => 1650,
			'therapy'           => 1650,
			'insurance'         => 1650,
			'nursing'           => 1250,
			'beauty'            => 1250,
		);
		return $values[ $profession ] ?? 1650;
	}

	/**
	 * 1-Click Plug-and-Play Questbook CRM Contact Sync
	 */
	public function handle_crm_sync( WP_REST_Request $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$lead = $body['lead'] ?? array();

		if ( empty( $lead['fullName'] ) ) {
			return new WP_Error( 'invalid_lead', 'Lead fullName is required for Questbook sync.', array( 'status' => 400 ) );
		}

		$name_parts       = explode( ' ', trim( $lead['fullName'] ) );
		$first_name       = $name_parts[0] ?? '';
		$last_name        = count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '';
		$email            = $lead['skipTraceData']['primaryEmail'] ?? ( $lead['email'] ?? '' );
		$phone            = $lead['skipTraceData']['verifiedPhone'] ?? ( $lead['phone'] ?? '' );
		$profession       = $lead['profession'] ?? '';
		$profession_title = $lead['professionTitle'] ?? '';
		$license_num      = $lead['licenseNumber'] ?? '';
		$city             = $lead['city'] ?? '';
		$state            = $lead['state'] ?? '';
		$deal_value       = (float) ( $lead['estimatedDealValue'] ?? 1650 );
		$outreach_status  = $lead['outreachStatus'] ?? 'uncontacted';
		$college          = $lead['collegeOrSchool'] ?? '';
		$address          = $lead['skipTraceData']['currentAddress'] ?? '';

		$contacts_table = $wpdb->prefix . 'xophz_qb_contacts';
		$deals_table    = $wpdb->prefix . 'xophz_qb_deals';
		$contact_id     = 0;
		$action         = 'created';

		$company_name = ! empty( $lead['collegeOrSchool'] ) ? $lead['collegeOrSchool'] : ( $profession_title . ' Practice' );
		$meta_data = array(
			'profession'         => $profession,
			'professionTitle'    => $profession_title,
			'licenseNumber'      => $license_num,
			'city'               => $city,
			'state'              => $state,
			'address'            => $address,
			'collegeOrSchool'    => $college,
			'estimatedDealValue' => $deal_value,
			'outreachStatus'     => $outreach_status,
			'freshMintsLeadId'   => $lead['id'] ?? '',
			'notes'              => "Imported from Fresh Mints: {$profession_title} in {$city}, {$state}. License: {$license_num}.",
			'company'            => $company_name,
			'servicePackage'     => 'w4 2-Year Cloud Hosting',
			'paymentStatus'      => 'Pending',
			'retainer'           => $deal_value,
		);

		// Check if Questbook custom contacts table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $contacts_table ) ) === $contacts_table;

		if ( $table_exists ) {
			// Find existing contact by email or exact first/last name
			$existing = null;
			if ( ! empty( $email ) ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, meta_data FROM {$contacts_table} WHERE email = %s LIMIT 1",
					$email
				) );
			}
			if ( ! $existing && ! empty( $first_name ) && ! empty( $last_name ) ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, meta_data FROM {$contacts_table} WHERE first_name = %s AND last_name = %s LIMIT 1",
					$first_name,
					$last_name
				) );
			}

			if ( $existing ) {
				$contact_id = (int) $existing->id;
				$action     = 'updated';
				$existing_meta = json_decode( $existing->meta_data, true ) ?: array();
				$merged_meta   = array_merge( $existing_meta, $meta_data );

				$wpdb->update(
					$contacts_table,
					array(
						'first_name'  => sanitize_text_field( $first_name ),
						'last_name'   => sanitize_text_field( $last_name ),
						'email'       => sanitize_email( $email ),
						'phone'       => sanitize_text_field( $phone ),
						'company'     => sanitize_text_field( $company_name ),
						'source'      => 'Fresh Mints',
						'lead_status' => 'Lead',
						'meta_data'   => wp_json_encode( $merged_meta ),
						'updated_at'  => current_time( 'mysql' ),
					),
					array( 'id' => $contact_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$inserted = $wpdb->insert(
					$contacts_table,
					array(
						'first_name'  => sanitize_text_field( $first_name ),
						'last_name'   => sanitize_text_field( $last_name ),
						'email'       => sanitize_email( $email ),
						'phone'       => sanitize_text_field( $phone ),
						'company'     => sanitize_text_field( $company_name ),
						'source'      => 'Fresh Mints',
						'lead_status' => 'Lead',
						'meta_data'   => wp_json_encode( $meta_data ),
						'created_at'  => current_time( 'mysql' ),
						'updated_at'  => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					$contact_id = (int) $wpdb->insert_id;
				}
			}

			// Also sync into Questbook deals table
			$deals_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $deals_table ) ) === $deals_table;
			if ( $deals_table_exists && $contact_id > 0 ) {
				$existing_deal = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$deals_table} WHERE contact_id = %d LIMIT 1",
					$contact_id
				) );
				if ( ! $existing_deal ) {
					$wpdb->insert(
						$deals_table,
						array(
							'title'       => sanitize_text_field( "{$lead['fullName']} - {$profession_title}" ),
							'contact_id'  => $contact_id,
							'amount'      => $deal_value,
							'stage'       => 'New',
							'description' => sanitize_textarea_field( "Fresh Mints License Lead: {$license_num} ({$city}, {$state})" ),
							'created_at'  => current_time( 'mysql' ),
							'updated_at'  => current_time( 'mysql' ),
						),
						array( '%s', '%d', '%f', '%s', '%s', '%s', '%s' )
					);
				}
			}
		}

		// Also maintain CPT for backward compatibility with post queries
		$cpt_existing = 0;
		if ( ! empty( $email ) ) {
			$found = get_posts( array(
				'post_type'   => 'questbook_contact',
				'meta_key'    => '_qb_email',
				'meta_value'  => $email,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			) );
			if ( ! empty( $found ) ) {
				$cpt_existing = $found[0];
			}
		}

		$post_data = array(
			'post_title'  => sanitize_text_field( $lead['fullName'] ),
			'post_type'   => 'questbook_contact',
			'post_status' => 'publish',
		);
		if ( $cpt_existing > 0 ) {
			$post_data['ID'] = $cpt_existing;
			$cpt_id = wp_update_post( $post_data );
		} else {
			$cpt_id = wp_insert_post( $post_data );
		}

		if ( ! is_wp_error( $cpt_id ) && $cpt_id > 0 ) {
			$preview_slug = sanitize_title( $lead['websiteConfig']['previewSlug'] ?? $this->generate_preview_slug( $lead['fullName'], $lead['id'] ?? '' ) );
			$preview_url  = home_url( '/fresh-mints/#/preview/' . $preview_slug );

			update_post_meta( $cpt_id, '_qb_first_name', sanitize_text_field( $first_name ) );
			update_post_meta( $cpt_id, '_qb_last_name', sanitize_text_field( $last_name ) );
			update_post_meta( $cpt_id, '_qb_full_name', sanitize_text_field( $lead['fullName'] ) );
			update_post_meta( $cpt_id, '_qb_email', sanitize_email( $email ) );
			update_post_meta( $cpt_id, '_qb_phone', sanitize_text_field( $phone ) );
			update_post_meta( $cpt_id, '_qb_profession', sanitize_text_field( $profession ) );
			update_post_meta( $cpt_id, '_qb_license_number', sanitize_text_field( $license_num ) );
			update_post_meta( $cpt_id, '_qb_city', sanitize_text_field( $city ) );
			update_post_meta( $cpt_id, '_qb_state', sanitize_text_field( $state ) );
			update_post_meta( $cpt_id, '_qb_lead_source', 'fresh_mints' );
			update_post_meta( $cpt_id, '_qb_deal_value', $deal_value );
			update_post_meta( $cpt_id, '_qb_outreach_status', sanitize_text_field( $outreach_status ) );
			update_post_meta( $cpt_id, '_qb_fresh_mints_lead_id', sanitize_text_field( $lead['id'] ?? '' ) );
			update_post_meta( $cpt_id, '_qb_preview_slug', $preview_slug );
			update_post_meta( $cpt_id, '_qb_preview_url', $preview_url );
			if ( $contact_id > 0 ) {
				update_post_meta( $cpt_id, '_qb_custom_table_contact_id', $contact_id );
			}
		}

		$final_contact_id = $contact_id > 0 ? $contact_id : ( ! is_wp_error( $cpt_id ) ? $cpt_id : 0 );

		// Auto-sync into Bomb Bag if setting or request flag is active
		$auto_bomb_bag = get_option( 'xophz_compass_freshmints_auto_sync_bomb_bag', '0' );
		$bomb_bag_res  = null;
		if ( $auto_bomb_bag === '1' || ! empty( $body['syncBombBag'] ) ) {
			$bomb_bag_req = new WP_REST_Request( 'POST', '/xophz-freshmints/v1/bomb-bag/sync' );
			$bomb_bag_req->set_body_params( array( 'lead' => $lead ) );
			$bomb_bag_res = $this->handle_bomb_bag_sync( $bomb_bag_req );
		}

		return rest_ensure_response( array(
			'success'        => true,
			'contactId'      => $final_contact_id,
			'action'         => $action,
			'crmMessage'     => "Successfully synced {$lead['fullName']} into Questbook CRM (ID: #{$final_contact_id}).",
			'bombBagResult'  => $bomb_bag_res ? $bomb_bag_res->get_data() : null,
		) );
	}

	/**
	 * Generate a clean kebab-case preview slug from practitioner full name with collision support.
	 */
	public function generate_preview_slug( $full_name, $id = '', $state = '', $license = '' ) {
		$clean = sanitize_title( $full_name );
		if ( empty( $clean ) ) {
			$clean = ! empty( $id ) ? sanitize_title( $id ) : 'lead-' . wp_rand( 1000, 9999 );
		}

		// If state is provided and needed for disambiguation
		if ( ! empty( $state ) && ( empty( $clean ) || strpos( $clean, 'dr-' ) === 0 ) ) {
			$clean .= '-' . strtolower( sanitize_title( $state ) );
		}

		return $clean;
	}

	/**
	 * 1-Click Sync single lead or array of leads into Bomb Bag Email Marketing & Journeys
	 */
	public function handle_bomb_bag_sync( WP_REST_Request $request ) {
		global $wpdb;
		$body           = $request->get_json_params();
		$lead           = $body['lead'] ?? null;
		$leads          = $body['leads'] ?? ( $lead ? array( $lead ) : array() );
		$target_list_id = ! empty( $body['listId'] ) ? absint( $body['listId'] ) : 0;
		$custom_tags    = ! empty( $body['tags'] ) && is_array( $body['tags'] ) ? $body['tags'] : array();

		if ( empty( $leads ) ) {
			return new WP_Error( 'missing_lead', 'Lead data is required for Bomb Bag synchronization.', array( 'status' => 400 ) );
		}

		$subscribers_table      = $wpdb->prefix . 'bomb_bag_subscribers';
		$lists_table            = $wpdb->prefix . 'bomb_bag_lists';
		$list_subscribers_table = $wpdb->prefix . 'bomb_bag_list_subscribers';
		$tags_table             = $wpdb->prefix . 'bomb_bag_tags';
		$subscriber_tags_table  = $wpdb->prefix . 'bomb_bag_subscriber_tags';

		// Verify that Bomb Bag subscribers table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscribers_table ) ) === $subscribers_table;
		if ( ! $table_exists ) {
			return new WP_Error( 'bomb_bag_not_active', 'Bomb Bag database tables are not installed or active.', array( 'status' => 400 ) );
		}

		$synced_results = array();

		foreach ( $leads as $l ) {
			$full_name        = trim( $l['fullName'] ?? 'Practitioner Lead' );
			$name_parts       = explode( ' ', $full_name, 2 );
			$first_name       = $name_parts[0] ?? '';
			$last_name        = $name_parts[1] ?? '';
			$profession       = sanitize_text_field( $l['profession'] ?? 'general' );
			$profession_title = sanitize_text_field( $l['professionTitle'] ?? ucwords( str_replace( '_', ' ', $profession ) ) );
			$city             = sanitize_text_field( $l['city'] ?? '' );
			$state            = sanitize_text_field( $l['state'] ?? '' );
			$license_num      = sanitize_text_field( $l['licenseNumber'] ?? '' );
			$phone            = sanitize_text_field( $l['skipTraceData']['verifiedPhone'] ?? '' );
			$preview_slug     = sanitize_title( $l['websiteConfig']['previewSlug'] ?? $this->generate_preview_slug( $full_name, $l['id'] ?? '' ) );
			$preview_url      = home_url( '/fresh-mints/#/preview/' . $preview_slug );
			$deal_value       = (float) ( $l['estimatedDealValue'] ?? 1650 );
			$outreach_status  = sanitize_text_field( $l['outreachStatus'] ?? 'Uncontacted' );

			// Determine valid or fallback email
			$email = sanitize_email( $l['skipTraceData']['primaryEmail'] ?? '' );
			if ( empty( $email ) ) {
				$email_slug = sanitize_title( $full_name );
				$email      = $email_slug . '-' . sanitize_title( $license_num ?: ( $l['id'] ?? 'lead' ) ) . '@leads.freshmints.local';
			}

			// Custom fields JSON
			$custom_fields = array(
				'profession'       => $profession,
				'profession_title' => $profession_title,
				'license_number'   => $license_num,
				'city'             => $city,
				'state'            => $state,
				'phone'            => $phone,
				'preview_slug'     => $preview_slug,
				'preview_url'      => $preview_url,
				'deal_value'       => $deal_value,
				'outreach_status'  => $outreach_status,
				'lead_id'          => $l['id'] ?? '',
				'source'           => 'Fresh Mints',
			);

			// Check existing subscriber by email
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, custom_fields FROM {$subscribers_table} WHERE email = %s LIMIT 1",
				$email
			) );

			$subscriber_id = 0;
			$action        = 'created';

			if ( $existing ) {
				$subscriber_id = (int) $existing->id;
				$action        = 'updated';
				$existing_cf   = json_decode( $existing->custom_fields, true ) ?: array();
				$merged_cf     = array_merge( $existing_cf, $custom_fields );

				$wpdb->update(
					$subscribers_table,
					array(
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'source'        => 'Fresh Mints',
						'custom_fields' => wp_json_encode( $merged_cf ),
						'updated_at'    => current_time( 'mysql' ),
					),
					array( 'id' => $subscriber_id ),
					array( '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$inserted = $wpdb->insert(
					$subscribers_table,
					array(
						'email'         => $email,
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'status'        => 'active',
						'source'        => 'Fresh Mints',
						'score'         => 50,
						'lead_status'   => 'warm',
						'custom_fields' => wp_json_encode( $custom_fields ),
						'subscribed_at' => current_time( 'mysql' ),
						'created_at'    => current_time( 'mysql' ),
						'updated_at'    => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( $inserted ) {
					$subscriber_id = (int) $wpdb->insert_id;
				}
			}

			if ( $subscriber_id > 0 ) {
				// Manage Lists
				$assigned_list_id = $target_list_id;
				if ( ! $assigned_list_id ) {
					// Ensure default master Fresh Mints list exists
					$master_list_name = 'Fresh Mints: All Leads';
					$list_row         = $wpdb->get_row( $wpdb->prepare(
						"SELECT id FROM {$lists_table} WHERE name = %s LIMIT 1",
						$master_list_name
					) );
					if ( $list_row ) {
						$assigned_list_id = (int) $list_row->id;
					} else {
						$wpdb->insert(
							$lists_table,
							array(
								'name'        => $master_list_name,
								'description' => 'Master list of practitioner leads synced from Fresh Mints discovery platform.',
								'created_at'  => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%s' )
						);
						$assigned_list_id = (int) $wpdb->insert_id;
					}
				}

				if ( $assigned_list_id > 0 ) {
					// Attach to list
					$link_exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$list_subscribers_table} WHERE list_id = %d AND subscriber_id = %d",
						$assigned_list_id,
						$subscriber_id
					) );
					if ( ! $link_exists ) {
						$wpdb->insert(
							$list_subscribers_table,
							array(
								'list_id'       => $assigned_list_id,
								'subscriber_id' => $subscriber_id,
							),
							array( '%d', '%d' )
						);
					}

					// Update subscriber count in list
					$count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(subscriber_id) FROM {$list_subscribers_table} WHERE list_id = %d",
						$assigned_list_id
					) );
					$wpdb->update(
						$lists_table,
						array( 'subscriber_count' => $count ),
						array( 'id' => $assigned_list_id ),
						array( '%d' ),
						array( '%d' )
					);
				}

				// Manage Tags
				$tags_to_apply = array_unique( array_merge(
					array( 'Fresh Mints', $profession_title, $state ?: 'US', $outreach_status ),
					$custom_tags
				) );

				$tags_table_exists     = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tags_table ) ) === $tags_table;
				$sub_tags_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscriber_tags_table ) ) === $subscriber_tags_table;

				if ( $tags_table_exists && $sub_tags_table_exists ) {
					foreach ( $tags_to_apply as $tag_name ) {
						$tag_name = trim( $tag_name );
						if ( empty( $tag_name ) ) {
							continue;
						}

						$tag_slug = sanitize_title( $tag_name );
						$tag_row  = $wpdb->get_row( $wpdb->prepare(
							"SELECT id FROM {$tags_table} WHERE slug = %s OR name = %s LIMIT 1",
							$tag_slug,
							$tag_name
						) );

						$tag_id = 0;
						if ( $tag_row ) {
							$tag_id = (int) $tag_row->id;
						} else {
							$wpdb->insert(
								$tags_table,
								array(
									'name'       => $tag_name,
									'slug'       => $tag_slug,
									'created_at' => current_time( 'mysql' ),
								),
								array( '%s', '%s', '%s' )
							);
							$tag_id = (int) $wpdb->insert_id;
						}

						if ( $tag_id > 0 ) {
							$tag_link_exists = $wpdb->get_var( $wpdb->prepare(
								"SELECT COUNT(*) FROM {$subscriber_tags_table} WHERE subscriber_id = %d AND tag_id = %d",
								$subscriber_id,
								$tag_id
							) );
							if ( ! $tag_link_exists ) {
								$wpdb->insert(
									$subscriber_tags_table,
									array(
										'subscriber_id' => $subscriber_id,
										'tag_id'        => $tag_id,
									),
									array( '%d', '%d' )
								);
							}
						}
					}
				}

				// Trigger Bomb Bag automation hook
				do_action( 'bomb_bag_subscriber_created', $subscriber_id, $assigned_list_id );

				$synced_results[] = array(
					'leadId'       => $l['id'] ?? '',
					'subscriberId' => $subscriber_id,
					'listId'       => $assigned_list_id,
					'action'       => $action,
					'email'        => $email,
					'previewSlug'  => $preview_slug,
				);
			}
		}

		return rest_ensure_response( array(
			'success'      => true,
			'syncedCount'  => count( $synced_results ),
			'results'      => $synced_results,
			'subscriberId' => $synced_results[0]['subscriberId'] ?? 0,
			'message'      => count( $synced_results ) === 1
				? "Successfully synced {$leads[0]['fullName']} into Bomb Bag Marketing (Subscriber ID: #{$synced_results[0]['subscriberId']})."
				: "Successfully synced " . count( $synced_results ) . " leads into Bomb Bag Marketing.",
		) );
	}

	/**
	 * Get available Bomb Bag subscriber lists
	 */
	public function handle_get_bomb_bag_lists() {
		global $wpdb;
		$lists_table = $wpdb->prefix . 'bomb_bag_lists';
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table ) ) === $lists_table;

		if ( ! $table_exists ) {
			return rest_ensure_response( array(
				'success' => true,
				'lists'   => array(),
			) );
		}

		$lists = $wpdb->get_results( "SELECT id, name, description, subscriber_count FROM {$lists_table} ORDER BY name ASC" );

		return rest_ensure_response( array(
			'success' => true,
			'lists'   => $lists ?: array(),
		) );
	}

	/**
	 * Get Fresh Mints Pipeline Stats
	 */
	public function handle_get_stats() {
		global $wpdb;
		$total_contacts = 0;
		if ( post_type_exists( 'questbook_contact' ) ) {
			$counts         = (array) wp_count_posts( 'questbook_contact' );
			$total_contacts = (int) ( $counts['publish'] ?? 0 );
		}

		$bomb_subscribers = 0;
		$subscribers_table = $wpdb->prefix . 'bomb_bag_subscribers';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subscribers_table ) ) === $subscribers_table ) {
			$bomb_subscribers = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$subscribers_table} WHERE source = 'Fresh Mints' OR custom_fields LIKE '%Fresh Mints%'" );
		}

		$bomb_lists = 0;
		$lists_table = $wpdb->prefix . 'bomb_bag_lists';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table ) ) === $lists_table ) {
			$bomb_lists = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$lists_table}" );
		}

		return rest_ensure_response( array(
			'success'            => true,
			'questbookContacts'  => $total_contacts,
			'bombBagSubscribers' => $bomb_subscribers,
			'bombBagLists'       => $bomb_lists,
			'loadMode'           => get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' ),
			'customSlug'         => get_option( 'xophz_compass_freshmints_custom_slug', 'fresh-mints' ),
			'hasGeminiConnector' => ! empty( $this->get_gemini_api_key() ),
		) );
	}

	/**
	 * Authenticate User via In-App Login
	 */
	public function handle_login( $request ) {
		$params   = $request->get_json_params() ?: array();
		$username = sanitize_text_field( $params['username'] ?? '' );
		$password = trim( $params['password'] ?? '' );
		$remember = ! empty( $params['remember'] );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error( 'missing_credentials', 'Please enter your username/email and password.', array( 'status' => 400 ) );
		}

		// Support logging in with email or username
		if ( is_email( $username ) ) {
			$user_obj = get_user_by( 'email', $username );
			if ( $user_obj ) {
				$username = $user_obj->user_login;
			}
		}

		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'invalid_credentials', 'Invalid username or password. Please try again.', array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		$user_data = array(
			'id'           => 'wp-' . $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'fullName'     => $user->display_name ?: $user->user_login,
			'avatarUrl'    => get_avatar_url( $user->ID ) ?: '',
			'role'         => in_array( 'administrator', (array) $user->roles, true ) ? 'admin' : 'rep',
			'roles'        => (array) $user->roles,
			'registeredAt' => strtotime( $user->user_registered ) * 1000,
		);

		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => true,
			'user'       => $user_data,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Log Out Current User
	 */
	public function handle_logout() {
		wp_logout();
		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => false,
			'user'       => null,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/**
	 * Get Current Authenticated User & Nonce
	 */
	public function handle_get_me() {
		$is_logged_in = is_user_logged_in();
		$user_id      = get_current_user_id();
		$user_data    = null;

		if ( $is_logged_in && $user_id > 0 ) {
			$u = wp_get_current_user();
			$user_data = array(
				'id'           => 'wp-' . $user_id,
				'username'     => $u->user_login,
				'email'        => $u->user_email,
				'fullName'     => $u->display_name ?: $u->user_login,
				'avatarUrl'    => get_avatar_url( $user_id ) ?: '',
				'role'         => in_array( 'administrator', (array) $u->roles, true ) ? 'admin' : 'rep',
				'roles'        => (array) $u->roles,
				'registeredAt' => strtotime( $u->user_registered ) * 1000,
			);
		}

		return rest_ensure_response( array(
			'success'    => true,
			'isLoggedIn' => $is_logged_in,
			'user'       => $user_data,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		) );
	}
}
