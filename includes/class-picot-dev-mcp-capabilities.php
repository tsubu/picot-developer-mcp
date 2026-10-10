<?php
/**
 * Capability helpers.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Capabilities class.
 */
class Picot_Dev_Mcp_Capabilities {

	const CAP       = 'manage_picot_developer_mcp';
	const ADMIN_CAP = 'manage_options';

	/**
	 * Whether the current user may administer this plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::ADMIN_CAP );
	}

	/**
	 * Grant the legacy cap to administrators only.
	 *
	 * @return void
	 */
	public static function register() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}

		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			if ( 'administrator' === $role_name ) {
				continue;
			}
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	/**
	 * Remove the custom capability from every role.
	 *
	 * @return void
	 */
	public static function unregister() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}
}
