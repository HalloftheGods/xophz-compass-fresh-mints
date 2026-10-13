<?php
/**
 * Agent Finder weekly sync: pulls newly licensed Texas insurance agents from the registry and stores each
 * one once, by NPN, for the delivery step (#6024) to send on.
 *
 * One run:
 *   1. picks a window: 7 days, widened to cover the time since the last FULLY completed run (cap 30 days);
 *   2. calls Freshmints_Registry_Producers::fetch( 'TX', LEAD_LIMIT, <days>, true ), which already returns
 *      only people whose first Texas licence of any kind falls in the window, and reads last_stats();
 *   3. stores the people not stored before (Freshmints_Agent_Finder_Store, unique NPN);
 *   4. records the run and fires `freshmints_agent_finder_new_agents` with the new agent ids.
 *
 * A run whose stats say complete=false (a request failed, the row cap or the time budget stopped it) is
 * recorded as partial. Its agents are kept, each one was verified, but it does not count as completed, so
 * the next run's window still reaches back to the last run that did. A window longer than the cap is
 * recorded as complete with error code `window_capped`: the gap beyond 30 days cannot be recovered.
 *
 * Nothing is delivered here. Listeners of the action receive the ids of the agents this run inserted;
 * pending and failed rows from earlier runs stay queryable by delivery status (REST or the store).
 *
 * Scheduling: Action Scheduler (WooCommerce ships it) when it is loaded, else WP-Cron, once a week.
 * Scheduling is idempotent and is re-checked on `init` (at most every SCHEDULE_CHECK_SECONDS).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-freshmints-agent-finder-store.php';

class Freshmints_Agent_Finder_Sync {

	const HOOK_SYNC          = 'freshmints_agent_finder_weekly_sync';
	const ACTION_NEW_AGENTS  = 'freshmints_agent_finder_new_agents';
	const SCHEDULER_GROUP    = 'freshmints-agent-finder';
	const SCHEDULE_OPTION    = 'freshmints_agent_finder_schedule';
	const LOCK_OPTION        = 'freshmints_agent_finder_sync_lock';

	const STATE                  = 'TX';
	const DEFAULT_WINDOW_DAYS    = 7;
	const MAX_WINDOW_DAYS        = 30;
	const LEAD_LIMIT             = 10000;
	const TIME_BUDGET_SECONDS    = 120;
	const LOCK_TTL_SECONDS       = 900;
	const SCHEDULE_CHECK_SECONDS = 21600;

	/** The qualifications that carry annuity authority (decision on #6021); every other life line is stored with annuity_capable = 0. */
	const ANNUITY_QUALIFICATIONS = array(
		'Life, Accident, Health & HMO',
		'Life Agent/Agency',
	);

	public function register(): void {
		add_action( 'init', array( $this, 'on_init' ), 20 );
		add_action( self::HOOK_SYNC, array( $this, 'run_scheduled' ) );
	}

	public function on_init(): void {
		Freshmints_Agent_Finder_Store::maybe_install();
		self::ensure_scheduled( false );
	}

	public function run_scheduled(): void {
		$this->run( 'cron' );
	}

	/** Plugin activation: create the tables and the weekly schedule. */
	public static function activate(): void {
		Freshmints_Agent_Finder_Store::install();
		self::ensure_scheduled( true );
	}

	/** Plugin deactivation: remove the weekly schedule from both schedulers. The tables and rows stay. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK_SYNC );
		if ( self::scheduler_loaded() ) {
			as_unschedule_all_actions( self::HOOK_SYNC );
		}
		delete_option( self::SCHEDULE_OPTION );
	}

	/**
	 * Make sure exactly one weekly schedule exists. $force skips the SCHEDULE_CHECK_SECONDS throttle.
	 * Action Scheduler wins when it is loaded, and the WP-Cron event is removed so a week never runs twice;
	 * with no Action Scheduler (or the filter `freshmints_agent_finder_use_action_scheduler` returning false)
	 * the WP-Cron event is used and any Action Scheduler action for the hook is removed.
	 */
	public static function ensure_scheduled( bool $force ): void {
		$loaded = self::scheduler_loaded();
		$kind   = $loaded && apply_filters( 'freshmints_agent_finder_use_action_scheduler', true ) ? 'action-scheduler' : 'wp-cron';
		$state  = get_option( self::SCHEDULE_OPTION );

		if ( ! $force && is_array( $state ) && ( $state['kind'] ?? '' ) === $kind
			&& ( time() - (int) ( $state['checked'] ?? 0 ) ) < self::SCHEDULE_CHECK_SECONDS ) {
			return;
		}

		$first_run = (int) strtotime( 'next monday 12:00:00 UTC' );

		if ( $kind === 'action-scheduler' ) {
			wp_clear_scheduled_hook( self::HOOK_SYNC );
			if ( ! as_next_scheduled_action( self::HOOK_SYNC, array(), self::SCHEDULER_GROUP ) ) {
				as_schedule_recurring_action( $first_run, WEEK_IN_SECONDS, self::HOOK_SYNC, array(), self::SCHEDULER_GROUP );
			}
		} else {
			if ( $loaded ) {
				as_unschedule_all_actions( self::HOOK_SYNC );
			}
			if ( ! wp_next_scheduled( self::HOOK_SYNC ) ) {
				wp_schedule_event( $first_run, 'weekly', self::HOOK_SYNC );
			}
		}

		update_option( self::SCHEDULE_OPTION, array( 'kind' => $kind, 'checked' => time() ), true );
	}

	private static function scheduler_loaded(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& class_exists( 'ActionScheduler' )
			&& ActionScheduler::is_initialized();
	}

	/**
	 * Run one sync now.
	 *
	 * @param string $trigger 'cron' or 'manual'.
	 * @return array|WP_Error The finished run row (keys of the runs table, plus `new_agent_ids`), or an error when a run is already going.
	 */
	public function run( string $trigger = 'manual' ) {
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'freshmints_agent_finder_running', 'An Agent Finder sync is already running.', array( 'status' => 409 ) );
		}

		try {
			$row = $this->execute( $trigger );
		} finally {
			$this->release_lock();
		}

		if ( $row['new_agent_ids'] ) {
			/**
			 * New agents were stored. Delivery (#6024) listens here; the sync delivers nothing itself.
			 *
			 * @param int[] $new_agent_ids Ids in the agents table of the rows this run inserted.
			 */
			do_action( self::ACTION_NEW_AGENTS, $row['new_agent_ids'] );
		}

		return $row;
	}

	/** The run itself, with the lock held: window, fetch, store, record. */
	private function execute( string $trigger ): array {
		$started = microtime( true );
		$now     = time();
		$wanted  = $this->wanted_days( $now );
		$days    = min( self::MAX_WINDOW_DAYS, $wanted );
		$capped  = $wanted > self::MAX_WINDOW_DAYS;
		$run_id  = Freshmints_Agent_Finder_Store::start_run(
			$trigger,
			(int) strtotime( Freshmints_Registry_Producers::window_start( (string) $days ) . ' UTC' ),
			$now
		);

		$budget = static function () {
			return (float) apply_filters( 'freshmints_agent_finder_time_budget', self::TIME_BUDGET_SECONDS );
		};
		$leads  = array();
		$stats  = array();
		$thrown = false;

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( self::TIME_BUDGET_SECONDS + 120 );
			}
			add_filter( 'freshmints_registry_producers_time_budget', $budget, 20 );
			$leads = Freshmints_Registry_Producers::fetch( self::STATE, self::LEAD_LIMIT, (string) $days, true );
			$stats = Freshmints_Registry_Producers::last_stats();
		} catch ( \Throwable $e ) {
			$thrown = true;
		} finally {
			remove_filter( 'freshmints_registry_producers_time_budget', $budget, 20 );
		}

		$agents  = array();
		$skipped = (int) ( $stats['rows_without_npn'] ?? 0 );
		foreach ( $leads as $lead ) {
			$agent = self::agent_from_lead( $lead );
			if ( $agent === null ) {
				++$skipped;
				continue;
			}
			$agents[ $agent['npn'] ] = $agent;
		}

		$new_ids = $agents ? Freshmints_Agent_Finder_Store::insert_new_agents( array_values( $agents ), $run_id ) : array();
		$error   = self::error_code( $thrown, $stats, count( $leads ), $capped );

		Freshmints_Agent_Finder_Store::finish_run( $run_id, array(
			'found'       => count( $agents ),
			'new_count'   => count( $new_ids ),
			'skipped'     => $skipped,
			'partial'     => $error !== '' && $error !== 'window_capped',
			'error_code'  => $error,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
		) );

		$row                  = Freshmints_Agent_Finder_Store::get_run( $run_id ) ?? array();
		$row['new_agent_ids'] = $new_ids;
		return $row;
	}

	/**
	 * Days the next window should reach back, before the cap: 7 normally, more to cover the time since the
	 * last completed run.
	 */
	private function wanted_days( int $now ): int {
		$last_end = Freshmints_Agent_Finder_Store::last_complete_window_end();
		if ( $last_end === null ) {
			return self::DEFAULT_WINDOW_DAYS;
		}
		return max( self::DEFAULT_WINDOW_DAYS, (int) ceil( max( 0, $now - $last_end ) / DAY_IN_SECONDS ) );
	}

	/**
	 * '' when the run was clean. Otherwise why it is partial, or `window_capped` (complete, but the gap since
	 * the last completed run was longer than the cap).
	 */
	private static function error_code( bool $thrown, array $stats, int $lead_count, bool $capped ): string {
		if ( $thrown ) {
			return 'sync_exception';
		}
		if ( $stats === array() ) {
			return 'registry_unavailable';
		}
		if ( empty( $stats['complete'] ) ) {
			return 'registry_incomplete';
		}
		if ( $lead_count >= self::LEAD_LIMIT ) {
			return 'lead_limit_reached';
		}
		return $capped ? 'window_capped' : '';
	}

	/**
	 * The registry lead as a storable agent, or null when it has no NPN (the key a person is stored under).
	 * Values are the registry's own; nothing is filled in.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function agent_from_lead( $lead ): ?array {
		if ( ! is_array( $lead ) ) {
			return null;
		}
		$registry = is_array( $lead['registry'] ?? null ) ? $lead['registry'] : array();
		$npn      = self::fit( $registry['npn'] ?? '', 32 );
		$name     = self::fit( $lead['fullName'] ?? '', 255 );
		if ( $npn === '' || $name === '' ) {
			return null;
		}

		$qualification = self::fit( $registry['qualification'] ?? '', 100 );

		return array(
			'npn'              => $npn,
			'name'             => $name,
			'license_type'     => self::fit( $registry['licenseType'] ?? '', 100 ),
			'qualification'    => $qualification,
			'annuity_capable'  => in_array( $qualification, self::ANNUITY_QUALIFICATIONS, true ),
			'first_issue_date' => self::fit( $lead['issueDate'] ?? '', 10 ),
			'city'             => self::fit( $lead['city'] ?? '', 100 ),
			'state'            => self::fit( $lead['state'] ?? '', 2 ),
			'zip'              => self::fit( $registry['postalCode'] ?? '', 16 ),
			'source_label'     => Freshmints_Registry_Producers::SOURCE_LABEL,
		);
	}

	/** A scalar as a trimmed string cut to $max characters; anything else is ''. */
	private static function fit( $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( trim( (string) $value ), 0, $max ) : '';
	}

	/** One run at a time: add_option is atomic, and a lock older than LOCK_TTL_SECONDS (a dead run) is taken over. */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( ( time() - (int) get_option( self::LOCK_OPTION ) ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
