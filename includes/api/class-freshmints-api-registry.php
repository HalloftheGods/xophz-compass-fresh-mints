<?php
/**
 * POST /registry/fetch-live (manage_options): newly licensed professionals from public registries.
 * The NPPES, FINRA and New York lookups live in Freshmints_Api_Registry_Sources; the insurance
 * producer registries in Freshmints_Registry_Producers.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-api-controller.php';
require_once __DIR__ . '/class-freshmints-api-registry-sources.php';

class Freshmints_Api_Registry extends Freshmints_Api_Controller {

	public function register_routes() {
		register_rest_route( 'xophz-freshmints/v1', '/registry/fetch-live', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_fetch_live_registry' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
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

		$sources = new Freshmints_Api_Registry_Sources( $this );

		// 1. Healthcare & Wellness Practitioners: Query CMS Federal NPPES Individual (NPI-1) Registry
		if ( in_array( $profession, array( 'nursing', 'therapy', 'dental', 'chiropractic', 'medical', 'veterinary' ), true ) ) {
			$response = $sources->nppes( $profession, $state, $limit, $date_window, $cutoff_time, $window_label );
			if ( $response ) {
				return $response;
			}
		}

		// 2. Financial Advisors & Wealth Planners: Query Live FINRA BrokerCheck Public API
		if ( $profession === 'financial_advisor' ) {
			$response = $sources->finra( $profession, $state, $limit, $date_window, $cutoff_time, $window_label );
			if ( $response ) {
				return $response;
			}
		}

		// 3. State Socrata Open License Registries (e.g. NY State Open Data)
		if ( $state === 'NY' ) {
			$response = $sources->new_york( $profession, $state, $limit, $date_window, $cutoff_time, $window_label );
			if ( $response ) {
				return $response;
			}
		}

		if ( $profession === 'insurance' && Freshmints_Registry_Producers::supports( $state ) ) {
			$producer_leads = Freshmints_Registry_Producers::fetch( $state, $limit, (string) $date_window );

			if ( ! empty( $producer_leads ) ) {
				$labels = Freshmints_Registry_Producers::describe( $state );
				$notes  = "Retrieved " . count( $producer_leads ) . " live verified life insurance producer records{$window_label} from " . ( $labels['portal'] ?? "the {$state} open data portal" ) . '.';

				// The data's licence terms travel with it (Iowa: CC BY 4.0). Texas asks for none.
				if ( ! empty( $labels['attribution'] ) ) {
					$notes .= ' ' . $labels['attribution'];
				}

				return rest_ensure_response( array(
					'success'        => true,
					'source'         => $labels['source'] ?? $this->get_regulatory_board_name( $profession, $state ),
					'leads'          => $producer_leads,
					'groundingNotes' => $notes,
					'totalFound'     => count( $producer_leads ),
				) );
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
}
