<?php
/**
 * POST /places/search-no-website (manage_options): Google Places businesses with no website or
 * only a directory listing.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';

class Freshmints_Api_Places extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/places/search-no-website', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_places_search_no_website' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
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
				'Google Places API key is not configured. Set the Google Places API key under Settings > Connectors.',
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

			$website_url         = '';
			$formatted_phone     = '';
			$international_phone = '';
			$maps_url            = $place['url'] ?? "https://www.google.com/maps/place/?q=place_id:{$place_id}";
			$extracted_city      = $city_input;
			$extracted_state     = $state_input;

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
					'verifiedPhone'   => $formatted_phone,
					'phoneType'       => 'Google Business Line',
					'phoneSource'     => 'Google Places',
					'primaryEmail'    => '',
					'websiteUrl'      => $website_url,
					'currentAddress'  => $formatted_address,
					'enrichmentNotes' => "Phone from the Google Places listing (rating: {$rating} stars across {$reviews_count} reviews). Not independently verified.",
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
}
