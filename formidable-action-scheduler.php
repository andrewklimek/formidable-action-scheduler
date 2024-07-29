<?php
/*
Plugin Name: Formidable Form Action Scheduler
Description: Run your form actions asyncronously or at some future time
Version: 1.0
Author URI: https://github.com/andrewklimek/
Author: andrewklimek
*/

add_action( 'init', 'load_frm_action_scheduler', 0 );
function load_frm_action_scheduler() {
	require_once __DIR__ . '/classes/helpers/FrmActionSchedulerHelper.php';
	spl_autoload_register( 'FrmActionSchedulerHelper::autoload' );

	FrmActionSchedulerAppController::init();
}

register_activation_hook( __FILE__, 'frm_action_scheduler_activation' );

function frm_action_scheduler_activation() {
	
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );// to use dbDelta()
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}frm_actionscheduler_logs (
		id int(11) unsigned NOT NULL auto_increment,
		entry int(11) unsigned NOT NULL default 0,
		action int(11) unsigned NOT NULL default 0,
		time int(10) NOT NULL default UNIX_TIMESTAMP(),
		count tinyint(3) unsigned NULL default NULL,
		PRIMARY KEY  (id)
		) ENGINE=InnoDB {$charset_collate};");

	dbDelta( "CREATE TABLE {$wpdb->prefix}frm_actionscheduler_queue (
		action_entry varchar(255) NOT NULL default '',
		time int(10) NOT NULL,
		recheck tinyint(1) NULL,
		PRIMARY KEY  (action_entry)
		) ENGINE=InnoDB {$charset_collate};");


	/**
	 * Migrate from Formidable Form Action Automation
	 */
	deactivate_plugins( 'formidable-autoresponder/formidable-autoresponder.php', 'silent' );

	load_frm_action_scheduler();

	define( 'DOING_FRM_ACTION_SCHEDULER_QUEUE', true );
	$crons = _get_cron_array();
	foreach ( $crons as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			if ( $hook !== 'formidable_send_autoresponder' ) continue;
			$set_one = true;
			foreach ( $events as $event ) {
				FrmActionSchedulerAppController::schedule( $event['args'][1], $event['args'][0], $timestamp, true );// $action, $entry_id, $timestamp, $recheck_conditionals
			}
		}
	}
	if ( isset( $set_one ) ) {
		wp_unschedule_hook('formidable_send_autoresponder');
	}
}