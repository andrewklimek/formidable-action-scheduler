<?php

class FrmActionSchedulerHelper {

	/**
	 * Returns the complete url to this plugin's root directory, possibly to a subudirectory
	 *
	 * @param string $subpath - will be added to the end of the url
	 *
	 * @return string the url
	 */
	public static function plugin_url( $subpath = '' ) {
		return plugins_url( 'formidable-action-scheduler/' . $subpath );
	}

	/**
	 * Returns the complete path to this plugin's root directory, possibly to a subudirectory
	 *
	 * @param string $subpath - will be added to the end of the path
	 *
	 * @return string the path
	 */
	public static function plugin_path( $subpath = '' ) {
		return trailingslashit( realpath( __DIR__ . '/../../' ) ) . $subpath;
	}

	/**
	 * Autoloader for Formidable Autoresponder classes
	 *
	 * @param string $class_name - the class name
	 *
	 * @return void
	 */
	public static function autoload( $class_name ) {
		// Only load FrmActionScheduler classes here
		if ( ! preg_match('/^FrmActionScheduler.*$/', $class_name) ) {
			return;
		}

		$filepath = self::plugin_path( 'classes' );

		if ( preg_match('/^.+Helper$/', $class_name) ) {
			$filepath .= '/helpers/';
		} else if ( preg_match('/^.+Controller$/', $class_name) ) {
			$filepath .= '/controllers/';
		} else {
			$filepath .= '/models/';
		}

		$filepath .= $class_name .'.php';
		if ( file_exists( $filepath ) ) {
			include_once $filepath;
		}
	}

	public static function allowed_actions() {
		return apply_filters( 'frm_autoresponder_allowed_actions', array( 'email', 'twilio', 'api' ) );
	}

	public static function is_allowed_action( $action ) {
		return in_array( $action, self::allowed_actions() );
	}
}
