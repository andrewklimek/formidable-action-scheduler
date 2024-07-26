<?php

class FrmActionScheduler {
	/**
	 * The autoresponder is an array with the following elements:
	 *     bool     is_active           - whether an autoresponder is active for the action
	 *     bool     do_default_trigger  - either 'yes' or 'no'.  'yes' means the default Formidable Pro email will still be
	 *                                    sent immediately.  'no' means that gets skipped.  Autoresponder gets queued
	 *                                    regardless
	 *     string   send_date           - the reference date.  either 'update' or 'create' ( for date the entry was
	 *                                    updated or created ) or a numeric field id to a date field
	 *     string   send_before_after   - 'before' means send before reference date, 'after' means send after
	 *     string   send_unit           - 'minutes', 'hours', 'days', 'months', 'years'.  Potentially could be anything that
	 *                                     works in the form strtotime( sprintf( "-$x %s", $send_unit ) );
	 *     int      send_interval       - how many send_unit's we will schedule for.  Positive integer
	 *     bool     send_after          - whether to schedule another autoresponder after the initial one
	 *     bool     send_after_limit    - whether to set a maximum number of times
	 *     null|int send_after_count    - if provided, the maximum number of times ( in total, including the very first
	 *                                    autoresponder ) to send this notification.  If null, then there is no limit.
	 *                                    This has no effect unless 'send_after' and 'send_after_limit' are true
	 *     string   send_after_unit     - like send_unit but for "after" autoresponders
	 *     string   send_after_interval - like send_interval but for "after" autoresponders
	 *     string   send_after_interval_type - tells us what the send_after_interval refers to, either a 'number' ( default )
	 *                                    or a 'field'
	 *     int      send_after_interval_field - the id of the field from which to decide the "send_after_interval" ( must
	 *                                    be a number field
	 *     bool     debug               - whether or not to turn debug on for this autoresponder
	 *
	 * @return array
	 */
	public static function get_default_autoresponder() {
		$defaults = [
			'is_active'                 => true,
			'do_default_trigger'        => 'no',
			'recheck'                   => null,
			'send_date'                 => 'update',
			'send_before_after'         => 'after',
			'send_unit'                 => 'days',
			'send_interval'             => 30,
			'send_after'                => false,
			'send_after_limit'          => null,
			'send_after_count'          => null,
			'send_after_unit'           => null,
			'send_after_interval'       => null,
			'send_after_interval_type'  => null,
			'send_after_interval_field' => null,
			'debug'                     => false,
		];
		return apply_filters( 'formidable_autoresponder_defaults', $defaults );
	}
	
	public static function get_blank_autoresponder_array() {
		$defaults = [
			'is_active'                 => '',
			'do_default_trigger'        => '',
			'recheck'                   => '',
			'send_date'                 => '',
			'send_before_after'         => '',
			'send_unit'                 => '',
			'send_interval'             => '',
			'send_after'                => '',
			'send_after_limit'          => '',
			'send_after_count'          => '',
			'send_after_unit'           => '',
			'send_after_interval'       => '',
			'send_after_interval_type'  => '',
			'send_after_interval_field' => '',
			'debug'                     => '',
		];
		return $defaults;
	}

	/**
	 * Gets the action for the action.  This method serves the purpose of normalizing an action for use within the
	 * Formidable Autoresponder plugin.  It can be passed an instantiated action object or an action id and it will
	 * return an object that has a ->post_content member that is an array that may contain a ['autoresponder'] element.
	 *
	 * @param int|object $action either an action id or an action object
	 *
	 * @return bool|object an object if it's a valid "autorespondable" action, or the boolean false if it isn't.
	 *                     Note, just because it's a valid action doesn't mean it has an autoresponder.
	 */
	public static function get_action( $action ) {
		if ( is_numeric( $action ) ) {
			$action_id = $action;
			$action = get_post( $action_id );
		} else {
			$action = (object) $action;
			$action_id = $action->ID;
		}
		return FrmFormAction::get_single_action_type( $action_id, $action->post_excerpt );
	}

	/**
	 * Returns an autoresponder for an action, if the action has one.  If it doesn't, this method returns false.
	 *
	 * @param object $action the post object for the action
	 *
	 * @return array|bool array if it's an autoresponder, false otherwise
	 */
	public static function get_autoresponder( $action ) {
		$responder = false;
		if ( ! $action ) return $responder;
		if ( ! is_object( $action ) || empty( $action->post_content['autoresponder'] ) ) $action = self::get_action( $action );
		if ( $action && isset( $action->post_content['autoresponder'] ) ) {
			$settings = $action->post_content['autoresponder'];
			if ( $settings['is_active'] ){
				if ( $settings['send_date'] === 'create' ) $settings['send_date'] = 'update';// migrate
				$defaults = self::get_default_autoresponder();
				$responder = array_merge( $defaults, $settings );
			}
		}
		return $responder;
	}

	/**
	 * Adds an autoresponder to an action.  Note, this is adding a bunch of autoresponder settings to a Formidable
	 * action.  It is not setting up an autoresponder for a particular entry.
	 *
	 * @param object $action the post object for the action
	 * @param array $autoresponder the autoresponder you want to add to the action
	 *
	 * @return object the object with the autoresponder added.  Unchanged if unable to add an autoresponder
	 */
	public static function set_autoresponder( $action, $autoresponder ) {
		error_log("set_autoresponder ran... I didn't think it ever id. DELETE THIS DEBUG LINE");
		$normalized_action = self::get_action( $action );
		if ( $normalized_action ) {
			$action = $normalized_action;
			$autoresponder = wp_parse_args( $autoresponder, self::get_default_autoresponder() );
			$action->post_content['autoresponder'] = $autoresponder;
			FrmDb::save_settings( $action, 'frm_actions' );
		}
		return $action;
	}

	/**
	 * Removes an autoresponder from an action.
	 *
	 * @param object $action the post object for the action.  Passed by reference and will be changed ( autoresponder
	 *                       will be removed )
	 *
	 * @return object the object with the autoresponder removed.  Unchanged if it didn't have an autoresponder.
	 */
	public static function remove_autoresponder( $action ) {
		$normalized_action = self::get_action( $action );
		if ( $normalized_action && isset( $normalized_action->post_content['autoresponder'] ) ) {
			$action = $normalized_action;
			unset( $action->post_content['autoresponder'] );
			FrmDb::save_settings( $action, 'frm_actions' );
		}
		return $action;
	}

}
