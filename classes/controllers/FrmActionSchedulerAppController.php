<?php

/**
 * FrmActionSchedulerAppController
 *
 * This class is the main controller for the Formidable Action Scheduler plugin.
 * Its purpose is to hook into formidable to run the scheduler functionality.
 */
class FrmActionSchedulerAppController {

	public static function init() {

		// add_action( 'shutdown', __CLASS__ . '::maybe_do_queue' );
		add_filter( 'pre_get_ready_cron_jobs', __CLASS__ . '::maybe_do_queue' );

		// Important, if you add another listener to frm_trigger_email_action, make sure you
		// remove it before actually doing that action in the run_action method
		foreach ( FrmActionSchedulerHelper::allowed_actions() as $action ) {
			self::load_hooks( $action );
		}

		/**
		 * I believe these are all just to handle a dumb case where a scheduler setting is based on update time, but the actual action is not triggered on update
		 * Not sure it's neccessary, it does give the "just updated" plugin extra work to do.
		 */
		add_filter( 'frm_skip_form_action', __CLASS__ . '::check_all_actions', 10, 2 );
		add_action( 'frm_after_update_entry', __CLASS__ . '::check_update_actions', 20 );
		add_action( 'frm_before_destroy_entry', __CLASS__ . '::unschedule_all_events_for_entry' );

		self::load_admin_hooks();

		if ( get_option('frm_action_scheduler_defer_all', 0) ) {

			add_action( 'frm_after_create_entry', __CLASS__ . '::defer_create_actions', 0, 3 );
			add_action( 'frm_after_update_entry', __CLASS__ . '::defer_update_actions', 0, 2 );

			add_action( 'wp_ajax_frm_actionscheduler_async', __CLASS__ . '::ajax_do_queue' );
			add_action( 'wp_ajax_nopriv_frm_actionscheduler_async', __CLASS__ . '::ajax_do_queue' );
		}
	}


	private static function load_hooks( $action ) {
		add_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::pre_trigger_scheduler', 2 );
		add_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::post_trigger_scheduler', 1000 );
		add_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::trigger_scheduler', 10, 2 );
	}


	private static function unload_hooks( $action ) {
		remove_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::pre_trigger_scheduler', 2 );
		remove_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::post_trigger_scheduler', 1000 );
		remove_action( 'frm_trigger_' . $action . '_action', __CLASS__ . '::trigger_scheduler', 10, 2 );
	}


	private static function load_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		$class = 'FrmActionSchedulerSettingsController';
		add_action( 'frm_additional_action_settings', $class . '::form_action_settings', 10, 2);
		add_action( 'admin_init', $class . '::admin_js' );

		add_action( 'wp_ajax_formidable_autoresponder_logview', $class . '::log_viewer' );
		add_action( 'wp_ajax_formidable_autoresponder_delete_log', $class . '::delete_log_ajax' );
		add_action( 'wp_ajax_formidable_autoresponder_delete_queue_item', $class . '::delete_queue_item_ajax' );

		add_action( 'frm_add_settings_section', $class . '::add_settings_section' );
	}


	public static function maybe_do_queue( $pre=null ) {
		
		// error_log(__FUNCTION__ . ' before check');
		// pre_get_ready_cron_jobs is fired before sending the async cron request in spawn_cron (as a sanity check to bail if nothing is in queue)
		// running this at that time would defeat the purpose - we want to run on the async call, so we wait for DOING_CRON to be defined.
		if ( ! defined( 'DOING_CRON' ) ) return;
		
		// error_log(__FUNCTION__);
		
		// This was all stuff for an idea of hooking into shutdown rather than the cron filter
		// $time = time();
		// if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			// error_log( "maybe_do_queue: doing cron" );
		// }
		// elseif ( $time > ( 60 + get_option( 'frm_action_scheduler_last_run', 0 ) ) ) {// TODO this might be limited to admins
		// 	error_log( "maybe_do_queue: its been over a minute" );
		// 	update_option( 'frm_action_scheduler_last_run', $time, true );
		// }
		// else {
		// 	return;
		// }
		self::do_queue();

		return $pre;
	}


	public static function do_queue() {

		global $wpdb;
		$items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue WHERE time <= '" . date('Y-m-d H:i:s' ) . "' ORDER BY time ASC LIMIT 20");
error_log(__FUNCTION__);
		foreach ( $items as $item ) {
			$action_entry = explode( '_', $item->action_entry );
			self::run_action( $action_entry[1], $action_entry[0], $item->recheck );// $entry_id, $action_id, $recheck
		}
	}

	public static function ajax_do_queue() {

		error_log( current_action() );
		error_log( var_export( $_REQUEST, 1 ) );

		session_write_close();

		$result = check_ajax_referer( 'frm_actionscheduler_async', 'token' );
		error_log("check_ajax_referer $result");

		self::do_queue();

		wp_die();
	}


	public static function send_async() {

		$url = add_query_arg( [ 'action' => 'frm_actionscheduler_async', 'token' => wp_create_nonce( 'frm_actionscheduler_async' ) ], admin_url( 'admin-ajax.php' ) );
		$args = [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => $_COOKIE,
			// 'body'      => $this->data,
		];
	error_log($url);
		return wp_remote_post( $url, $args );
	}

	/**
	 * Keep record of all actions just in case the logic or triggers aren't met.
	 * This will allow us to stop scheduled events when logic is not met.
	 */
	public static function check_all_actions( $skip, $a ) {
		if ( $a['event'] == 'update' && FrmActionSchedulerHelper::is_allowed_action( $a['action']->post_excerpt ) ) {
			// error_log( __FUNCTION__ );
			if ( FrmActionScheduler::get_autoresponder( $a['action'] ) ) {
				self::add_action_to_global( $a );
			}
		}
		return $skip;
	}


	/**
	 * After the entry is updated, run checks for actions that didn't trigger
	 */
	public static function check_update_actions( $entry_id ) {
		global $frm_vars;
		if ( isset( $frm_vars['action_check'] ) ) {
			foreach ( $frm_vars['action_check'] as $action_info ) {
				self::maybe_unschedule_skipped_action( $action_info, $entry_id );
			}
		}
	}


	private static function maybe_unschedule_skipped_action( $a, $entry_id ) {
		$entry = $a['entry'];
		if ( ! is_object( $entry ) ) {
			$entry = FrmEntry::getOne( $entry, true );
		}

		if ( $entry->id == $entry_id ) {
			$conditions_not_met = FrmFormAction::action_conditions_met( $a['action'], $entry );
			if ( $conditions_not_met ) {
				// This unschedule doesnt seem necessary, and it needs to respect the recheck option so disabling for now
				// self::unschedule( ['entry_id' => $entry->id, 'action_id' => $a['action']->ID ] );
			} else {
				// If we get here, the event is an update, the conditions were met, and there is an scheduler setting...
				// but the action itself isn't set to trigger on update so the normal hook that runs trigger_scheduler didn't fire
				self::trigger_scheduler( $a['action'], $entry );
			}
		}
	}


	private static function add_action_to_global( $a ) {
		global $frm_vars;
		if ( ! isset( $frm_vars['action_check'] ) ) $frm_vars['action_check'] = [];
		$frm_vars['action_check'][ $a['action']->ID ] = $a;
	}


	private static function remove_action_from_global( $a ) {
		global $frm_vars;
		if ( isset( $frm_vars['action_check'][ $a['action']->ID ] ) ) {
			unset( $frm_vars['action_check'][ $a['action']->ID ] );
		}
	}


	/**
	 * This trigger listens for the frm_trigger_email_action and executes very early in the queue.  Its purpose is to
	 * check for autoresponder actions where the "ignore default" is set to true.  If that condition is found, then it
	 * removes the FrmNotification::trigger_email action on 'frm_trigger_email_action'.  If it removes it, it will get
	 * added back in in the post_trigger_scheduler method below.
	 *
	 * @param object $action - a post object for the action
	 *
	 * @return void
	 */
	public static function pre_trigger_scheduler( $action ) {
		// error_log( __FUNCTION__ );
		if ( $autoresponder = FrmActionScheduler::get_autoresponder( $action ) ) {
			// the conditions are met, so it's already handled from here
			self::remove_action_from_global( compact( 'action' ) );

			// It has an autoresponder component to the notification.  Is it set to ignore the default action?
			if ( $autoresponder['do_default_trigger'] == 'no' ) {
				if ( $action->post_excerpt == 'email' ) {
					remove_action( 'frm_trigger_email_action', 'FrmNotification::trigger_email', 10 );//if ( is_callable( 'FrmNotification::stop_emails') ) FrmNotification::stop_emails();
				} elseif ( $action->post_excerpt == 'twilio' ) {
					remove_action( 'frm_trigger_twilio_action', 'FrmTwloAppController::trigger_sms', 10 );
				} elseif ( $action->post_excerpt == 'api' ) {
					remove_action( 'frm_trigger_api_action', 'FrmAPISettingsController::trigger_api', 10 );
				}
			}
		}
	}


	/**
	 * This trigger listens for the frm_trigger_email_action and executes very late in the queue.  Its purpose is to
	 * check for autoresponder actions where the "ignore default" is set to true.  If that condition is found, then it
	 * adds back in the FrmNotification::trigger_email listener on 'frm_trigger_email_action' that was removed in
	 * the pre_trigger_scheduler method above.
	 *
	 * It needs to get added back in in case there subsequent Form Actions that maybe want to email.
	 *
	 * @param object $action - a post object for the action
	 *
	 * @return void
	 */
	public static function post_trigger_scheduler( $action ) {
		// error_log( __FUNCTION__ );
		if ( $autoresponder = FrmActionScheduler::get_autoresponder( $action ) ) {
			// It's has an autoresponder component to the notification.  Is it set to ignore the default action?
			if ( $autoresponder['do_default_trigger'] == 'no' ) {
				if ( $action->post_excerpt == 'email' ) {
					add_action( 'frm_trigger_email_action', 'FrmNotification::trigger_email', 10, 3 );// if ( is_callable( 'FrmNotification::hook_emails_to_action') ) FrmNotification::hook_emails_to_action();
				} elseif ( $action->post_excerpt == 'twilio' ) {
					add_action( 'frm_trigger_twilio_action', 'FrmTwloAppController::trigger_sms', 10, 3 );
				} elseif ( $action->post_excerpt == 'api' ) {
					add_action( 'frm_trigger_api_action', 'FrmAPISettingsController::trigger_api', 10, 3 );
				}
			}
		}
	}


	/**
	 * This is the method that gets called to schedule the initial autoresponder.  This happens after the form has
	 * been created or updated.
	 *
	 * Note, there's a bit of a gotcha in here.  The user can setup the autoresponder to trigger after the update date,
	 * but if the notification itself is not setup to trigger off of the update event, then it'll never get here.
	 * This is handles by 3 functions: check_all_actions() check_update_actions() unschedule_all_events_for_entry()
	 *
	 * Second note: this does not actually send out the email.  This just schedules a cron job to send it out.
	 *
	 * @param object $entry  - an object for the entry
	 * @param object $action - a post object for the action
	 *
	 * @return void
	 */
	public static function trigger_scheduler( $action, $entry ) {

		// TODO is this needed, and if so shouldnt it be after get_autoresponder check?
		// self::unschedule( [ 'entry_id' => $entry->id, 'action_id' => $action->ID ] );
		// error_log( __FUNCTION__ );

		$autoresponder = FrmActionScheduler::get_autoresponder( $action );
		if ( ! $autoresponder ) return;

		$reference_date = self::get_trigger_date( compact( 'entry', 'action', 'autoresponder' ) );
		if ( empty( $reference_date ) ) return;

		$trigger_ts = self::calculate_trigger_timestamp( $reference_date, compact( 'autoresponder', 'entry' ) );

		// 'initial' means this is the initial autoresponder
		$trigger_ts = apply_filters( 'formidable_autoresponder_trigger_timestamp', $trigger_ts, $reference_date, $entry, $action, 'initial' );

		if ( ! $trigger_ts ) {
			self::debug( sprintf( 'Not scheduling "%1$s" action for entry #%2$d because the settings are invalid: %3$s.', $action->post_title, $entry->id, print_r( $autoresponder, true ) ), $action );
		} elseif ( $trigger_ts < time() ) {
			self::debug( sprintf( 'Not scheduling "%s" action for entry #%d for %s because the time has already passed.', $action->post_title, $entry->id, date( 'Y-m-d H:i:s', $trigger_ts ) ), $action );
		} else {
			$recheck = $autoresponder['recheck'] == 'no' ? false : ( $autoresponder['recheck'] == 'yes' ? true : ( $trigger_ts > time() + 900 ) );// if trigger time is over 15 minutes from now
			self::schedule( $action, $entry->id, $trigger_ts, $recheck );
		}
		self::debug( sprintf( 'Reference TS: %s', date( 'Y-m-d H:i:s', strtotime( $reference_date ) ) ), $action );
	}


	private static function get_trigger_date( $a ) {
		$send_date = $a['autoresponder']['send_date'];
		$reference_date = '';
		if ( strpos( $send_date, '-' ) ) {
			// based on a date and time field
			list( $date_field, $time_field ) = explode( '-', $send_date );
			$reference_date = $a['entry']->metas[ $date_field ];
			$a['time'] = $a['entry']->metas[ $time_field ];
			if ( ! empty( $reference_date ) ) {
				self::localize_date( $reference_date, $a );
			}
		} elseif ( is_numeric( $send_date ) ) {
			// based on a field
			if ( ! empty( $a['entry']->metas[ $send_date ] ) ) {
				$reference_date = $a['entry']->metas[ $send_date ];
				self::localize_date( $reference_date, $a );
			}
		} elseif ( $send_date == 'update' ) {
			$reference_date = $a['entry']->updated_at;
		} else {
			$reference_date = $a['entry']->created_at;
		}
		return $reference_date;
	}


	private static function localize_date( &$reference_date, $a ) {
		$a['date'] = $reference_date;
		$a['time'] = ( isset( $a['time'] ) && ! empty( $a['time'] ) ) ? $a['time'] : '00:00:00';
		$trigger_time = apply_filters( 'frm_autoresponder_time', $a['time'], $a );
		$trigger_time = date( 'H:i:s', strtotime( $trigger_time ) );
		$reference_date = date( 'Y-m-d H:i:s', strtotime( $reference_date . ' ' . $trigger_time ) );
		$reference_date = get_gmt_from_date( $reference_date );
	}


	/**
	 * Given the reference date, which is anything that passes strtotime(), and the settings, calculate the next trigger timestamp.
	 *
	 * @param string $reference_date any date that satisfies strtotime().  For example, YYYY-mm-dd
	 * @param array  $atts
	 *               - entry
	 *               - autoresponder  the settings for this particular autoresponder.  We pay attention to
	 *                                  - send_before_after - which says if we should trigger before or after the reference
	 *                                    date
	 *                                  - send_unit - which is 'minutes', 'hours', 'days', 'months', 'years'
	 *                                  - send_interval which is how many send_units we should calculate
	 *
	 * @return int|boolean a timestamp if all is good, false if $reference_date does not translate to a date
	 */
	public static function calculate_trigger_timestamp( $reference_date, $a ) {
		$autoresponder = $a['autoresponder'];
		$reference_ts = strtotime( $reference_date );
		if ( ! $reference_ts ) {
			return false;
		}

		if ( !in_array( $autoresponder['send_before_after'], [ 'before', 'after' ] ) ) return false;
		if ( !in_array( $autoresponder['send_unit'], [ 'minutes', 'hours', 'days', 'months', 'years' ] ) ) return false;
		if ( !is_numeric( $autoresponder['send_interval'] ) ) return false;

		$one = ( $autoresponder['send_before_after'] == 'before' ) ? -1 : 1;
		$multiplier = ( $one == 1 ? '+' : '' ) . $one * $autoresponder['send_interval'];
		$trigger_on = strtotime( $multiplier . ' ' . $autoresponder['send_unit'], $reference_ts );

		if ( $trigger_on < time() ) {
			self::get_future_date( $trigger_on, array( 'autoresponder' => $autoresponder, 'entry' => $a['entry'] ) );
		}

		return $trigger_on;
	}


	private static function get_future_date( &$trigger_on, $a ) {

		$autoresponder = $a['autoresponder'];
		if ( empty( $autoresponder['send_after'] ) ) {
			// don't trigger if date has passed, and it is repeating
			return;
		}

		$autoresponder = self::get_repeat_settings( $autoresponder, $a['entry'] );

		if ( empty( $autoresponder['send_interval'] ) ) {
			// if the interval is 0, prevent an infinite loop
			$autoresponder['send_interval'] = 1;
			$autoresponder['send_unit'] = 'minutes';
		}

		while ( $trigger_on < time() ) {
			$trigger_on = strtotime( '+' . $autoresponder['send_interval'] . ' ' . $autoresponder['send_unit'], $trigger_on );
		}
	}



	public static function defer_create_actions( $entry_id, $form_id, $args = array() ) {
		$args['entry_id'] = $entry_id;
		$args['form_id']  = $form_id;
		$event = apply_filters( 'frm_trigger_create_action', 'create', $args );
		self::schedule( $event, $entry_id, time() );
		self::remove_trigger_hooks();
	}

	public static function defer_update_actions( $entry_id, $form_id ) {
		$event = apply_filters( 'frm_trigger_update_action', 'update', [ 'entry_id' => $entry_id ] );
		self::schedule( $event, $entry_id, time() );
		self::remove_trigger_hooks();
	}

	public static function run_all_actions( $entry_id, $event, $form_id ) {
		FrmFormActionsController::trigger_actions( $event, $form_id, $entry_id );
	}

	public static function remove_trigger_hooks() {
		remove_action( 'frm_after_create_entry', 'FrmFormActionsController::trigger_create_actions', 20 );// 3
		remove_action( 'frm_after_create_entry', 'FrmProFormActionsController::trigger_draft_actions', 10 );// 2
		remove_action( 'frm_after_update_entry', 'FrmProFormActionsController::trigger_update_actions', 10 );// 2

		// trigger an async request to run these right away
		// I guess these should be set on the same hooks and priorities so the timing will be sort of similar... probably still ruined if trying to modify data after this point.
		add_action( 'shutdown', __CLASS__ . '::send_async' );
		// add_action( 'shutdown', 'spawn_cron' );
		// add_action( 'frm_after_create_entry', 'spawn_cron', 20 );
		// add_action( 'frm_after_update_entry', 'spawn_cron', 10 );
	}


	/**
	 * The method that listens to the cron job action 'formidable_send_autoresponder'.  It is passed in an entry id and action id.
	 * It looks both of those up and if they both exist, then it checks to see if the action conditions are still met.
	 * If they are, then it increments the sent counter for this entry id
	 */
	public static function run_action( $entry_id, $action_id, $recheck ) {

		$entry = FrmEntry::getOne( $entry_id, true );
		if ( empty( $entry ) ) {
			self::unschedule( compact( 'entry_id', 'action_id' ) );
			return;
		}

		if ( in_array( $action_id, [ 'update', 'create', 'draft' ] ) ) {
			self::run_all_actions( $entry_id, $action_id, $entry->form_id );
			self::unschedule( compact( 'entry_id', 'action_id' ) );
			return;
		}
// error_log( __FUNCTION__ );
		$action = FrmActionScheduler::get_action( $action_id );
		$autoresponder = FrmActionScheduler::get_autoresponder( $action );
		if ( empty( $autoresponder ) ) {
			self::unschedule( compact( 'entry_id', 'action_id' ) );
			return;
		}

		// action_conditions_met actually returns false if conditions are met.  it returns boolean "stop" value
		if ( $recheck && FrmFormAction::action_conditions_met( $action, $entry ) ) {
			self::debug( sprintf( 'Conditions for "%s" action for entry #%d not met. Halting.', $action->post_title, $entry->id, date( 'Y-m-d H:i:s' ) ), $action );
			self::unschedule( compact( 'entry_id', 'action_id' ) );
			return;
		}

		self::debug( sprintf( 'Conditions for "%s" action for entry #%d met. Proceeding.', $action->post_title, $entry->id, date( 'Y-m-d H:i:s' ) ), $action );
		
		$sent_count = null;
		if ( $autoresponder['send_after'] ) {
			$sent_count = self::get_run_count( $entry_id, $action_id );
			if ( $autoresponder['send_after_limit'] && $sent_count >= $autoresponder['send_after_count'] ) {
				self::debug( sprintf( 'Not triggering $s because there we have already sent out the limit of %d.', $action->post_excerpt, $sent_count ), $action );
				self::unschedule( compact( 'entry_id', 'action_id' ) );
				return;
			}
			$sent_count++;
		}

		// make sure hooks are loaded
		new FrmNotification();

		// First remove our pre/post listeners - this is a scheduled autoresponder, not something triggered immediately after creating/updating the record
		self::unload_hooks( $action->post_excerpt );

		// Now, do the action - this will trigger FrmNotification::trigger_email();
		self::debug( sprintf( 'Triggering %1$s action for "%2$s"', $action->post_excerpt, $action->post_title ), $action );
		do_action( 'frm_trigger_' . $action->post_excerpt . '_action', $action, $entry, FrmForm::getOne( $entry->form_id ), 'create' );// TODO why does this say 'create'?

		self::add_to_log( $entry_id, $action_id, $sent_count );
		
		// If necessary, setup the next event
		if ( $autoresponder['send_after'] ) {
			if ( ! $autoresponder['send_after_limit'] || $sent_count < $autoresponder['send_after_count'] ) {
				$after_settings = self::get_repeat_settings( $autoresponder, $entry );

				$reference_date = date( 'Y-m-d H:i:s' );
				$trigger_ts = self::calculate_trigger_timestamp( $reference_date, array( 'autoresponder' => $after_settings, 'entry' => $entry ) );

				$trigger_ts = apply_filters( 'formidable_autoresponder_trigger_timestamp', $trigger_ts, $reference_date, $entry, $action, 'followup' ); // 'followup' means this is a followup autoresponder

				$recheck = $autoresponder['recheck'] == 'no' ? false : true;// default to rechecking repeat events unless explicitly set to No

				self::schedule( $action, $entry_id, $trigger_ts, $recheck, 'update' );
			}
		}

		// finally remove item from queue if it was not rescheduled ( which would have set $trigger_ts )
		if ( !isset( $trigger_ts ) ) {
			self::unschedule( compact( 'entry_id', 'action_id' ) );
		}

		// replace actions for other responders
		self::load_hooks( $action->post_excerpt );

	}


	public static function get_run_count( $entry_id, $action_id ) {
		global $wpdb;
		$last_send = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}frm_actionscheduler_logs WHERE entry = $entry_id AND action = $action_id ORDER BY time DESC LIMIT 1" );
		if ( ! $last_send ) return 0;
		if ( ! $last_send->count ) return 1;// almost always will be 1 send, so leaving this column null means 1
		return (int) $last_send->count;
	}


	public static function add_to_log( $entry_id, $action_id, $sent_count ) {
		global $wpdb;
		$data =[ 'entry' => $entry_id, 'action' => $action_id, 'time' => date('Y-m-d H:i:s'), 'count' => $sent_count ];
		error_log(var_export($data,1));
		$wpdb->get_results( "INSERT INTO {$wpdb->prefix}frm_actionscheduler_logs (". implode(", ", array_keys($data)) .") VALUES ('". implode("', '", $data) ."');" );
		error_log(var_export($wpdb->last_query,1));
	}


	/**
	 * add new item to schedule
	 * 
	 * Note:
	 * The action_entry combo is the unique index of this db table, so inserting will fail if there's already a scheduled item for the same entry & action.
	 * This is probably ideal behaviour, so using IGNORE to fire warnign instead of error when trying and failing to insert duplicate key
	 */
	private static function schedule( $action, $entry_id, $timestamp, $recheck_conditionals=true, $update=false ) {
		// error_log(__FUNCTION__);

		if ( is_object( $action ) ) {// if not object, it is an event type string (update, create, draft) for running all actions
			self::debug( sprintf( 'Scheduling "%s" action for entry #%d for %s', $action->post_title, $entry_id, date( 'Y-m-d H:i:s', $timestamp ) ), $action );
			$action = $action->ID;
		}
		global $wpdb;
		$data = [
			'action_entry' => $action .'_'. $entry_id,
			'time' => date( 'Y-m-d H:i:s', $timestamp ),
			'recheck' => $recheck_conditionals ? 1 : null,
		];
		$cmd = $update ? "REPLACE" : "INSERT IGNORE";
		$wpdb->get_results( "{$cmd} INTO {$wpdb->prefix}frm_actionscheduler_queue (". implode(", ", array_keys($data)) .") VALUES ('". implode("', '", $data) ."');" );
	}


	public static function unschedule( $a ) {

		if ( !empty($a['entry_id']) && !empty($a['action_id']) ) $where = "action_entry = '{$a['action_id']}_{$a['entry_id']}' LIMIT 1";
		elseif ( !empty($a['entry_id']) ) $where = "action_entry LIKE '%_{$a['entry_id']}'";
		elseif ( !empty($a['action_id']) ) $where = "action_entry LIKE '{$a['action_id']}_%'";
		else return 0;
		global $wpdb;
		$wpdb->get_results( "DELETE FROM {$wpdb->prefix}frm_actionscheduler_queue WHERE $where" );
		error_log("unscheduled $wpdb->rows_affected items");// secret property
	}


	private static function get_repeat_settings( $autoresponder, $entry ) {
		return array_merge( $autoresponder, [
			'send_before_after' => 'after',
			'send_interval'     => ( $autoresponder['send_after_interval_type'] == 'field' ) ? $entry->metas[ $autoresponder['send_after_interval_field'] ] : $autoresponder['send_after_interval'],
			'send_unit'         => $autoresponder['send_after_unit'],
		] );
	}


	/**
	 * Print a message out to a debug file.  This is useful for debugging to make sure that the emails are getting triggered.
	 */
	public static function debug( $message, $action ) {
		$log = new FrmActionSchedulerLog( compact('action') );
		$log->add( $message );
	}
}