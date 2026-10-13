<?php
/**
 * Wires the server-side HighLevel deliveries: new Agent Finder agents and captured Lead Magnet leads.
 * Loaded and registered from the bottom of class-freshmints-crm-webhook-rest.php.
 *
 * Hooks
 *   freshmints_agent_finder_new_agents   the sync stored new agents: queue a delivery pass.
 *   freshmints_agent_finder_weekly_sync  after the sync (priority 20): queue a pass when pending or failed
 *                                        rows are left over, so a week with no new agents still retries.
 *   freshmints_crm_deliver_agents        the pass itself, an Action Scheduler async action (WP-Cron when
 *                                        Action Scheduler is not loaded).
 *   xophz_lead_magnet_lead_captured      handled by Freshmints_Crm_Lead_Delivery.
 *
 * One pass sends up to BATCH_SIZE agents in id order, stops at TIME_BUDGET_SECONDS, and queues the next pass
 * until nothing is left, so a 600-agent week is a dozen short requests and never one long one. Each send is
 * recorded with Freshmints_Agent_Finder_Store::record_delivery(). A pass visits every row once (a cursor
 * rides on the queued action), so a failed row is tried again on the next pass, not inside this one, and a
 * row stops being tried after MAX_ATTEMPTS. Three failures in a row end the pass: the webhook is down, and
 * burning attempts on the rest would only use them up. The rest stay as they were for the next run.
 *
 * No webhook configured: nothing is queued or sent, rows stay `pending`, and summary() says
 * "HighLevel webhook not configured". summary() is added to the Agent Finder runs and agents REST responses
 * as `delivery`.
 *
 * POST /wp-json/xophz-freshmints/v1/crm/retry-deliveries   manage_options only
 *   body: reset_attempts (bool, default false) gives failed agents and leads their attempts back first.
 *   Runs one pass now. 400 freshmints_crm_webhook_not_configured when there is no webhook.
 *   -> { agents: {sent, failed, stopped, remaining}, leads: {delivered, failed, remaining}, delivery }
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-crm-payloads.php';
require_once __DIR__ . '/class-freshmints-crm-lead-delivery.php';

class Freshmints_Crm_Delivery {

	const REST_NAMESPACE = 'xophz-freshmints/v1';
	const HOOK_AGENTS    = 'freshmints_crm_deliver_agents';
	const GROUP          = 'freshmints-crm-delivery';
	const LOCK_OPTION    = 'freshmints_crm_delivery_lock';
	const LAST_OPTION    = 'freshmints_crm_delivery_last';

	const BATCH_SIZE          = 50;
	const TIME_BUDGET_SECONDS = 20;
	const MAX_ATTEMPTS        = 5;
	const BREAKER_FAILURES    = 3;
	const LOCK_TTL_SECONDS    = 600;
	const PAUSE_MICROSECONDS  = 100000;

	const NOT_CONFIGURED = 'HighLevel webhook not configured';
	const WEBHOOK_DOWN    = 'HighLevel is not accepting deliveries right now. Failed agents are retried on the next sync run.';

	public static function register(): void {
		add_action( 'freshmints_agent_finder_new_agents', array( __CLASS__, 'on_new_agents' ) );
		add_action( 'freshmints_agent_finder_weekly_sync', array( __CLASS__, 'on_weekly_sync' ), 20 );
		add_action( self::HOOK_AGENTS, array( __CLASS__, 'run_batch' ) );
		add_filter( 'freshmints_agent_finder_delivery_summary', array( __CLASS__, 'summary' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		Freshmints_Crm_Lead_Delivery::register();
	}

	/** @param mixed $agent_ids Ids of the agents the sync just stored; the pass reads the rows from the store. */
	public static function on_new_agents( $agent_ids ): void {
		if ( is_array( $agent_ids ) && $agent_ids !== array() && Freshmints_Crm_Webhook_Rest::is_configured() ) {
			self::schedule_batch();
		}
	}

	public static function on_weekly_sync(): void {
		if ( Freshmints_Crm_Webhook_Rest::is_configured() && Freshmints_Agent_Finder_Store::count_deliverable( self::MAX_ATTEMPTS ) > 0 ) {
			self::schedule_batch();
		}
	}

	/**
	 * One delivery pass over the agents still owed, from the row after $after_id.
	 *
	 * @param mixed $after_id Cursor: the last agent id the previous pass of this run handled.
	 * @return array{sent:int,failed:int,stopped:string,remaining:int,last_id:int} stopped is '' when the pass
	 *         ended on its own, else not_configured, circuit_open or running (another pass holds the lock).
	 */
	public static function run_batch( $after_id = 0 ): array {
		$result = array( 'sent' => 0, 'failed' => 0, 'stopped' => '', 'remaining' => 0, 'last_id' => max( 0, (int) $after_id ) );

		if ( ! Freshmints_Crm_Webhook_Rest::is_configured() ) {
			$result['stopped']   = 'not_configured';
			$result['remaining'] = Freshmints_Agent_Finder_Store::count_deliverable( self::MAX_ATTEMPTS, $result['last_id'] );
			return $result;
		}

		if ( ! self::acquire_lock() ) {
			$result['stopped'] = 'running';
			return $result;
		}

		try {
			$result = self::send_pass( $result );
		} finally {
			self::release_lock();
		}

		update_option( self::LAST_OPTION, array(
			'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sent'    => $result['sent'],
			'failed'  => $result['failed'],
			'stopped' => $result['stopped'],
		), false );

		if ( $result['stopped'] === '' && $result['remaining'] > 0 ) {
			self::schedule_batch( $result['last_id'] );
		}

		return $result;
	}

	private static function send_pass( array $result ): array {
		$relay   = new Freshmints_Crm_Webhook_Rest();
		$budget  = (float) apply_filters( 'freshmints_crm_delivery_time_budget', self::TIME_BUDGET_SECONDS );
		$pause   = (int) apply_filters( 'freshmints_crm_delivery_pause', self::PAUSE_MICROSECONDS );
		$started = microtime( true );
		$streak  = 0;

		foreach ( Freshmints_Agent_Finder_Store::deliverable_agents( self::BATCH_SIZE, self::MAX_ATTEMPTS, $result['last_id'] ) as $row ) {
			if ( ( microtime( true ) - $started ) >= $budget ) {
				break;
			}

			$sent = $relay->send_event( Freshmints_Crm_Payloads::EVENT_AGENT, Freshmints_Crm_Payloads::agent( $row ) );
			if ( ! $sent['configured'] ) {
				$result['stopped'] = 'not_configured';
				break;
			}

			Freshmints_Agent_Finder_Store::record_delivery( (int) $row['id'], $sent['ok'] );
			$result['last_id'] = (int) $row['id'];

			if ( $sent['ok'] ) {
				++$result['sent'];
				$streak = 0;
			} else {
				++$result['failed'];
				if ( ++$streak >= self::BREAKER_FAILURES ) {
					$result['stopped'] = 'circuit_open';
					break;
				}
			}

			if ( $pause > 0 ) {
				usleep( $pause );
			}
		}

		$result['remaining'] = Freshmints_Agent_Finder_Store::count_deliverable( self::MAX_ATTEMPTS, $result['last_id'] );

		return $result;
	}

	/**
	 * Where delivery stands, for the Agent Finder REST responses and the retry route. Counts and flags only.
	 * Also the callback of the filter `freshmints_agent_finder_delivery_summary`.
	 *
	 * @param mixed $unused The filter's incoming value.
	 */
	public static function summary( $unused = null ): array {
		$configured = Freshmints_Crm_Webhook_Rest::is_configured();
		$last       = get_option( self::LAST_OPTION, array() );
		$last       = is_array( $last ) && $last !== array() ? $last : null;

		$message = '';
		if ( ! $configured ) {
			$message = self::NOT_CONFIGURED;
		} elseif ( $last !== null && ( $last['stopped'] ?? '' ) === 'circuit_open' ) {
			$message = self::WEBHOOK_DOWN;
		}

		return array(
			'configured'  => $configured,
			'message'     => $message,
			'maxAttempts' => self::MAX_ATTEMPTS,
			'agents'      => Freshmints_Agent_Finder_Store::delivery_counts( self::MAX_ATTEMPTS ),
			'leads'       => Freshmints_Crm_Lead_Delivery::counts(),
			'lastBatch'   => $last,
		);
	}

	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/crm/retry-deliveries', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_retry' ),
			'permission_callback' => array( __CLASS__, 'can_retry' ),
			'args'                => array(
				'reset_attempts' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
	}

	public static function can_retry(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function handle_retry( WP_REST_Request $request ) {
		if ( ! Freshmints_Crm_Webhook_Rest::is_configured() ) {
			return new WP_Error(
				'freshmints_crm_webhook_not_configured',
				self::NOT_CONFIGURED,
				array( 'status' => 400, 'connector' => Freshmints_Crm_Webhook_Rest::CONNECTOR_ID )
			);
		}

		if ( rest_sanitize_boolean( $request['reset_attempts'] ) ) {
			Freshmints_Agent_Finder_Store::reset_failed_attempts();
			Freshmints_Crm_Lead_Delivery::reset_failed_attempts();
		}

		$agents = self::run_batch( 0 );
		unset( $agents['last_id'] );

		return new WP_REST_Response( array(
			'agents'   => $agents,
			'leads'    => Freshmints_Crm_Lead_Delivery::retry( self::TIME_BUDGET_SECONDS / 2 ),
			'delivery' => self::summary(),
		) );
	}

	/**
	 * Run `$hook` soon in the background: an Action Scheduler async action, or a WP-Cron single event when
	 * Action Scheduler is not loaded. A positive $delay (seconds) schedules it later.
	 */
	public static function enqueue( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( self::use_action_scheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), $hook, $args );
	}

	/** Queue a delivery pass unless one is already waiting. $after_id carries the cursor of a continuing run. */
	public static function schedule_batch( int $after_id = 0 ): void {
		if ( ! self::batch_waiting() ) {
			self::enqueue( self::HOOK_AGENTS, $after_id > 0 ? array( $after_id ) : array() );
		}
	}

	/** True when a pass is queued and has not started. A pass that is running does not count: it queues its own successor. */
	private static function batch_waiting(): bool {
		if ( self::use_action_scheduler() ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK_AGENTS,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);
			return ! empty( $ids );
		}

		// WP-Cron looks events up by hook and exact args, and a continuing pass carries a cursor, so scan the hook.
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ self::HOOK_AGENTS ] ) ) {
				return true;
			}
		}

		return false;
	}

	/** Action Scheduler (shipped with WooCommerce) when it is loaded; the filter forces WP-Cron. */
	private static function use_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_get_scheduled_actions' )
			&& class_exists( 'ActionScheduler' )
			&& ActionScheduler::is_initialized()
			&& apply_filters( 'freshmints_crm_delivery_use_action_scheduler', true );
	}

	/** One pass at a time: add_option is atomic, and a lock older than LOCK_TTL_SECONDS (a dead pass) is taken over. */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( ( time() - (int) get_option( self::LOCK_OPTION ) ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
