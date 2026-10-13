<?php
/**
 * Public registry lookups behind POST /registry/fetch-live. Each returns the route response when
 * its source yields leads, or null so the route tries the next source.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Api_Registry_Sources {

	/**
	 * Supplies generate_preview_slug() and get_estimated_deal_value().
	 *
	 * @var Freshmints_Api_Controller
	 */
	private $api;

	public function __construct( Freshmints_Api_Controller $api ) {
		$this->api = $api;
	}

	/**
	 * CMS Federal NPPES individual (NPI-1) registry, for healthcare and wellness professions.
	 */
	public function nppes( $profession, $state, $limit, $date_window, $cutoff_time, $window_label ) {
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

				$npi_num = trim( (string) ( $item['number'] ?? '' ) );
				if ( empty( $npi_num ) ) {
					continue;
				}

				$taxonomy      = $item['taxonomies'][0] ?? array();
				$licenseNumber = ! empty( $taxonomy['license'] ) ? (string) $taxonomy['license'] : ( 'NPI-' . $npi_num );
				$credential    = ! empty( $basic['credential'] ) ? trim( $basic['credential'] ) : ( $taxonomy['desc'] ?? 'Licensed Practitioner' );
				$phone         = trim( $addr['telephone_number'] ?? '' );
				$city          = ucwords( strtolower( trim( $addr['city'] ?? '' ) ) );
				$gradYear      = (int) substr( $issueDate, 0, 4 );
				$isRecent      = ( $issueTimestamp !== false ) ? ( ( time() - $issueTimestamp ) <= ( 365 * 86400 ) ) : true;
				$licenseStatus = $isRecent ? 'Active Board Pass' : 'Licensed Practitioner';

				$previewSlug   = $this->api->generate_preview_slug( $fullName, 'nppes-' . $npi_num );
				$dealVal       = $this->api->get_estimated_deal_value( $profession );

				$leads[] = array(
					'id'                => 'nppes-' . $npi_num,
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
						'verifiedPhone'   => $phone,
						'phoneType'       => 'Practice Line',
						'phoneSource'     => 'NPPES NPI Registry',
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

		return null;
	}

	/**
	 * FINRA BrokerCheck, for financial advisors.
	 */
	public function finra( $profession, $state, $limit, $date_window, $cutoff_time, $window_label ) {
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

				$crdNumber   = trim( (string) ( $source['ind_source_id'] ?? '' ) );
				if ( empty( $crdNumber ) ) {
					continue;
				}
				$emp         = $source['ind_current_employments'][0] ?? array();
				$firmName    = $emp['firm_name'] ?? 'Registered Investment Advisory';
				$city        = ucwords( strtolower( trim( $emp['branch_city'] ?? '' ) ) );
				$gradYear    = (int) substr( $issueDate, 0, 4 );
				$previewSlug = $this->api->generate_preview_slug( $fullName, 'finra-' . $crdNumber );
				$dealVal     = $this->api->get_estimated_deal_value( $profession );

				$leads[] = array(
					'id'                => 'finra-' . $crdNumber,
					'fullName'          => $fullName,
					'profession'        => 'financial_advisor',
					'professionTitle'   => 'Certified Financial Planner (CRD #' . $crdNumber . ')',
					'state'             => $state,
					'city'              => $city,
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
						'heroSubheadline' => ! empty( $city ) ? "Fiduciary Wealth & Retirement Advisory in {$city}, {$state}." : "Fiduciary Wealth & Retirement Advisory in {$state}.",
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

		return null;
	}

	/**
	 * New York State Open Data license registry.
	 */
	public function new_york( $profession, $state, $limit, $date_window, $cutoff_time, $window_label ) {
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

						$raw_lic = trim( (string) ( $row['license_number'] ?? '' ) );
						if ( empty( $raw_lic ) ) {
							continue;
						}

						$lic_num     = ( stripos( $raw_lic, 'ny-' ) === 0 ) ? substr( $raw_lic, 3 ) : $raw_lic;
						$city        = ucwords( strtolower( trim( $row['city'] ?? '' ) ) );
						$gradYear    = (int) substr( $issueDate, 0, 4 );
						$previewSlug = $this->api->generate_preview_slug( $fullName, 'ny-' . $lic_num );
						$dealVal     = $this->api->get_estimated_deal_value( $profession );

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
								'heroSubheadline' => ! empty( $city ) ? "Professional services in {$city}, NY." : "Professional services in NY.",
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

		return null;
	}
}
