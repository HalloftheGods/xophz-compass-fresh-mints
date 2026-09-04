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

		register_rest_route( 'xophz-freshmints/v1', '/places/search-no-website', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_places_search_no_website' ),
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
		return current_user_can( 'manage_options' );
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

			$phone            = '';
			$phone_type       = 'Unverified';
			$email            = '';
			$secondary_email  = '';
			$linkedin         = '';
			$website          = '';
			$address          = "{$city}, {$state}";
			$confidence       = 0;
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
								$phone_type      = 'Verified Practice Line';
								$confidence      = 95;
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
						$confidence      = 90;
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
				$scraped            = $this->scrape_website_contact_info( $website, $name );
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
					$confidence       = max( $confidence, 92 );
				}

				if ( empty( $phone ) && ! empty( $scraped['primaryPhone'] ) ) {
					$phone           = $scraped['primaryPhone'];
					$phone_type      = 'Website Scraped Line';
					$sources_found[] = 'Website Phone Scraper';
					$confidence      = max( $confidence, 88 );
				}
			}

			$has_contact = ! empty( $phone ) || ! empty( $email );
			$notes = ! empty( $sources_found )
				? ( 'Verified authentic coordinates via ' . implode( ' + ', array_unique( $sources_found ) ) . '.' )
				: 'No verified contact record found in Google Places or Yelp. (Deterministic API lookup - zero synthetic generation).';

			return rest_ensure_response( array(
				'success' => true,
				'data'    => array(
					'confidenceScore'   => $has_contact ? ( $confidence > 0 ? $confidence : 90 ) : 0,
					'verifiedPhone'     => $phone,
					'phoneType'         => ! empty( $phone ) ? $phone_type : 'Unverified',
					'dncStatus'         => ! empty( $phone ) ? 'Public Business Directory' : 'No Phone Found',
					'primaryEmail'      => $email,
					'secondaryEmail'    => $secondary_email,
					'websiteUrl'        => $website,
					'extractedEmails'   => $extracted_emails,
					'extractedPhones'   => $extracted_phones,
					'emailPermutations' => $email_permutations,
					'mxValid'           => $mx_valid,
					'mxRecords'         => $mx_records,
					'schemaOrgData'     => $schema_data,
					'emailValidation'   => ! empty( $email ) ? 'Website Scraped & Verified' : '',
					'linkedInUrl'       => $linkedin,
					'currentAddress'    => $address,
					'enrichmentNotes'   => $notes,
				),
			) );
		}

		return new WP_Error( 'invalid_action', 'Invalid generation action specified.', array( 'status' => 400 ) );
	}

	/**
	 * Check DNS MX records for domain.
	 *
	 * @param string $domain Target domain
	 * @return array MX status and record list
	 */
	public function check_domain_mx_records( $domain ) {
		$clean_domain = strtolower( trim( preg_replace( '~^https?://~i', '', $domain ) ) );
		$clean_domain = explode( '/', $clean_domain )[0];
		$clean_domain = preg_replace( '/^www\./', '', $clean_domain );

		if ( empty( $clean_domain ) || ! strpos( $clean_domain, '.' ) ) {
			return array( 'valid' => false, 'records' => array(), 'domain' => $clean_domain );
		}

		$mx_records = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $clean_domain, DNS_MX );
			if ( ! empty( $records ) && is_array( $records ) ) {
				foreach ( $records as $rec ) {
					if ( ! empty( $rec['target'] ) ) {
						$mx_records[] = $rec['target'];
					}
				}
			}
		}

		if ( empty( $mx_records ) && function_exists( 'checkdnsrr' ) ) {
			$has_mx = @checkdnsrr( $clean_domain, 'MX' );
			if ( $has_mx ) {
				$mx_records[] = "mail.{$clean_domain}";
			}
		}

		return array(
			'valid'   => ! empty( $mx_records ),
			'records' => array_values( array_unique( $mx_records ) ),
			'domain'  => $clean_domain,
		);
	}

	/**
	 * Generate standardized business email permutations for a domain based on practitioner name.
	 *
	 * @param string $lead_name Full name of the lead
	 * @param string $domain Website domain
	 * @return array List of candidate email permutations
	 */
	public function generate_domain_email_permutations( $lead_name, $domain ) {
		$clean_domain = strtolower( trim( preg_replace( '~^https?://~i', '', $domain ) ) );
		$clean_domain = explode( '/', $clean_domain )[0];
		$clean_domain = preg_replace( '/^www\./', '', $clean_domain );

		if ( empty( $clean_domain ) || ! strpos( $clean_domain, '.' ) ) {
			return array();
		}

		$permutations = array();
		$clean_name = strtolower( trim( preg_replace( '/[^a-zA-Z\s]/', '', $lead_name ) ) );
		$parts = array_values( array_filter( explode( ' ', $clean_name ) ) );

		if ( ! empty( $parts ) ) {
			$first = $parts[0];
			$last  = count( $parts ) > 1 ? end( $parts ) : '';

			if ( ! empty( $first ) && ! empty( $last ) ) {
				$first_initial = substr( $first, 0, 1 );

				$permutations[] = "{$first}.{$last}@{$clean_domain}";
				$permutations[] = "{$first}@{$clean_domain}";
				$permutations[] = "{$first_initial}{$last}@{$clean_domain}";
				$permutations[] = "{$first}{$last}@{$clean_domain}";
				$permutations[] = "{$first}_{$last}@{$clean_domain}";
			} elseif ( ! empty( $first ) ) {
				$permutations[] = "{$first}@{$clean_domain}";
			}
		}

		$standard = array( 'info', 'contact', 'office', 'appointments', 'admin', 'hello' );
		foreach ( $standard as $std ) {
			$permutations[] = "{$std}@{$clean_domain}";
		}

		return array_values( array_unique( $permutations ) );
	}

	/**
	 * Scrape website and contact pages for authentic email addresses, phone numbers, JSON-LD, and social links.
	 *
	 * @param string $url Target website URL
	 * @param string $lead_name Optional lead name for permutation generation
	 * @return array Scraped contact data
	 */
	public function scrape_website_contact_info( $url, $lead_name = '' ) {
		$result = array(
			'websiteUrl'          => $url,
			'emails'              => array(),
			'phones'              => array(),
			'primaryEmail'        => '',
			'primaryPhone'        => '',
			'pagesScanned'        => array(),
			'socialProfilesFound' => array(),
			'emailPermutations'   => array(),
			'mxValid'             => false,
			'mxRecords'           => array(),
			'schemaOrgData'       => null,
		);

		if ( empty( $url ) ) {
			return $result;
		}

		$clean_url = trim( $url );
		if ( ! preg_match( '~^https?://~i', $clean_url ) ) {
			$clean_url = 'https://' . $clean_url;
		}

		if ( ! filter_var( $clean_url, FILTER_VALIDATE_URL ) ) {
			return $result;
		}

		$result['websiteUrl'] = $clean_url;
		$parsed_base = parse_url( $clean_url );
		$base_host   = $parsed_base['host'] ?? '';
		$base_scheme = $parsed_base['scheme'] ?? 'https';

		if ( empty( $base_host ) ) {
			return $result;
		}

		// Disallowed domain check for scraping
		$ignore_hosts = array( 'facebook.com', 'instagram.com', 'yelp.com', 'linkedin.com', 'twitter.com', 'x.com', 'google.com', 'healthgrades.com', 'zocdoc.com' );
		foreach ( $ignore_hosts as $ih ) {
			if ( strpos( strtolower( $base_host ), $ih ) !== false ) {
				return $result;
			}
		}

		// Decode Cloudflare email protection data-cfemail
		$decode_cf_email = function( $cf_hex ) {
			if ( empty( $cf_hex ) || strlen( $cf_hex ) < 4 ) {
				return '';
			}
			$k = hexdec( substr( $cf_hex, 0, 2 ) );
			$email = '';
			for ( $i = 2; $i < strlen( $cf_hex ); $i += 2 ) {
				$email .= chr( hexdec( substr( $cf_hex, $i, 2 ) ) ^ $k );
			}
			return $email;
		};

		$fetch_and_extract = function( $target_url ) use ( &$result, $decode_cf_email ) {
			$res = wp_remote_get( $target_url, array(
				'timeout'     => 7,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
				'sslverify'   => false,
			) );

			if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
				return array( 'html' => '', 'emails' => array(), 'phones' => array(), 'contact_links' => array() );
			}

			$html = wp_remote_retrieve_body( $res );
			if ( ! in_array( $target_url, $result['pagesScanned'], true ) ) {
				$result['pagesScanned'][] = $target_url;
			}

			$found_emails  = array();
			$found_phones  = array();
			$contact_links = array();

			// 1. Extract mailto: links
			if ( preg_match_all( '/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $html, $m_matches ) ) {
				foreach ( $m_matches[1] as $em ) {
					$clean_em = strtolower( trim( $em ) );
					if ( ! in_array( $clean_em, $found_emails, true ) ) {
						$found_emails[] = $clean_em;
					}
				}
			}

			// 2. Extract Cloudflare protected emails (data-cfemail="...")
			if ( preg_match_all( '/data-cfemail=[\'"]([a-f0-9]+)[\'"]/i', $html, $cf_matches ) ) {
				foreach ( $cf_matches[1] as $cf_hex ) {
					$decoded_em = strtolower( trim( $decode_cf_email( $cf_hex ) ) );
					if ( ! empty( $decoded_em ) && filter_var( $decoded_em, FILTER_VALIDATE_EMAIL ) && ! in_array( $decoded_em, $found_emails, true ) ) {
						$found_emails[] = $decoded_em;
					}
				}
			}

			// 3. Extract Cloudflare links (/cdn-cgi/l/email-protection#...)
			if ( preg_match_all( '/\/cdn-cgi\/l\/email-protection#([a-f0-9]+)/i', $html, $cf_link_matches ) ) {
				foreach ( $cf_link_matches[1] as $cf_hex ) {
					$decoded_em = strtolower( trim( $decode_cf_email( $cf_hex ) ) );
					if ( ! empty( $decoded_em ) && filter_var( $decoded_em, FILTER_VALIDATE_EMAIL ) && ! in_array( $decoded_em, $found_emails, true ) ) {
						$found_emails[] = $decoded_em;
					}
				}
			}

			// 4. Extract standard regex emails
			if ( preg_match_all( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', $html, $reg_matches ) ) {
				foreach ( $reg_matches[0] as $em ) {
					$clean_em = strtolower( trim( $em ) );
					if ( ! in_array( $clean_em, $found_emails, true ) ) {
						$found_emails[] = $clean_em;
					}
				}
			}

			// 5. Extract text-obfuscated emails (e.g. user [at] domain [dot] com)
			if ( preg_match_all( '/([a-zA-Z0-9._%+-]+)\s*(?:\[at\]|\(at\))\s*([a-zA-Z0-9.-]+)\s*(?:\[dot\]|\(dot\)|\.)\s*([a-zA-Z]{2,})/i', $html, $obf_matches, PREG_SET_ORDER ) ) {
				foreach ( $obf_matches as $om ) {
					$reconstructed = strtolower( trim( "{$om[1]}@{$om[2]}.{$om[3]}" ) );
					if ( filter_var( $reconstructed, FILTER_VALIDATE_EMAIL ) && ! in_array( $reconstructed, $found_emails, true ) ) {
						$found_emails[] = $reconstructed;
					}
				}
			}

			// 6. Extract Schema.org JSON-LD structured data
			if ( preg_match_all( '/<script[^>]*type=[\'"]application\/ld\+json[\'"][^>]*>([\s\S]*?)<\/script>/i', $html, $jsonld_matches ) ) {
				foreach ( $jsonld_matches[1] as $json_str ) {
					$decoded_ld = json_decode( trim( $json_str ), true );
					if ( $decoded_ld ) {
						$extract_from_ld = function( $node ) use ( &$extract_from_ld, &$found_emails, &$found_phones, &$result ) {
							if ( ! is_array( $node ) ) return;
							if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
								foreach ( $node['@graph'] as $sub_node ) $extract_from_ld( $sub_node );
								return;
							}
							if ( ! empty( $node['email'] ) ) {
								$em = strtolower( trim( is_string( $node['email'] ) ? $node['email'] : '' ) );
								if ( filter_var( $em, FILTER_VALIDATE_EMAIL ) && ! in_array( $em, $found_emails, true ) ) {
									$found_emails[] = $em;
								}
							}
							if ( ! empty( $node['telephone'] ) ) {
								$ph = trim( preg_replace( '/[^\d\+\(\)\-\.\s]/', '', (string)$node['telephone'] ) );
								if ( strlen( preg_replace( '/\D/', '', $ph ) ) >= 10 && ! in_array( $ph, $found_phones, true ) ) {
									$found_phones[] = $ph;
								}
							}
							if ( ! empty( $node['contactPoint'] ) ) {
								$extract_from_ld( $node['contactPoint'] );
							}
							if ( ! empty( $node['founder'] ) ) {
								$extract_from_ld( $node['founder'] );
							}
							if ( ! empty( $node['employee'] ) ) {
								$extract_from_ld( $node['employee'] );
							}
							if ( empty( $result['schemaOrgData'] ) && ! empty( $node['@type'] ) ) {
								$result['schemaOrgData'] = array(
									'type'    => (string)$node['@type'],
									'name'    => (string)( $node['name'] ?? '' ),
									'email'   => (string)( $node['email'] ?? '' ),
									'phone'   => (string)( $node['telephone'] ?? '' ),
									'address' => is_array( $node['address'] ?? null ) ? ( $node['address']['streetAddress'] ?? '' ) : (string)( $node['address'] ?? '' ),
								);
							}
						};
						$extract_from_ld( $decoded_ld );
					}
				}
			}

			// 7. Extract Social Profile links
			if ( preg_match_all( '/href=[\'"](https?:\/\/(?:www\.)?(?:facebook\.com|instagram\.com|linkedin\.com|twitter\.com|x\.com|yelp\.com|youtube\.com)\/[^\'"#\s>]+)[\'"]/i', $html, $soc_matches ) ) {
				foreach ( $soc_matches[1] as $soc_url ) {
					$clean_soc = trim( $soc_url );
					if ( ! in_array( $clean_soc, $result['socialProfilesFound'], true ) ) {
						if ( ! preg_match( '/(sharer|share|intent|login|signup|policies)$/i', $clean_soc ) ) {
							$result['socialProfilesFound'][] = $clean_soc;
						}
					}
				}
			}

			// 8. Extract tel: links
			if ( preg_match_all( '/href=[\'"]tel:([^\'"\s>]+)[\'"]/i', $html, $tel_matches ) ) {
				foreach ( $tel_matches[1] as $ph ) {
					$clean_ph = trim( preg_replace( '/[^\d\+\(\)\-\.\s]/', '', $ph ) );
					if ( strlen( preg_replace( '/\D/', '', $clean_ph ) ) >= 10 && ! in_array( $clean_ph, $found_phones, true ) ) {
						$found_phones[] = $clean_ph;
					}
				}
			}

			// 9. Extract possible internal contact/about subpage links
			if ( preg_match_all( '/href=[\'"]([^\'"#\s>]+)[\'"]/i', $html, $href_matches ) ) {
				foreach ( $href_matches[1] as $href ) {
					if ( preg_match( '/(contact|about|team|staff|reach|connect|doctors|practitioners|location|locations|hours|office|touch)/i', $href ) ) {
						if ( ! preg_match( '/\.(jpg|jpeg|png|gif|svg|css|js|pdf|webp|ico|xml|json)$/i', $href ) ) {
							$contact_links[] = $href;
						}
					}
				}
			}

			return array(
				'html'          => $html,
				'emails'        => $found_emails,
				'phones'        => $found_phones,
				'contact_links' => array_unique( $contact_links ),
			);
		};

		// Helper to filter out system / garbage emails
		$filter_clean_emails = function( array $raw_emails ) {
			$invalid_needles = array(
				'example.com', 'domain.com', 'sentry.io', 'wixpress.com', 'wordpress.org', 'wp.com',
				'cloudflare.com', 'google.com', 'schema.org', 'w3.org', 'github.com', 'fontawesome',
				'bootstrap', 'jquery', 'npm', 'user@', 'email@', 'test@', 'yourname@', 'name@',
				'.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.css', '.js'
			);
			$clean = array();
			foreach ( $raw_emails as $em ) {
				$em_lower = strtolower( trim( $em ) );
				if ( ! filter_var( $em_lower, FILTER_VALIDATE_EMAIL ) ) {
					continue;
				}
				$skip = false;
				foreach ( $invalid_needles as $inv ) {
					if ( strpos( $em_lower, $inv ) !== false ) {
						$skip = true;
						break;
					}
				}
				if ( ! $skip && ! in_array( $em_lower, $clean, true ) ) {
					$clean[] = $em_lower;
				}
			}
			return $clean;
		};

		// 1. Fetch Homepage
		$homepage_data = $fetch_and_extract( $clean_url );
		$all_emails    = $filter_clean_emails( $homepage_data['emails'] );
		$all_phones    = $homepage_data['phones'];

		// 2. Assemble candidate contact subpages
		$subpage_targets = array();

		if ( ! empty( $homepage_data['contact_links'] ) ) {
			foreach ( $homepage_data['contact_links'] as $link ) {
				$sub_url = $link;
				if ( strpos( $link, 'http' ) !== 0 ) {
					$sub_url = rtrim( "{$base_scheme}://{$base_host}", '/' ) . '/' . ltrim( $link, '/' );
				}
				$sub_host = parse_url( $sub_url, PHP_URL_HOST );
				if ( $sub_host && strpos( strtolower( $sub_host ), strtolower( $base_host ) ) !== false ) {
					if ( ! in_array( $sub_url, $subpage_targets, true ) && $sub_url !== $clean_url ) {
						$subpage_targets[] = $sub_url;
					}
				}
			}
		}

		$candidate_paths = array(
			'/contact',
			'/contact-us',
			'/contactus',
			'/about',
			'/about-us',
			'/our-team',
			'/team',
			'/locations',
			'/location',
		);

		foreach ( $candidate_paths as $c_path ) {
			$candidate_url = rtrim( "{$base_scheme}://{$base_host}", '/' ) . $c_path;
			if ( ! in_array( $candidate_url, $subpage_targets, true ) && $candidate_url !== $clean_url ) {
				$subpage_targets[] = $candidate_url;
			}
		}

		// 3. Crawl top candidate subpages (up to 4 pages max) if more emails or phones needed
		if ( count( $all_emails ) < 3 && ! empty( $subpage_targets ) ) {
			foreach ( array_slice( $subpage_targets, 0, 4 ) as $target_sub_url ) {
				$sub_data   = $fetch_and_extract( $target_sub_url );
				$sub_emails = $filter_clean_emails( $sub_data['emails'] );
				foreach ( $sub_emails as $se ) {
					if ( ! in_array( $se, $all_emails, true ) ) {
						$all_emails[] = $se;
					}
				}
				foreach ( $sub_data['phones'] as $sp ) {
					if ( ! in_array( $sp, $all_phones, true ) ) {
						$all_phones[] = $sp;
					}
				}
				if ( count( $all_emails ) >= 3 ) {
					break;
				}
			}
		}

		// 4. DNS MX Check & Domain Email Permutations
		$mx_info = $this->check_domain_mx_records( $base_host );
		$result['mxValid']   = $mx_info['valid'];
		$result['mxRecords'] = $mx_info['records'];
		$result['emailPermutations'] = $this->generate_domain_email_permutations( $lead_name, $base_host );

		$result['emails']       = $all_emails;
		$result['phones']       = $all_phones;
		$result['primaryEmail'] = ! empty( $all_emails ) ? $all_emails[0] : '';
		$result['primaryPhone'] = ! empty( $all_phones ) ? $all_phones[0] : '';

		return $result;
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
				'extractedEmails'      => array(),
				'extractedPhones'      => array(),
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

		$hasWebsite       = false;
		$existingUrl      = null;
		$extracted_emails = array();
		$extracted_phones = array();

		if ($place) {
			$place_id = $place['place_id'];
			$details_url = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields=website&key={$google_key}";
			$det_res = wp_remote_get($details_url, array('timeout' => 15));
			$det_data = json_decode( wp_remote_retrieve_body( $det_res ), true );
			$website = $det_data['result']['website'] ?? null;

			if ($website) {
				// filter out common directories
				$blacklist = array( 'yelp.com', 'healthgrades.com', 'zocdoc.com', 'linkedin.com', 'facebook.com', 'vitals.com', 'webmd.com', 'realtor.com', 'zillow.com' );
				$is_directory = false;
				foreach ($blacklist as $domain) {
					if (strpos(strtolower($website), $domain) !== false) {
						$is_directory = true;
						break;
					}
				}
				if (!$is_directory) {
					$hasWebsite  = true;
					$existingUrl = $website;

					// Scrape website for contact emails, phones, social links, MX status, and permutations
					$scraped          = $this->scrape_website_contact_info( $existingUrl, $leadName );
					$extracted_emails = $scraped['emails'] ?? array();
					$extracted_phones = $scraped['phones'] ?? array();
					$social_profiles  = $scraped['socialProfilesFound'] ?? array();
					$permutations     = $scraped['emailPermutations'] ?? array();
					$mx_valid         = $scraped['mxValid'] ?? false;
					$mx_records       = $scraped['mxRecords'] ?? array();
					$schema_org       = $scraped['schemaOrgData'] ?? null;
				}
			}
		}

		$summary_note = $hasWebsite
			? "Found standalone website: {$existingUrl}" . ( ! empty( $extracted_emails ) ? ( ' (Extracted ' . count( $extracted_emails ) . ' verified email(s): ' . implode( ', ', $extracted_emails ) . ')' ) : '' )
			: "No active standalone custom domain found for {$leadName} in {$city}, {$state}.";

		$result = array(
			'hasWebsite'           => $hasWebsite,
			'existingUrl'          => $existingUrl,
			'status'               => $hasWebsite ? 'Website Found' : 'No Website Found - High Opportunity',
			'summary'              => $summary_note,
			'pitchStrategy'        => $hasWebsite ? 'Pitch SEO/Marketing or upgrade' : 'Pitch turnkey practice website package ($1,650 with 2 years hosting included).',
			'socialProfilesFound'  => $social_profiles ?? array(),
			'extractedEmails'      => $extracted_emails,
			'extractedPhones'      => $extracted_phones,
			'emailPermutations'    => $permutations ?? array(),
			'mxValid'              => $mx_valid ?? false,
			'mxRecords'            => $mx_records ?? array(),
			'schemaOrgData'        => $schema_org ?? null,
			'qualifications'       => array(
				'hasCustomDomain'              => $hasWebsite,
				'domainCheckSummary'           => $hasWebsite ? "Found domain ({$existingUrl})" : "No root domain registered for {$leadName}",
				'hasDirectBookingPortal'       => false,
				'isOnlyDirectoryOrBoardListing'=> !$hasWebsite,
				'digitalFootprintRating'       => $hasWebsite ? 'Established Custom Site' : 'Registry Only',
			),
			'checkedAt'            => gmdate( 'c' ),
		);

		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	/**
	 * Search businesses via Google Places API and filter for missing or directory-only websites.
	 */
	public function handle_places_search_no_website( WP_REST_Request $request ) {
		$body               = $request->get_json_params() ?: array();
		$query_input        = sanitize_text_field( $body['query'] ?? $body['keyword'] ?? '' );
		$profession_input   = sanitize_text_field( $body['profession'] ?? '' );
		$city_input         = sanitize_text_field( $body['city'] ?? '' );
		$state_input        = strtoupper( sanitize_text_field( $body['state'] ?? '' ) );
		$filter_no_website  = ! isset( $body['filter_no_website'] ) || ! empty( $body['filter_no_website'] );
		$min_rating         = isset( $body['min_rating'] ) ? (float) $body['min_rating'] : 0;
		$min_reviews        = isset( $body['min_reviews'] ) ? (int) $body['min_reviews'] : 0;
		$page_token         = sanitize_text_field( $body['pagetoken'] ?? '' );
		$limit              = min( max( (int) ( $body['limit'] ?? 20 ), 1 ), 60 );

		$google_key = $this->get_api_key( 'google' );
		if ( empty( $google_key ) ) {
			return new WP_Error(
				'missing_google_api_key',
				'Google Places API key is not configured. Please add compass_google_places_api_key in WordPress options or .env.',
				array( 'status' => 400 )
			);
		}

		// Compose search query string
		$query_parts = array();
		if ( ! empty( $query_input ) ) {
			$query_parts[] = $query_input;
		} elseif ( ! empty( $profession_input ) ) {
			$query_parts[] = ucwords( str_replace( '_', ' ', $profession_input ) );
		} else {
			$query_parts[] = 'local businesses';
		}

		if ( ! empty( $city_input ) ) {
			$query_parts[] = 'in ' . $city_input;
		}
		if ( ! empty( $state_input ) && ! in_array( $state_input, array( 'ALL', 'NONE', 'US', 'ANY' ), true ) ) {
			$query_parts[] = $state_input;
		}

		$search_query = implode( ' ', $query_parts );
		$encoded_query = urlencode( $search_query );

		$text_search_url = "https://maps.googleapis.com/maps/api/place/textsearch/json?query={$encoded_query}&key={$google_key}";
		if ( ! empty( $page_token ) ) {
			$text_search_url .= "&pagetoken=" . urlencode( $page_token );
		}

		$search_response = wp_remote_get( $text_search_url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $search_response ) ) {
			return new WP_Error(
				'google_places_request_failed',
				'Failed to reach Google Places API: ' . $search_response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $search_response );
		if ( $status_code !== 200 ) {
			return new WP_Error(
				'google_places_http_error',
				"Google Places API responded with HTTP status {$status_code}.",
				array( 'status' => $status_code )
			);
		}

		$search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );
		$api_status  = $search_data['status'] ?? 'UNKNOWN';

		if ( in_array( $api_status, array( 'REQUEST_DENIED', 'OVER_QUERY_LIMIT', 'INVALID_REQUEST' ), true ) ) {
			$error_message = $search_data['error_message'] ?? "Google Places API error: {$api_status}";
			return new WP_Error( 'google_places_error', $error_message, array( 'status' => 400 ) );
		}

		$raw_results     = $search_data['results'] ?? array();
		$next_page_token = $search_data['next_page_token'] ?? null;

		$directory_blacklist = array(
			'facebook.com', 'fb.com', 'm.facebook.com',
			'yelp.com', 'm.yelp.com',
			'yellowpages.com', 'yp.com',
			'instagram.com', 'tiktok.com', 'twitter.com', 'x.com',
			'linkedin.com', 'google.com', 'maps.google.com',
			'bbb.org', 'angieslist.com', 'angi.com', 'thumbtack.com',
			'healthgrades.com', 'zocdoc.com', 'vitals.com', 'webmd.com',
			'realtor.com', 'zillow.com', 'redfin.com',
			'mapquest.com', 'dexknows.com', 'superpages.com', 'manta.com',
			'citysearch.com', 'nextdoor.com', 'merchantcircle.com',
			'foursquare.com', 'houzz.com', 'porch.com', 'homeadvisor.com',
			'alignable.com', 'chamberofcommerce.com', 'dandb.com',
		);

		$places_leads        = array();
		$total_queried       = count( $raw_results );
		$no_website_count    = 0;
		$directory_only_count = 0;
		$has_website_count   = 0;

		foreach ( $raw_results as $place ) {
			if ( count( $places_leads ) >= $limit && ! $filter_no_website ) {
				break;
			}

			$business_status = $place['business_status'] ?? 'OPERATIONAL';
			if ( $business_status === 'CLOSED_PERMANENTLY' ) {
				continue;
			}

			$rating        = isset( $place['rating'] ) ? (float) $place['rating'] : 0.0;
			$reviews_count = isset( $place['user_ratings_total'] ) ? (int) $place['user_ratings_total'] : 0;

			if ( $min_rating > 0 && $rating < $min_rating ) {
				continue;
			}
			if ( $min_reviews > 0 && $reviews_count < $min_reviews ) {
				continue;
			}

			$place_id = $place['place_id'] ?? '';
			if ( empty( $place_id ) ) {
				continue;
			}

			$place_name         = trim( $place['name'] ?? 'Local Business' );
			$formatted_address  = trim( $place['formatted_address'] ?? '' );
			$types              = (array) ( $place['types'] ?? array() );

			// Query Place Details for verified phone, website, and maps URL
			$details_fields = 'name,formatted_phone_number,international_phone_number,website,url,formatted_address,address_components,types,rating,user_ratings_total,opening_hours';
			$details_url    = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields={$details_fields}&key={$google_key}";
			$details_res    = wp_remote_get( $details_url, array( 'timeout' => 15 ) );

			$website_url     = '';
			$formatted_phone = '';
			$maps_url        = $place['url'] ?? "https://www.google.com/maps/place/?q=place_id:{$place_id}";
			$extracted_city  = $city_input;
			$extracted_state = $state_input;

			if ( ! is_wp_error( $details_res ) && wp_remote_retrieve_response_code( $details_res ) === 200 ) {
				$details_data = json_decode( wp_remote_retrieve_body( $details_res ), true );
				$details      = $details_data['result'] ?? array();

				$website_url         = trim( $details['website'] ?? '' );
				$formatted_phone     = trim( $details['formatted_phone_number'] ?? $details['international_phone_number'] ?? '' );
				$international_phone = trim( $details['international_phone_number'] ?? '' );
				if ( ! empty( $details['url'] ) ) {
					$maps_url = $details['url'];
				}
				if ( ! empty( $details['formatted_address'] ) ) {
					$formatted_address = $details['formatted_address'];
				}

				// Extract City and State from address components if missing
				if ( ! empty( $details['address_components'] ) && is_array( $details['address_components'] ) ) {
					foreach ( $details['address_components'] as $component ) {
						$comp_types = $component['types'] ?? array();
						if ( empty( $extracted_city ) && in_array( 'locality', $comp_types, true ) ) {
							$extracted_city = $component['long_name'] ?? '';
						}
						if ( empty( $extracted_state ) && in_array( 'administrative_area_level_1', $comp_types, true ) ) {
							$extracted_state = $component['short_name'] ?? '';
						}
					}
				}
			}

			// Determine website qualification
			$has_standalone_domain = false;
			$website_status        = 'missing';
			$website_summary       = 'Zero Website Detected';

			if ( empty( $website_url ) ) {
				$has_standalone_domain = false;
				$website_status        = 'missing';
				$website_summary       = 'Zero Website Detected (Prime Turnkey Prospect)';
				$no_website_count++;
			} else {
				$is_directory_stub = false;
				$lower_website     = strtolower( $website_url );

				foreach ( $directory_blacklist as $dir_domain ) {
					if ( strpos( $lower_website, $dir_domain ) !== false ) {
						$is_directory_stub = true;
						break;
					}
				}

				if ( $is_directory_stub ) {
					$has_standalone_domain = false;
					$website_status        = 'directory_only';
					$website_summary       = "Social or directory listing only ({$website_url})";
					$directory_only_count++;
				} else {
					$has_standalone_domain = true;
					$website_status        = 'has_website';
					$website_summary       = "Active standalone website: {$website_url}";
					$has_website_count++;
				}
			}

			// If filter_no_website is active, skip businesses with active standalone websites
			if ( $filter_no_website && $has_standalone_domain ) {
				continue;
			}

			// Infer profession / category from search query or Google Place types
			$inferred_profession = $this->infer_profession_from_place( $types, $place_name, $profession_input, $query_input );
			$deal_value          = $this->get_estimated_deal_value( $inferred_profession );
			$preview_slug        = $this->generate_preview_slug( $place_name, 'place-' . $place_id, $extracted_state );

			$places_leads[] = array(
				'id'                 => 'place-' . $place_id,
				'placeId'            => $place_id,
				'fullName'           => $place_name,
				'businessName'       => $place_name,
				'phone'              => $formatted_phone,
				'internationalPhone' => $international_phone,
				'profession'         => $inferred_profession,
				'professionTitle'    => ucwords( str_replace( '_', ' ', $inferred_profession ) ),
				'state'              => $extracted_state ?: ( $state_input ?: 'US' ),
				'city'               => $extracted_city ?: ( $city_input ?: 'Local Metro' ),
				'licenseNumber'      => 'G-MAPS-' . substr( $place_id, 0, 8 ),
				'collegeOrSchool'    => 'Google Verified Business Profile',
				'graduationYear'     => 2026,
				'issueDate'          => gmdate( 'Y-m-d' ),
				'licenseStatus'      => 'Active Business Listing',
				'skipTraceStatus'    => ! empty( $formatted_phone ) ? 'Traced' : 'Not Traced',
				'skipTraceData'      => ! empty( $formatted_phone ) ? array(
					'tracedAt'        => gmdate( 'Y-m-d' ),
					'confidenceScore' => 98,
					'verifiedPhone'   => $formatted_phone,
					'phoneType'       => 'Google Business Line',
					'primaryEmail'    => '',
					'websiteUrl'      => $website_url,
					'currentAddress'  => $formatted_address,
					'enrichmentNotes' => "Google Places Verified (Rating: {$rating} stars across {$reviews_count} reviews)",
				) : null,
				'outreachStatus'     => 'Uncontacted',
				'website'            => $website_url,
				'hasWebsite'         => $has_standalone_domain,
				'websiteStatus'      => $website_status,
				'websiteSummary'     => $website_summary,
				'rating'             => $rating,
				'userRatingsTotal'   => $reviews_count,
				'googleMapsUrl'      => $maps_url,
				'formattedAddress'   => $formatted_address,
				'websiteConfig'      => array(
					'previewSlug'     => $preview_slug,
					'heroHeadline'    => $place_name,
					'heroSubheadline' => "Premier " . ucwords( str_replace( '_', ' ', $inferred_profession ) ) . " Services in " . ( $extracted_city ?: $city_input ) . ", " . ( $extracted_state ?: $state_input ),
					'tagline'         => "Official Digital Portal & Client Intake Engine",
					'previewUrl'      => home_url( "/fresh-mints/#/preview/{$preview_slug}" ),
					'offerPrice'      => $deal_value,
				),
				'estimatedDealValue' => $deal_value,
				'createdAt'          => gmdate( 'c' ),
			);
		}

		$strike_rate = $total_queried > 0 ? round( ( ( $no_website_count + $directory_only_count ) / $total_queried ) * 100 ) : 0;
		$potential_pipeline_value = array_sum( array_column( $places_leads, 'estimatedDealValue' ) );

		return rest_ensure_response( array(
			'success'                => true,
			'query'                  => $search_query,
			'totalQueried'           => $total_queried,
			'totalReturned'          => count( $places_leads ),
			'noWebsiteCount'         => $no_website_count,
			'directoryOnlyCount'     => $directory_only_count,
			'hasWebsiteCount'        => $has_website_count,
			'strikeRatePercentage'   => $strike_rate,
			'potentialPipelineValue' => $potential_pipeline_value,
			'nextPageToken'          => $next_page_token,
			'leads'                  => $places_leads,
		) );
	}

	/**
	 * Infer project profession category from Google Place types, business name, and search queries.
	 */
	private function infer_profession_from_place( $types, $name, $explicit_profession = '', $query = '' ) {
		if ( ! empty( $explicit_profession ) && in_array( $explicit_profession, array(
			'real_estate', 'nursing', 'dental', 'chiropractic', 'therapy', 'beauty',
			'veterinary', 'legal', 'financial_advisor', 'finance', 'insurance', 'trade', 'architecture'
		), true ) ) {
			return $explicit_profession;
		}

		$haystack = strtolower( implode( ' ', (array) $types ) . ' ' . $name . ' ' . $query );

		if ( preg_match( '/\b(plumb|roof|electric|hvac|contractor|carpenter|painter|handyman|mechanic|garage|auto repair|towing|welding)\b/i', $haystack ) ) {
			return 'trade';
		}
		if ( preg_match( '/\b(dent|orthodont|teeth|oral|periodont)\b/i', $haystack ) ) {
			return 'dental';
		}
		if ( preg_match( '/\b(chiro|physio|spinal|adjust)\b/i', $haystack ) ) {
			return 'chiropractic';
		}
		if ( preg_match( '/\b(therap|counsel|psych|mental|mind)\b/i', $haystack ) ) {
			return 'therapy';
		}
		if ( preg_match( '/\b(vet|animal|pet|hospital|clinic)\b/i', $haystack ) ) {
			return 'veterinary';
		}
		if ( preg_match( '/\b(law|attorney|legal|counsel|barrister|solicitor|paralegal)\b/i', $haystack ) ) {
			return 'legal';
		}
		if ( preg_match( '/\b(cpa|account|tax|bookkeep|audit)\b/i', $haystack ) ) {
			return 'finance';
		}
		if ( preg_match( '/\b(wealth|advisor|invest|fiduciary|financial|planner)\b/i', $haystack ) ) {
			return 'financial_advisor';
		}
		if ( preg_match( '/\b(insur|coverage|policy|annuity)\b/i', $haystack ) ) {
			return 'insurance';
		}
		if ( preg_match( '/\b(realt|real estate|broker|property|home|mortgage)\b/i', $haystack ) ) {
			return 'real_estate';
		}
		if ( preg_match( '/\b(spa|salon|beauty|esthetic|nail|hair|lash|skin|barber)\b/i', $haystack ) ) {
			return 'beauty';
		}
		if ( preg_match( '/\b(nurse|home health|care|concierge medical|infusion)\b/i', $haystack ) ) {
			return 'nursing';
		}
		if ( preg_match( '/\b(architect|interior design|drafting|landscape design)\b/i', $haystack ) ) {
			return 'architecture';
		}

		return 'trade';
	}

	/**
	 * Proxy Live Open Data Registry Lookups (CMS NPPES, FINRA BrokerCheck, State Licensing Boards)
	 */
	public function handle_fetch_live_registry( WP_REST_Request $request ) {
		$body        = $request->get_json_params();
		$profession  = $body['profession'] ?? 'financial_advisor';
		$state       = strtoupper( trim( $body['state'] ?? 'AZ' ) );
		$limit       = min( max( (int) ( $body['limit'] ?? 25 ), 5 ), 100 );
		$date_window = ! empty( $body['date_window'] ) && $body['date_window'] !== 'all' ? (int) $body['date_window'] : 0;
		$cutoff_time = $date_window > 0 ? ( time() - ( $date_window * 86400 ) ) : 0;

		$window_label = $date_window > 0 ? " (issued within past {$date_window} days)" : '';

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

			$fetch_limit = $date_window > 0 ? min( $limit * 3, 200 ) : $limit;
			$url = "https://npiregistry.cms.hhs.gov/api/?version=2.1&state={$state}&taxonomy_description=" . urlencode( $taxonomy_desc ) . "&enumeration_type=NPI-1&limit={$fetch_limit}";
			$res = wp_remote_get( $url, array( 'timeout' => 15 ) );

			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
				$leads = array();

				foreach ( ( $data['results'] ?? array() ) as $item ) {
					if ( count( $leads ) >= $limit ) {
						break;
					}

					$basic = $item['basic'] ?? array();
					$addr  = $item['addresses'][0] ?? array();
					$firstName = ucwords( strtolower( trim( $basic['first_name'] ?? '' ) ) );
					$lastName  = ucwords( strtolower( trim( $basic['last_name'] ?? '' ) ) );
					$fullName  = trim( "{$firstName} {$lastName}" );

					if ( empty( $fullName ) ) {
						continue;
					}

					$issueDate      = $basic['enumeration_date'] ?? gmdate( 'Y-m-d' );
					$issueTimestamp = strtotime( $issueDate );

					if ( $cutoff_time > 0 && $issueTimestamp !== false && $issueTimestamp < $cutoff_time ) {
						continue;
					}

					$taxonomy      = $item['taxonomies'][0] ?? array();
					$licenseNumber = ! empty( $taxonomy['license'] ) ? (string) $taxonomy['license'] : ( 'NPI-' . (string) ( $item['number'] ?? '' ) );
					$credential    = ! empty( $basic['credential'] ) ? trim( $basic['credential'] ) : ( $taxonomy['desc'] ?? 'Licensed Practitioner' );
					$phone         = trim( $addr['telephone_number'] ?? '' );
					$city          = ucwords( strtolower( trim( $addr['city'] ?? '' ) ) );
					$gradYear      = (int) substr( $issueDate, 0, 4 );
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
						'groundingNotes' => "Retrieved " . count( $leads ) . " live verified individual {$profession} practitioner records{$window_label} from CMS NPI Registry for {$state}.",
						'totalFound'     => count( $leads ),
					) );
				}
			}
		}

		// 2. Financial Advisors & Wealth Planners: Query Live FINRA BrokerCheck Public API
		if ( $profession === 'financial_advisor' ) {
			$fetch_limit = $date_window > 0 ? min( $limit * 3, 100 ) : $limit;
			$finra_url   = "https://api.brokercheck.finra.org/search/individual?query=advisor&hl=true&includePrevious=true&nrows={$fetch_limit}&start=0&r=25&state={$state}";
			$res         = wp_remote_get( $finra_url, array( 'timeout' => 15 ) );

			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
				$hits  = $data['hits']['hits'] ?? array();
				$leads = array();

				foreach ( $hits as $hit ) {
					if ( count( $leads ) >= $limit ) {
						break;
					}

					$source    = $hit['_source'] ?? array();
					$firstName = ucwords( strtolower( trim( $source['ind_firstname'] ?? '' ) ) );
					$lastName  = ucwords( strtolower( trim( $source['ind_lastname'] ?? '' ) ) );
					$fullName  = trim( "{$firstName} {$lastName}" );

					if ( empty( $fullName ) ) {
						continue;
					}

					$issueDate      = $source['ind_industry_cal_date'] ?? gmdate( 'Y-m-d' );
					$issueTimestamp = strtotime( $issueDate );

					if ( $cutoff_time > 0 && $issueTimestamp !== false && $issueTimestamp < $cutoff_time ) {
						continue;
					}

					$crdNumber   = (string) ( $source['ind_source_id'] ?? '' );
					$emp         = $source['ind_current_employments'][0] ?? array();
					$firmName    = $emp['firm_name'] ?? 'Registered Investment Advisory';
					$city        = ucwords( strtolower( trim( $emp['branch_city'] ?? '' ) ) );
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
						'groundingNotes' => "Retrieved " . count( $leads ) . " live verified individual financial advisor records{$window_label} from FINRA BrokerCheck for {$state}.",
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
				$fetch_limit = $date_window > 0 ? min( $limit * 3, 200 ) : $limit;
				$ny_url      = "https://data.ny.gov/resource/k397-673v.json?\$limit={$fetch_limit}&\$order=issue_date%20DESC&\$q=" . urlencode( $search_term );
				$ny_res      = wp_remote_get( $ny_url, array( 'timeout' => 15 ) );

				if ( ! is_wp_error( $ny_res ) && wp_remote_retrieve_response_code( $ny_res ) === 200 ) {
					$ny_rows = json_decode( wp_remote_retrieve_body( $ny_res ), true );
					if ( is_array( $ny_rows ) && ! empty( $ny_rows ) ) {
						$leads = array();
						foreach ( $ny_rows as $row ) {
							if ( count( $leads ) >= $limit ) {
								break;
							}

							$issueDate      = substr( $row['issue_date'] ?? gmdate( 'Y-m-d' ), 0, 10 );
							$issueTimestamp = strtotime( $issueDate );

							if ( $cutoff_time > 0 && $issueTimestamp !== false && $issueTimestamp < $cutoff_time ) {
								continue;
							}

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
								'groundingNotes' => "Retrieved " . count( $leads ) . " live verified {$profession} practitioner records{$window_label} from NY State Open Data.",
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
			'groundingNotes' => "Live open data registry queries are not available for {$profession} in {$state}{$window_label}. Please use the CSV Importer to upload licensing board roster exports manually.",
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
			'role'         => user_can( $user, 'manage_options' ) ? 'admin' : 'rep',
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
				'role'         => current_user_can( 'manage_options' ) ? 'admin' : 'rep',
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
