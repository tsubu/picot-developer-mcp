<?php
/**
 * Uninstall cleanup.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'picot_dev_mcp_settings' );
delete_option( 'picot_dev_mcp_tokens' );
delete_option( 'picot_dev_mcp_history' );
delete_option( 'picot_dev_mcp_audit' );
delete_option( 'picot_dev_mcp_snapshot_index' );
delete_option( 'picot_dev_mcp_migration_index' );
delete_option( 'picot_dev_mcp_transfer_tokens' );

global $wpdb;
$picot_dev_like    = $wpdb->esc_like( 'picot_dev_mcp_seq_' ) . '%';
$picot_dev_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $picot_dev_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( is_array( $picot_dev_options ) ) {
	foreach ( $picot_dev_options as $picot_dev_option ) {
		delete_option( (string) $picot_dev_option );
	}
}

$picot_dev_uploads = wp_upload_dir( null, false );
if ( empty( $picot_dev_uploads['error'] ) && ! empty( $picot_dev_uploads['basedir'] ) ) {
	$picot_dev_dir_names = array( 'picot-developer-mcp' );
	$picot_dev_dir_name  = (string) get_option( 'picot_dev_mcp_storage_dir', '' );
	if ( preg_match( '/^picot-developer-mcp-[a-z0-9]{20}$/', $picot_dev_dir_name ) ) {
		$picot_dev_dir_names[] = $picot_dev_dir_name;
	}
	foreach ( $picot_dev_dir_names as $picot_dev_dir_name ) {
		$picot_dev_dir = trailingslashit( $picot_dev_uploads['basedir'] ) . $picot_dev_dir_name;
		if ( is_dir( $picot_dev_dir ) ) {
			$picot_dev_iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $picot_dev_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $picot_dev_iterator as $picot_dev_item ) {
				if ( $picot_dev_item->isDir() ) {
					rmdir( $picot_dev_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					wp_delete_file( $picot_dev_item->getPathname() );
				}
			}
			rmdir( $picot_dev_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}
delete_option( 'picot_dev_mcp_storage_dir' );

if ( ! function_exists( 'get_role' ) ) {
	return;
}
global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
foreach ( array_keys( $wp_roles->roles ) as $picot_dev_role_name ) {
	$picot_dev_role = get_role( $picot_dev_role_name );
	if ( $picot_dev_role && $picot_dev_role->has_cap( 'manage_picot_developer_mcp' ) ) {
		$picot_dev_role->remove_cap( 'manage_picot_developer_mcp' );
	}
}
