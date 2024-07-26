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
		time datetime NOT NULL default '0000-00-00 00:00:00',
		count tinyint(3) unsigned NULL default NULL,
		PRIMARY KEY  (id)
		) ENGINE=InnoDB {$charset_collate};");

	dbDelta( "CREATE TABLE {$wpdb->prefix}frm_actionscheduler_queue (
		action_entry varchar(255) NOT NULL default '',
		time datetime NOT NULL default '0000-00-00 00:00:00',
		recheck tinyint(1) NULL,
		PRIMARY KEY  (action_entry)
		) ENGINE=InnoDB {$charset_collate};");

}