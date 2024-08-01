<?php
/**
 * A pseudo-cron daemon for scheduling WordPress tasks.
 */

//  ini_set( 'log_errors', 1 );
//  ini_set( 'error_log', __DIR__ . '/debug.log' );

ignore_user_abort( true );

if ( ! headers_sent() ) {
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
}

// Don't run cron until the request finishes, if possible.
if ( function_exists( 'fastcgi_finish_request' ) ) {
	fastcgi_finish_request();
} elseif ( function_exists( 'litespeed_finish_request' ) ) {
	litespeed_finish_request();
}

if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) ) {
	error_log('doing ajax or cron');// dont see why this would happen
	die();
}

define( 'DOING_CRON', true );// might be useful to masquerade

if ( ! defined( 'ABSPATH' ) ) {
	/** Set up WordPress environment */
	$abspath = dirname( __DIR__, 3 );
	if ( ! file_exists( $abspath . '/wp-load.php') ) {
		/** wp-load.php not in the expected place, recursively try to find a dir with both wp-load.php and wp-admin/ */
		$abspath = __DIR__;
		do {
			if ( dirname( $abspath ) === $abspath ) {
				error_log("Formidable Action Scheduler could not find the home directory and will not be able to work.");
				die();
			}
			$abspath = dirname( $abspath );
		}
		while( ! ( file_exists( $abspath . '/wp-load.php' ) && file_exists( $abspath . '/wp-admin/' ) ) );
	}
	require_once $abspath . '/wp-load.php';
}

// Attempt to raise the PHP memory limit for cron event processing.
wp_raise_memory_limit( 'cron' );

set_time_limit(360);

if ( !empty( $_POST['lock'] ) ) {
	$lock = get_transient( 'frm_action_scheduler_running' );
	error_log("checking lock {$_POST['lock']} === $lock");
	if ( $lock !== $_POST['lock'] ) {
		die();
	}
	error_log("proceeding with ajax do queue");
	define( 'DOING_FRM_ACTION_SCHEDULER_QUEUE', true );
}

$actions = !empty( $_POST['actions'] ) ? (array) $_POST['actions'] : [];

FrmActionSchedulerCronController::do_queue( $actions );

if ( !empty( $_POST['lock'] ) ) {
	error_log('do_queue: delete frm_action_scheduler_running');
	delete_transient( 'frm_action_scheduler_running' );
}

die();