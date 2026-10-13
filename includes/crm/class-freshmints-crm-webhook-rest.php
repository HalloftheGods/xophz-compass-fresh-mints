<?php
/**
 * Server-side relay for the CRM lead webhook (Zapier, Make, HubSpot, GoHighLevel).
 *
 * A CRM webhook URL is a bearer credential: anyone holding it can write into the CRM. It is
 * resolved here through the WordPress Connectors API and never sent to, or stored in, the
 * browser. The browser only says "send these leads"; this class makes the one POST.
 *
 * POST /wp-json/xophz-freshmints/v1/crm/webhook
 *   body:     { event, data }   data is one lead object, or a list of lead objects
 *   success:  { success: true, message, status }
 *   errors:   freshmints_crm_webhook_not_configured (400), freshmints_crm_webhook_invalid (400),
 *             freshmints_crm_webhook_unreachable (502), freshmints_crm_webhook_rejected (502)
 *
 * Error responses carry only the connector id, an error code and the webhook's HTTP status.
 * They never carry the webhook URL or the text of a transport error.
 *
 * Server-side sends: send_event() lets plugin code (the Agent Finder and Lead Magnet deliveries in
 * class-freshmints-crm-delivery.php) post a SERVER_EVENTS event through the same connector. It returns
 * { ok, configured, code, status } and nothing else, so no caller can read or log the URL. The browser
 * route does not accept SERVER_EVENTS: only server code can say a lead was captured or an agent was found.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Freshmints_Crm_Webhook_Rest {

	const REST_NAMESPACE    = 'xophz-freshmints/v1';
	const CONNECTOR_ID      = 'crm_webhook_url';
	const SOURCE            = 'Fresh Mints Lead Platform';
	const TIMEOUT           = 15;
	const MAX_LEADS         = 500;
	const MAX_DEPTH         = 6;
	const MAX_STRING_LEN    = 5000;
	const MAX_PAYLOAD_BYTES = 4194304;

	const EVENTS = array(
		'lead.created',
		'lead.skiptraced',
		'lead.outreach_sent',
		'lead.site_pitch',
		'batch.export',
		'leads.bulk_synced',
	);

	/** Events only server code may send (send_event()); the browser relay route rejects them. */
	const SERVER_EVENTS = array(
		'agent_finder_new_agent',
		'lead_magnet_opt_in',
	);

	public function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/crm/webhook', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_relay' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );
	}

	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/** True when the crm_webhook_url connector holds a value. The value itself never leaves this class. */
	public static function is_configured(): bool {
		return Freshmints_Connectors::get_key( self::CONNECTOR_ID ) !== '';
	}

	public function handle_relay( WP_REST_Request $request ) {
		$webhook_url = Freshmints_Connectors::get_key( self::CONNECTOR_ID );
		if ( $webhook_url === '' ) {
			return new WP_Error(
				'freshmints_crm_webhook_not_configured',
				'No CRM webhook is configured. Add the CRM Webhook URL under Settings > Connectors.',
				array( 'status' => 400, 'connector' => self::CONNECTOR_ID )
			);
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$payload = $this->build_payload( $body );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$status = $this->post_to_webhook( $webhook_url, $payload );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Payload synced to the connected CRM webhook.',
			'status'  => $status,
		) );
	}

	/**
	 * Post one server-side event (one lead or agent) through the crm_webhook_url connector.
	 *
	 * Nothing is sent when the connector is empty. The result never carries the URL or a transport message.
	 *
	 * @param string $event One of SERVER_EVENTS.
	 * @param array  $data  One string-keyed object; cleaned the same way as the browser relay's leads.
	 * @return array{ok:bool,configured:bool,code:string,status:int} code is '' on success, else the
	 *               freshmints_crm_webhook_* error code; status is the webhook's HTTP status, or 0.
	 */
	public function send_event( string $event, array $data ): array {
		$webhook_url = Freshmints_Connectors::get_key( self::CONNECTOR_ID );
		if ( $webhook_url === '' ) {
			return self::outcome( false, false, 'freshmints_crm_webhook_not_configured', 0 );
		}

		if ( ! in_array( $event, self::SERVER_EVENTS, true ) || $data === array() || $this->is_list( $data ) ) {
			return self::outcome( false, true, 'freshmints_crm_webhook_invalid', 0 );
		}

		$payload = $this->envelope( $event, 1, $this->clean_value( $data, 0 ) );
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return self::outcome( false, true, 'freshmints_crm_webhook_invalid', 0 );
		}

		$status = $this->post_to_webhook( $webhook_url, $payload );
		if ( is_wp_error( $status ) ) {
			$info = $status->get_error_data();
			return self::outcome( false, true, $status->get_error_code(), (int) ( is_array( $info ) ? ( $info['webhook_status'] ?? 0 ) : 0 ) );
		}

		return self::outcome( true, true, '', $status );
	}

	private static function outcome( bool $ok, bool $configured, string $code, int $status ): array {
		return array( 'ok' => $ok, 'configured' => $configured, 'code' => $code, 'status' => $status );
	}

	/**
	 * The one POST to the webhook, shared by the browser relay and send_event().
	 *
	 * @return int|WP_Error The webhook's HTTP status for a 2xx answer, else a WP_Error without the URL.
	 */
	private function post_to_webhook( string $webhook_url, array $payload ) {
		// wp_safe_remote_post refuses loopback and private-network hosts, so the stored URL
		// cannot be pointed at an internal service.
		$response = wp_safe_remote_post( $webhook_url, array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'freshmints_crm_webhook_unreachable',
				'The CRM webhook could not be reached.',
				array( 'status' => 502, 'transport' => $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'freshmints_crm_webhook_rejected',
				"The CRM webhook responded with HTTP {$status}.",
				array( 'status' => 502, 'webhook_status' => $status )
			);
		}

		return $status;
	}

	private function envelope( string $event, int $count, $data ): array {
		return array(
			'event'     => $event,
			'timestamp' => gmdate( 'c' ),
			'count'     => $count,
			'data'      => $data,
			'source'    => self::SOURCE,
		);
	}

	/**
	 * The envelope the webhook receives, built from validated fields only.
	 *
	 * @return array|WP_Error WP_Error when the event is unknown or there is no lead to send
	 */
	private function build_payload( array $body ) {
		$event = $body['event'] ?? '';
		if ( ! is_string( $event ) || ! in_array( $event, self::EVENTS, true ) ) {
			return $this->invalid( 'A CRM webhook relay needs a known event.' );
		}

		$data = $body['data'] ?? null;
		if ( ! is_array( $data ) || empty( $data ) ) {
			return $this->invalid( 'A CRM webhook relay needs at least one lead.' );
		}

		$is_list = $this->is_list( $data );
		$leads   = $is_list ? $data : array( $data );
		if ( count( $leads ) > self::MAX_LEADS ) {
			return $this->invalid( 'A CRM webhook relay accepts at most ' . self::MAX_LEADS . ' leads at a time.' );
		}

		$clean = array();
		foreach ( $leads as $lead ) {
			$lead = is_array( $lead ) && ! $this->is_list( $lead ) ? $this->clean_value( $lead, 0 ) : null;
			if ( ! is_array( $lead ) || $this->text( $lead['fullName'] ?? '' ) === '' ) {
				return $this->invalid( 'Every lead needs a name.' );
			}
			$clean[] = $lead;
		}

		$payload = $this->envelope( $event, count( $clean ), $is_list ? $clean : $clean[0] );

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return $this->invalid( 'The CRM webhook payload is too large.' );
		}

		return $payload;
	}

	/**
	 * Keeps scalars, lists and string-keyed maps up to a fixed depth. Objects, resources and
	 * keys that are not plain identifiers are dropped, and long strings are cut.
	 */
	private function clean_value( $value, int $depth ) {
		if ( is_string( $value ) ) {
			return mb_substr( $value, 0, self::MAX_STRING_LEN );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || $value === null ) {
			return $value;
		}

		if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
			return null;
		}

		$is_list = $this->is_list( $value );
		$clean   = array();
		foreach ( $value as $key => $item ) {
			if ( $is_list ) {
				$clean[] = $this->clean_value( $item, $depth + 1 );
				continue;
			}
			if ( is_string( $key ) && preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $key ) === 1 ) {
				$clean[ $key ] = $this->clean_value( $item, $depth + 1 );
			}
		}

		return $clean;
	}

	private function is_list( array $value ): bool {
		return $value === array_values( $value );
	}

	private function text( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	private function invalid( string $message ): WP_Error {
		return new WP_Error( 'freshmints_crm_webhook_invalid', $message, array( 'status' => 400 ) );
	}
}

// The server-side deliveries (new Agent Finder agents, captured Lead Magnet leads) ride on send_event(),
// so they hook themselves in from here: this file is already loaded by the plugin bootstrap.
require_once __DIR__ . '/class-freshmints-crm-delivery.php';
Freshmints_Crm_Delivery::register();
