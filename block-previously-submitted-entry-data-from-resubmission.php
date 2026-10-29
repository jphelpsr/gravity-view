<?php
/**
 *
 * 1. Duplicate check (embedded form and Edit Entry)
 *    Blocks a submission if the same user already has an entry with the same
 *    field so other users are never blocked.
 *
 * 2. Read-only fields (GravityView Edit Entry)
 *    When a logged-in user edits their own entry, fields 1, 7 and 5 are shown but locked,
 *    and their saved values are re-applied on save so they can't be changed.
 *    Admins editing someone else's entry can still change them (see owner check below).
 *
 */
class GW_Unique_Field_Combination {

	const FORM_ID   = 17;
	const FIELD_IDS = array( 1, 7, 5 );
	const MESSAGE   = 'Entry already exists. Please edit the existing entry.';

	public static function init() {
		// Read-only fields: render (GravityKit's documented pattern, priority 20 runs after GravityView).
		add_action( 'gravityview/edit-entry/render/before', array( __CLASS__, 'add_render_filter' ) );
		add_action( 'gravityview/edit-entry/render/after', array( __CLASS__, 'remove_render_filter' ) );

		// Read-only fields: save.
		add_filter( 'gform_pre_validation_' . self::FORM_ID, array( __CLASS__, 'force_saved_values' ) );

		// Duplicate check: priority 20 so it runs after GravityView's Edit Entry validation.
		add_filter( 'gform_validation_' . self::FORM_ID, array( __CLASS__, 'check_duplicates' ), 20 );
	}

	/* ---------- Helpers ---------- */

	private static function log( $msg ) {
		if ( class_exists( 'GFCommon' ) ) {
			GFCommon::log_debug( 'GW Unique Field Combination: ' . $msg );
		}
	}

	/** The entry GravityView is editing, loaded fresh from the database (pre-save values), or false. */
	private static function get_edit_entry() {
		$entry_id = 0;

		if ( class_exists( 'GravityView_Edit_Entry' ) ) {
			$gv     = GravityView_Edit_Entry::getInstance();
			$render = isset( $gv->instances['render'] ) ? $gv->instances['render'] : null;
			if ( $render && ! empty( $render->entry['id'] ) ) {
				$entry_id = (int) $render->entry['id'];
			}
		}

		if ( ! $entry_id && rgpost( 'is_gv_edit_entry' ) && rgpost( 'lid' ) ) {
			$entry_id = absint( rgpost( 'lid' ) );
		}

		if ( ! $entry_id ) {
			self::log( 'Could not find the entry being edited.' );
			return false;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || (int) rgar( $entry, 'form_id' ) !== self::FORM_ID ) {
			return false;
		}

		return $entry;
	}

	/** Whether the fields should be locked for the current user on this entry. */
	private static function should_lock( $entry ) {
		// Owner check: return true here instead to lock the fields for everyone, admins included.
		$user_id = get_current_user_id();
		$owned   = $user_id && (int) rgar( $entry, 'created_by' ) === $user_id;

		if ( ! $owned ) {
			self::log( sprintf( 'Entry #%d not owned by user %d, fields left editable.', $entry['id'], $user_id ) );
		}

		return $owned;
	}

	/* ---------- Read-only fields ---------- */

	public static function add_render_filter() {
		self::log( 'Edit Entry render started.' );
		add_filter( 'gform_field_content', array( __CLASS__, 'lock_field' ), 20, 5 );
	}

	public static function remove_render_filter() {
		remove_filter( 'gform_field_content', array( __CLASS__, 'lock_field' ), 20 );
	}

	/** Disable the select and post the saved value through a hidden input. */
	public static function lock_field( $content, $field, $value, $entry_id, $form_id ) {
		static $entry = null;

		if ( (int) $form_id !== self::FORM_ID || ! in_array( (int) $field->id, self::FIELD_IDS, true ) ) {
			return $content;
		}

		if ( null === $entry ) {
			$entry = self::get_edit_entry();
			if ( $entry && ! self::should_lock( $entry ) ) {
				$entry = false;
			}
		}
		if ( ! $entry ) {
			return $content;
		}

		$saved = rgar( $entry, (string) $field->id );

		// Disabled selects aren't submitted, so the hidden input carries the saved value.
		$content  = preg_replace( '/<select\b/', '<select disabled="disabled" aria-readonly="true"', $content, 1 );
		$content .= sprintf( '<input type="hidden" name="input_%d" value="%s" />', $field->id, esc_attr( $saved ) );

		self::log( sprintf( 'Locked field %d on entry #%d.', $field->id, $entry['id'] ) );

		return $content;
	}

	/** On an Edit Entry save, put the saved values back before validation and update. */
	public static function force_saved_values( $form ) {
		if ( ! rgpost( 'is_gv_edit_entry' ) ) {
			return $form; // Regular form submission.
		}

		$entry = self::get_edit_entry();
		if ( ! $entry || ! self::should_lock( $entry ) ) {
			return $form;
		}

		foreach ( self::FIELD_IDS as $field_id ) {
			$_POST[ 'input_' . $field_id ] = rgar( $entry, (string) $field_id );
		}

		self::log( sprintf( 'Restored saved values on entry #%d before update.', $entry['id'] ) );

		return $form;
	}

	/* ---------- Duplicate check ---------- */

	public static function check_duplicates( $validation_result ) {
		$form       = $validation_result['form'];
		$edit_entry = rgpost( 'is_gv_edit_entry' ) ? self::get_edit_entry() : false;
		$edit_id    = $edit_entry ? (int) $edit_entry['id'] : 0;

		// Check against the entry owner's entries when editing, else the current user's.
		$owner_id = $edit_entry ? (int) rgar( $edit_entry, 'created_by' ) : get_current_user_id();

		self::log( sprintf( 'Duplicate check | Owner user ID: %d | Editing entry ID: %d', $owner_id, $edit_id ) );

		if ( ! $owner_id ) {
			self::log( 'No user ID, skipping duplicate check.' );
			return $validation_result;
		}

		$filters = array(
			'mode' => 'all',
			array( 'key' => 'created_by', 'value' => $owner_id ), // Remove this line to enforce across ALL users.
		);

		foreach ( self::FIELD_IDS as $field_id ) {
			$value = rgpost( 'input_' . $field_id );

			// When editing, a field missing from the Edit Entry layout isn't posted: use the saved value.
			if ( rgblank( $value ) && $edit_entry ) {
				$value = rgar( $edit_entry, (string) $field_id );
			}

			if ( rgblank( $value ) || is_array( $value ) ) {
				self::log( 'Field ' . $field_id . ' value is empty or an array, skipping check: ' . print_r( $value, true ) );
				return $validation_result; // Required-field validation handles empty values.
			}

			$filters[] = array( 'key' => (string) $field_id, 'value' => $value );
		}

		$entries = GFAPI::get_entries(
			$form['id'],
			array( 'status' => 'active', 'field_filters' => $filters ),
			null,
			array( 'offset' => 0, 'page_size' => 5 )
		);

		if ( is_wp_error( $entries ) ) {
			self::log( 'GFAPI error: ' . $entries->get_error_message() );
			return $validation_result;
		}

		self::log( 'Search filters: ' . print_r( $filters, true ) . ' Matching entries: ' . count( $entries ) );

		foreach ( $entries as $entry ) {
			if ( (int) $entry['id'] === $edit_id ) {
				continue; // Ignore the entry being edited.
			}

			$validation_result['is_valid'] = false;

			foreach ( $form['fields'] as &$field ) {
				if ( in_array( (int) $field->id, self::FIELD_IDS, true ) ) {
					$field->failed_validation  = true;
					$field->validation_message = self::MESSAGE;
				}
			}
			unset( $field );

			$validation_result['form'] = $form;
			self::log( 'Duplicate found (entry #' . $entry['id'] . '). Submission blocked.' );
			break;
		}

		return $validation_result;
	}
}

add_action( 'init', array( 'GW_Unique_Field_Combination', 'init' ) );
