<?php
/**
 * POST /leads/check-website (manage_options): does a lead already have a standalone website.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';
require_once __DIR__ . '/class-freshmints-api-website-scraper.php';

class Freshmints_Api_Leads extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/leads/check-website', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_check_website' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
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
					$scraped          = Freshmints_Api_Website_Scraper::scrape_website_contact_info( $existingUrl, $leadName );
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
}
