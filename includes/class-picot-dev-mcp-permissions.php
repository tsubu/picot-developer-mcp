<?php
/**
 * Permission checks: API key, capability, feature, operation, production.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Permissions class.
 */
class Picot_Dev_Mcp_Permissions {

	/**
	 * Authorize one catalog ability.
	 *
	 * @param array $definition Catalog row.
	 * @param array $input      Ability input.
	 * @return true|WP_Error
	 */
	public static function authorize( array $definition, $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( '管理者権限が必要です。', 'picot-developer-mcp' ) );
		}

		$feature = isset( $definition['feature'] ) ? (string) $definition['feature'] : '';
		if ( '' !== $feature && ! Picot_Dev_Mcp_Settings::instance()->is_feature_enabled( $feature ) ) {
			return Picot_Dev_Mcp_Errors::make( 'feature_disabled', __( 'このDeveloper機能は無効です。', 'picot-developer-mcp' ) );
		}

		$operation = isset( $definition['operation'] ) ? (string) $definition['operation'] : '';
		if ( '' !== $operation && ! Picot_Dev_Mcp_Settings::instance()->is_operation_enabled( $operation ) ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( 'この操作権限は無効です。', 'picot-developer-mcp' ) );
		}

		if ( ! empty( $definition['writes'] ) ) {
			$production = self::authorize_production_write( $input, ! empty( $definition['rollback'] ) );
			if ( is_wp_error( $production ) ) {
				return $production;
			}
		}

		return true;
	}

	/**
	 * Production writes require the explicit setting and a confirmation flag.
	 * Rollback stays available so a bad change can still be undone.
	 *
	 * @param array $input     Input.
	 * @param bool  $rollback  Whether this call restores a snapshot.
	 * @return true|WP_Error
	 */
	public static function authorize_production_write( array $input, $rollback = false ) {
		if ( $rollback || ! self::is_production() ) {
			return true;
		}
		if ( ! Picot_Dev_Mcp_Settings::instance()->is_operation_enabled( 'production_apply' ) ) {
			return Picot_Dev_Mcp_Errors::make(
				'permission_denied',
				__( 'Productionへの直接反映は無効です。', 'picot-developer-mcp' ),
				array( 'environment' => 'production' )
			);
		}
		if ( empty( $input['confirm_production'] ) ) {
			return Picot_Dev_Mcp_Errors::make(
				'permission_denied',
				__( 'Productionへの反映には confirm_production: true が必要です。先にDry RunとSnapshotを確認してください。', 'picot-developer-mcp' ),
				array( 'environment' => 'production' )
			);
		}
		return true;
	}

	/**
	 * Whether this WordPress environment is production.
	 *
	 * @return bool
	 */
	public static function is_production() {
		return function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type();
	}

	/**
	 * Gateway tools: authenticated administrator while the server is enabled.
	 *
	 * @return true|WP_Error
	 */
	public static function authorize_gateway() {
		if ( ! Picot_Dev_Mcp_Settings::instance()->is_enabled() ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( 'MCPサーバーは無効です。', 'picot-developer-mcp' ) );
		}
		$token = Picot_Dev_Mcp_Auth::instance()->get_current_token();
		if ( ! is_array( $token ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキー認証が必要です。', 'picot-developer-mcp' ) );
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( '管理者権限が必要です。', 'picot-developer-mcp' ) );
		}
		return true;
	}
}
