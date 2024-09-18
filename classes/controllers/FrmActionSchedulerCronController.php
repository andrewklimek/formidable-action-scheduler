<?php

/**
 * FrmActionSchedulerCronController
 */
class FrmActionSchedulerCronController {


	public static function init() {

		$alt_cron = false;
		if ( $alt_cron ) {
			add_action( 'wp_loaded', __CLASS__ . '::alt_cron' );
		} else {
			add_action( 'frm_actionscheduler_cron_hook', __CLASS__ . '::do_queue' );
			add_filter( 'cron_schedules', __CLASS__ . '::add_cron_schedule', 9 );
			add_action( 'frm_actionscheduler_after_schedule', __CLASS__ . '::schedule_recurring_cron', 10, 1 );
			// self::schedule_recurring_cron();
		}
		add_shortcode( 'frm_actionscheduler_queue', __CLASS__ . '::frm_actionscheduler_queue_shortcode' );
	}

	public static function frm_actionscheduler_queue_shortcode($a) {
		if ( ! current_user_can( 'frm_edit_entries' ) ) return;
		ob_start();
		include( FrmActionSchedulerHelper::plugin_path( 'classes/views/queue.php' ) );
		return ob_get_clean();
	}

	public static function schedule_recurring_cron( $action ) {
		if ( ! is_numeric( $action ) ) return;
		if ( ! wp_next_scheduled( 'frm_actionscheduler_cron_hook' ) ) {
			$time = intval( ceil( time() / 300 ) * 300 );// make it an even minute
			$result = wp_schedule_event( $time, 'five_minutes', 'frm_actionscheduler_cron_hook', [], true );
		}
		remove_action( 'frm_actionscheduler_after_schedule', __CLASS__ . '::schedule_recurring_cron', 10 );
	}


	public static function add_cron_schedule( $schedules ) {

		$schedules[ 'five_minutes' ] = [
			'interval' => 300,
			'display'  => 'Every 5 minutes',
		];
		return $schedules;
	}

	public static function alt_cron() {

		if ( defined( 'DOING_AJAX' ) ) return;

		// if ( is_favicon() ) return;// not sure why this doesnt work here
		if ( '/favicon.ico' == $_SERVER['REQUEST_URI'] ) return;

		if ( isset( $_GET['action'] ) && $_GET['action'] === 'frm_actionscheduler_async' ) {
			error_log("action = frm_actionscheduler_async... THIS CHECK SHOULDNT HAPPEN!!");
			return;
		}

		if ( defined( 'DOING_FRM_ACTION_SCHEDULER_QUEUE' ) ) {
			error_log( 'defined already: DOING_FRM_ACTION_SCHEDULER_QUEUE... THIS CHECK SHOULDNT HAPPEN!!');
			return;
		}

		$next_run = self::get_next_run();
		// error_log( 'frm_action_scheduler_next_run: ' . $next_run );
		if ( ! $next_run ) {
			// error_log('exiting bc no next run!');
			return;
		}
		if ( time() < $next_run ) {
			// error_log('exiting bc too soon');
			return;
		}

		// error_log( __FUNCTION__ .' '. $_SERVER['REQUEST_URI'] .' '. var_export( $_REQUEST, 1 ) );

		$lock = get_transient( 'frm_action_scheduler_running' );
	// error_log("retreived frm_action_scheduler_running: $lock");
		if ( $lock ) {
			$time = (int) $lock;
			if ( time() < $time + 600 ) {
				// error_log("frm_action_scheduler_running transient existed and wasnt over 10 mintues ago");
				return;
			}
		}

		$lock = time() .'|'. bin2hex(random_bytes(5));

		set_transient( 'frm_action_scheduler_running', $lock );// any reason to set expiration?

		// error_log("set lock $lock and calling send_async");

		self::send_async( [ 'lock' => $lock ] );

		// self::do_queue();

		// self::set_next_run();

		return;
	}

	public static function get_next_run() {
		$timestamp = get_option( 'frm_action_scheduler_next_run', 'init' );
		if ( 'init' === $timestamp ) {
			global $wpdb;
			$timestamp = $wpdb->get_var("SELECT time FROM {$wpdb->prefix}frm_actionscheduler_queue ORDER BY time LIMIT 1");
			add_option( 'frm_action_scheduler_next_run', $timestamp, '', true );
		}
		return (int) $timestamp;
	}

	public static function set_next_run( $timestamp=0 ) {
		global $wpdb;
		if ( $timestamp ) {
			$timestamp = (int) $timestamp;
			$wpdb->get_results("UPDATE {$wpdb->prefix}options SET option_value = $timestamp WHERE option_name='frm_action_scheduler_next_run' AND ( option_value > $timestamp OR option_value = '')");
			// error_log("setting next run as $timestamp  - rows_affected: $wpdb->rows_affected");// secret property
		} else {
			$wpdb->get_results("UPDATE {$wpdb->prefix}options SET option_value = (SELECT time FROM {$wpdb->prefix}frm_actionscheduler_queue ORDER BY time LIMIT 1) WHERE option_name='frm_action_scheduler_next_run'");
			// error_log("setting next run based on schedule table - rows_affected: $wpdb->rows_affected");// secret property
		}
	}

	public static function do_queue( $actions = [] ) {

		if ( !empty( $actions ) ) {
			// data validation
			foreach( $actions as $action ) {
				$parts = explode( '_', $action );
				if ( count($parts) != 2 || ! is_numeric($parts[0]) || !is_numeric($parts[1]) ) return;
			}
			$where = "WHERE action_entry IN ('" . implode( "', '", $actions ) . "')";
		} else {
			$where = "WHERE time <= '" . time() . "'";
		}

		error_log(__FUNCTION__);
		global $wpdb;
		$items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue $where ORDER BY time ASC LIMIT 30");
		error_log( $wpdb->last_query );
		error_log( "returned {$wpdb->num_rows} rows" );
		if ( ! $items ) return;

		foreach ( $items as $item ) {
			// error_log( "doing: " . $item->action_entry );
			$action_entry = explode( '_', $item->action_entry );
			FrmActionSchedulerAppController::run_action( $action_entry[1], $action_entry[0], $item->recheck );// $entry_id, $action_id, $recheck
		}
		if ( empty( $actions ) ) {
			// error_log('do_queue: set next run');
			self::set_next_run();
			// error_log('do_queue: delete frm_action_scheduler_running');
			// delete_transient( 'frm_action_scheduler_running' );

			// TESTING CONSISTENCY
			$next_run = self::get_next_run();
			if ( $next_run ) {
				if ( $items[0]->time == $next_run ) {
					// error_log( 'frm_action_scheduler_next_run was correct' );
				} else {
					error_log( "frm_action_scheduler_next_run was mismatched: {$items[0]->time} vs $next_run" );
				}
			}
		}
	}

	public static function ajax_do_queue() {
		// error_log( current_action() );
		// error_log( __FUNCTION__ . " " . microtime(1) );
		// error_log( var_export( $_REQUEST, 1 ) );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		session_write_close();// WP_Async_Request (used by action scheduler)
		ignore_user_abort( true );// wp-cron.php
		set_time_limit(360);

		// $result = check_ajax_referer( 'frm_actionscheduler_async', 'token' );// not technically needed
		// error_log("check_ajax_referer $result");

		if ( !empty( $_POST['lock'] ) ) {
			$lock = get_transient( 'frm_action_scheduler_running' );
			// error_log("checking lock {$_POST['lock']} === $lock");
			if ( $lock !== $_POST['lock'] ) wp_die();
			// error_log("proceeding with ajax do queue");
			define( 'DOING_FRM_ACTION_SCHEDULER_QUEUE', true );
		}

		$actions = !empty( $_POST['actions'] ) ? (array) $_POST['actions'] : [];

		self::do_queue( $actions );

		wp_die();
	}


	public static function send_deferred_async() {
		error_log(__FUNCTION__ .' '. current_filter() );
		$actions = FrmActionSchedulerAppController::defer_action( null, null, 'get_clean' );// send truthy value so deferred items get cleared so they can't possibly be queued twice (like drafts which run frm_after_create_entry AND frm_after_update_entry)
		if ( ! $actions ) return false;

		$time = time() + 300;
		$values = [];
		foreach( $actions as $action ) {
			$values[] = "('{$action}',{$time},0)";
		}
		$values = implode( ', ', $values );
		global $wpdb;
		$wpdb->get_results("INSERT IGNORE INTO {$wpdb->prefix}frm_actionscheduler_queue VALUES $values");
		error_log($wpdb->last_query);
		error_log(var_export($wpdb->rows_affected,1));

		self::send_async( [ 'actions' => $actions, 'deferred' => 1 ] );
	}

	public static function send_async( $body ) {
		
		// $url = add_query_arg( [ 'action' => 'frm_actionscheduler_async' ], admin_url( 'admin-ajax.php' ) );// , 'token' => wp_create_nonce( 'frm_actionscheduler_async' )
		
		$url = WP_PLUGIN_URL . '/formidable-action-scheduler/cron.php';

		$args = [
			'timeout'	=> 1,
			'blocking'	=> false,
			'sslverify'	=> apply_filters( 'https_local_ssl_verify', false ),
			'body'		=> $body,
			// 'cookies'	=> $_COOKIE,
		];
		// error_log($url);
		$timer = microtime(1);

		// $options = [
		// 	CURLOPT_URL				=> $url,
		// 	CURLOPT_RETURNTRANSFER	=> true,
		// 	CURLOPT_TIMEOUT_MS		=> 100,
		// 	CURLOPT_NOSIGNAL		=> true,// urban rumour this allows timeouts < 1 sec
		// 	CURLOPT_SSL_VERIFYHOST	=> false,// to be like WP, set these 2 false
		// 	CURLOPT_SSL_VERIFYPEER	=> false,
		// 	CURLOPT_POST			=> true,
		// 	CURLOPT_POSTFIELDS		=> http_build_query($body, '', '&'),
		// ];
		// $ch = curl_init();
		// curl_setopt_array($ch, $options);
		// $result = curl_exec($ch);
		// curl_close($ch);

		$result = wp_remote_post( $url, $args );

		// error_log( 'ajax send took ' . (microtime(1) - $timer) );
		return $result;
	}

}