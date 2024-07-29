<?php

class FrmActionSchedulerSettingsController {

	/**
	 * Setup and include the settings view
	 */
	public static function form_action_settings( $form_action, $atts ) {
		if ( ! FrmActionSchedulerHelper::is_allowed_action( $form_action->post_excerpt ) ) {
			return;
		}

		$form = $atts['form'];
		$action_key = $atts['action_key'];
		$fields = $atts['values']['fields'];
		$action_id = (int) $form_action->ID;


		$has_number_field = false;
		$date_fields = array();
		$time_fields = array();
		foreach ( $fields as $field ) {
			if ( $field['type'] == 'number' ) {
				$has_number_field = true;
			} elseif ( $field['type'] == 'date' ) {
				$date_fields[] = $field;
			} elseif ( $field['type'] == 'time' ) {
				$time_fields[] = $field;
			}
		}

		$input_name = $atts['action_control']->get_field_name( 'autoresponder' );
		$autoresponder = FrmActionScheduler::get_autoresponder( $form_action );
		
		if ( false === $autoresponder ) {
			$autoresponder = FrmActionScheduler::get_blank_autoresponder_array();
		}
		
		$is_active = $autoresponder['is_active'];
		$time_units = [
			'days'    => 'Days',
			'years'   => 'Years',
			'months'  => 'Months',
			'hours'   => 'Hours',
			'minutes' => 'Minutes',
		];

		$log = new FrmActionSchedulerLog( [ 'action' => $form_action ] );
		$debug_urls = $log->get_urls();
		$debug_urls_more = 5; // the number of logs to show initially before the "more" link

		global $wpdb;
		$queue = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue WHERE action_entry LIKE '{$action_id}_%'" );
		$queue_more = 5;

		$date_format = get_option('date_format') .' '. get_option('time_format');

		include( FrmActionSchedulerHelper::plugin_path( 'classes/views/settings.php' ) );

		static $once;
		if ( ! isset( $once ) ) {
			$once = 'done';
			?>
			<style>
			.dashicons.spin {
				animation: spin 1s linear infinite;
			}
			</style>
			<?php
		}
	}

	/**
	 * Enqueue Javascript
	 */
	public static function admin_js() {
		if ( filter_input( INPUT_GET, 'frm_action', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) === 'settings' ) {
			$path = 'resources/js/frm-action-scheduler-admin.js';
			wp_enqueue_script( 'frm-action-scheduler-admin', FrmActionSchedulerHelper::plugin_url( $path ), ['formidable_admin'], '1', true );
		}

		if ( ! function_exists( 'load_frm_autoresponder' ) ) {
			function load_frm_autoresponder(){
				// stop adding the disabled "setup automation" button in form actions that triggers an upgrade prompt
			}
		}
	}

	/**
	 * The AJAX listener for deleting a particular queue item.  It is based on the posted 
	 * values for timestamp, entry_id and action_id
	 * POST includes the keys 'timestamp', 'entry_id', 'action_id' ( array or url parameter string )
	 */
	public static function delete_queue_item_ajax() {
		FrmAppHelper::permission_check('frm_edit_forms');
		check_ajax_referer( 'frm_ajax', 'nonce' );

		FrmActionSchedulerAppController::unschedule( wp_parse_args( $_POST ) );
		echo "ok";

		wp_die();
	}

	/**
	 * A listener for the ajax action, takes the log entry in the autoresponder logs directory and displays it
	 */
	public static function log_viewer() {
		FrmAppHelper::permission_check('frm_edit_forms');
		check_ajax_referer( 'frm_ajax', 'nonce' );

		if ( isset( $_REQUEST['log'] ) ) {
			$url = sanitize_text_field( $_REQUEST['log'] );
			$log = new FrmActionSchedulerLog();
			$log->get_content( $url );
		}

		wp_die();
	}

	/**
	 * The AJAX listener for deleting a log file.  Does a sanity check to make sure that we are only attempting to
	 * delete real autoresponder log files.  Deletes the file based on the $_POST['url'] variable.
	 */
	public static function delete_log_ajax() {
		FrmAppHelper::permission_check('frm_edit_forms');
		check_ajax_referer( 'frm_ajax', 'nonce' );

		$url = sanitize_text_field( $_POST['url'] );
		$log = new FrmActionSchedulerLog();
		$log->delete( $url );

		wp_die();
	}

	public static function add_settings_section( $sections ) {
		if ( ! isset( $sections['actionscheduler'] ) ) {
			$sections['actionscheduler'] = array(
				'class'    => 'FrmActionSchedulerSettingsController',
				'function' => 'route',
				'name'     => 'Action Scheduler',
				'icon'     => 'frm_calendar_icon frm_icon_font',
			);
		}
		return $sections;
	}

	public static function route(){
		$action = isset( $_REQUEST['frm_action'] ) ? 'frm_action' : 'action';
		$action = FrmAppHelper::get_param( $action );
		if ( $action == 'process-form' ) {
			return self::process_form();
		} else {
			return self::display_form();
		}
	}

	public static function display_form(){
		$defer = get_option('frm_action_scheduler_defer_all', 0);
		$debug = get_option('frm_action_scheduler_debug', 'respect');

		include( FrmActionSchedulerHelper::plugin_path( 'classes/views/global-settings.php' ) );

	}

	public static function process_form(){
		
		if ( !empty( $_REQUEST['frm_action_scheduler_async'] ) ) {
			update_option( 'frm_action_scheduler_defer_all', 1 );
		} else {
			update_option( 'frm_action_scheduler_defer_all', 0 );
		}

		if ( is_numeric( $_REQUEST['frm_action_scheduler_debug'] ) ) {
			update_option( 'frm_action_scheduler_debug', $_REQUEST['frm_action_scheduler_debug'] );
		} else {
			delete_option( 'frm_action_scheduler_debug' );
		}
	
		self::display_form();
	}

}
