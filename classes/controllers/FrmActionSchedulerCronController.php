<?php

/**
 * FrmActionSchedulerCronController
 */
class FrmActionSchedulerCronController {

	public static function maybe_do_queue( $pre=null ) {
		
		error_log(__FUNCTION__ . ' running.');
		// pre_get_ready_cron_jobs is fired before sending the async cron request in spawn_cron (as a sanity check to bail if nothing is in queue)
		// running this at that time would defeat the purpose - we want to run on the async call, so we wait for DOING_CRON to be defined.
		if ( ! defined( 'DOING_CRON' ) ) {
			error_log(__FUNCTION__ . " doing_cron not defined. exit.");
			return $pre;
		}
		
		if ( time() < ( 60 + get_option( 'frm_action_scheduler_last_run', 0 ) ) ) {// TODO this might be limited to admins
			error_log( __FUNCTION__ . " queue ran too recently" );
			return $pre;
		}

		self::do_queue();

		return $pre;
	}


	public static function do_queue() {
		error_log(__FUNCTION__);
		update_option( 'frm_action_scheduler_last_run', time(), true );
		global $wpdb;
		$items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue WHERE time <= '" . date('Y-m-d H:i:s' ) . "' ORDER BY time ASC LIMIT 20");
		foreach ( $items as $item ) {
			$action_entry = explode( '_', $item->action_entry );
			FrmActionSchedulerAppController::run_action( $action_entry[1], $action_entry[0], $item->recheck );// $entry_id, $action_id, $recheck
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
		];
		error_log($url);
		return wp_remote_post( $url, $args );
	}



	public static function defer_create_actions( $entry_id, $form_id, $args = array() ) {
		$args['entry_id'] = $entry_id;
		$args['form_id']  = $form_id;
		$event = apply_filters( 'frm_trigger_create_action', 'create', $args );
		FrmActionSchedulerAppController::schedule( $event, $entry_id, time() );
		self::remove_trigger_hooks();
	}

	public static function defer_update_actions( $entry_id, $form_id ) {
		$event = apply_filters( 'frm_trigger_update_action', 'update', [ 'entry_id' => $entry_id ] );
		FrmActionSchedulerAppController::schedule( $event, $entry_id, time() );
		self::remove_trigger_hooks();
	}

	public static function remove_trigger_hooks() {
		remove_action( 'frm_after_create_entry', 'FrmFormActionsController::trigger_create_actions', 20 );// 3
		remove_action( 'frm_after_create_entry', 'FrmProFormActionsController::trigger_draft_actions', 10 );// 2
		remove_action( 'frm_after_update_entry', 'FrmProFormActionsController::trigger_update_actions', 10 );// 2

		// trigger an async request to run these right away
		// I guess these should be set on the same hooks and priorities so the timing will be sort of similar... probably still ruined if trying to modify data after this point.
		// add_action( 'shutdown', __CLASS__ . '::send_async' );
		add_action( 'frm_after_create_entry', __CLASS__ . '::send_async', 20 );
		add_action( 'frm_after_update_entry', __CLASS__ . '::send_async', 10 );
		// add_action( 'shutdown', 'spawn_cron' );
		// add_action( 'frm_after_create_entry', 'spawn_cron', 20 );
		// add_action( 'frm_after_update_entry', 'spawn_cron', 10 );
	}

}