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
	}


	public static function schedule_recurring_cron( $action ) {
		if ( ! is_numeric( $action ) ) return;
		if ( ! wp_next_scheduled( 'frm_actionscheduler_cron_hook' ) ) {
			$time = intval( ceil( time() / 60 ) * 60 );// make it an even minute
			wp_schedule_event( $time, 'five_minutes', 'frm_actionscheduler_cron_hook' );
		}
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

		$next_run = get_option( 'frm_action_scheduler_next_run', 10000 + time() );
		error_log( 'frm_action_scheduler_next_run: ' . $next_run );
		if ( ! $next_run ) {
			error_log('exiting bc no next run!');
			return;
		}
		if ( time() < $next_run ) {
			error_log('exiting bc too soon');
			return;
		}

		error_log( __FUNCTION__ .' '. $_SERVER['REQUEST_URI'] .' '. var_export( $_REQUEST, 1 ) );

		$lock = get_transient( 'frm_action_scheduler_running' );
	error_log("retreived frm_action_scheduler_running: $lock");
		if ( $lock ) {
			$time = (int) $lock;
			if ( time() < $time + 600 ) {
				error_log("frm_action_scheduler_running transient existed and wasnt over 10 mintues ago");
				return;
			}
		}

		$lock = time() .'|'. bin2hex(random_bytes(5));

		set_transient( 'frm_action_scheduler_running', $lock );// any reason to set expiration?

		error_log("set lock $lock and calling send_async");

		self::send_async( null, $lock );

		// self::do_queue();

		// self::set_next_run();

		return;
	}

	public static function set_next_run( $timestamp=0 ) {
		global $wpdb;
		if ( $timestamp ) {
			$timestamp = (int) $timestamp;
			$wpdb->get_results("UPDATE {$wpdb->prefix}options SET option_value = $timestamp WHERE option_name='frm_action_scheduler_next_run' AND ( option_value > $timestamp OR option_value = '')");
			error_log("setting next run as $timestamp  - rows_affected: $wpdb->rows_affected");// secret property
		} else {
			$wpdb->get_results("UPDATE {$wpdb->prefix}options SET option_value = (SELECT UNIX_TIMESTAMP(time) FROM {$wpdb->prefix}frm_actionscheduler_queue ORDER BY time LIMIT 1) WHERE option_name='frm_action_scheduler_next_run'");
			error_log("setting next run based on schedule table - rows_affected: $wpdb->rows_affected");// secret property
		}
	}

	public static function do_queue( $actions = [] ) {

		if ( !empty( $actions ) ) {
			$in = [];
			foreach( $actions as $action => $entry ) {
				if ( ! is_numeric($entry) || ( ! is_numeric($action) && ! in_array($action, ['create','update','draft']) ) ) return;// data validation
				$in[] = $action .'_'. $entry;
			}
		}
		if ( !empty( $in ) ) {
			$where = "WHERE action_entry IN ('" . implode( "', '", $in ) . "')";
		} else {
			$where = "WHERE time <= '" . date('Y-m-d H:i:s' ) . "'";
		}

		error_log(__FUNCTION__);
		global $wpdb;
		$items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue $where ORDER BY time ASC LIMIT 30");
		if ( ! $items ) return;
		error_log( $wpdb->last_query );

		foreach ( $items as $item ) {
			error_log( "doing: " . $item->action_entry );
			$action_entry = explode( '_', $item->action_entry );
			if ( empty( $action ) && ! is_numeric( $action_entry[0] ) && strtotime( $item->time ) < time() + 30 ) {
				error_log('found a very recent deferred action in queue... This shouldnt happen!');
			}
			FrmActionSchedulerAppController::run_action( $action_entry[1], $action_entry[0], $item->recheck );// $entry_id, $action_id, $recheck
		}
		if ( empty( $in ) ) {
			error_log('do_queue: set next run');
			self::set_next_run();
			// error_log('do_queue: delete frm_action_scheduler_running');
			// delete_transient( 'frm_action_scheduler_running' );

			// TESTING CONSISTENCY
			$next_run = get_option( 'frm_action_scheduler_next_run', 0 );
			if ( $next_run ) {
				if ( strtotime( $items[0]->time ) == (int) $next_run ) {
					error_log( 'frm_action_scheduler_next_run was correct' );
				} else {
					error_log( "frm_action_scheduler_next_run was mismatched: {$items[0]->time} vs " . date("Y-m-d H:i:s", $next_run ) );
				}
			}
		}
	}

	public static function ajax_do_queue() {
		// error_log( current_action() );
		error_log( __FUNCTION__ . " " . microtime(1) );
		error_log( var_export( $_REQUEST, 1 ) );
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
			error_log("checking lock {$_POST['lock']} === $lock");
			if ( $lock !== $_POST['lock'] ) wp_die();
			error_log("proceeding with ajax do queue");
			define( 'DOING_FRM_ACTION_SCHEDULER_QUEUE', true );
		}

		$actions = !empty( $_POST['actions'] ) ? (array) $_POST['actions'] : [];

		self::do_queue( $actions );

		wp_die();
	}


	public static function send_async( $entry_id=0, $lock=null ) {
		$body = [];
		if ( $entry_id ) {
			$actions = [];
			switch ( current_filter() ) {
				case 'frm_after_create_entry':
					$actions = [ "create" => $entry_id, "draft" => $entry_id ];
					break;
				case 'frm_after_update_entry':
					$actions = [ "update" => $entry_id ];
					break;
				default:
					$actions = [ "create" => $entry_id, "update" => $entry_id, "draft" => $entry_id ];
					break;
			}
		}
		if ( !empty( $actions ) ) $body['actions'] = $actions;
		if ( !empty( $lock ) ) $body['lock'] = $lock;


		
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
		error_log('ajax send started at ' . $timer );

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

		error_log( 'ajax send took ' . (microtime(1) - $timer) );
		return $result;
	}

}