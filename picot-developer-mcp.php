<?php
/**
 * Plugin Name: Picot Developer MCP
 * Plugin URI: https://picot.tokyo/
 * Description: AI向けのWordPress開発ブリッジ。有効なテーマと指定したプラグインだけを開発・検証し、成果物を別サイトへ移植します。
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Tested up to: 7.2
 * Author: PICOT
 * Author URI: https://picot.tokyo/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: picot-developer-mcp
 * Domain Path: /languages
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PICOT_DEV_MCP_VERSION', '1.0.0' );
define( 'PICOT_DEV_MCP_FILE', __FILE__ );
define( 'PICOT_DEV_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PICOT_DEV_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'PICOT_DEV_MCP_OPTION', 'picot_dev_mcp_settings' );

$picot_dev_mcp_files = array(
	'includes/class-picot-dev-mcp-errors.php',
	'includes/class-picot-dev-mcp-capabilities.php',
	'includes/class-picot-dev-mcp-settings.php',
	'includes/class-picot-dev-mcp-storage.php',
	'includes/class-picot-dev-mcp-api-key.php',
	'includes/class-picot-dev-mcp-auth.php',
	'includes/class-picot-dev-mcp-permissions.php',
	'includes/class-picot-dev-mcp-audit.php',
	'includes/class-picot-dev-mcp-paths.php',
	'includes/class-picot-dev-mcp-files.php',
	'includes/class-picot-dev-mcp-diff.php',
	'includes/class-picot-dev-mcp-validation.php',
	'includes/class-picot-dev-mcp-site.php',
	'includes/class-picot-dev-mcp-manifest.php',
	'includes/class-picot-dev-mcp-snapshot.php',
	'includes/class-picot-dev-mcp-change.php',
	'includes/class-picot-dev-mcp-theme.php',
	'includes/class-picot-dev-mcp-plugin-dev.php',
	'includes/class-picot-dev-mcp-transfer.php',
	'includes/class-picot-dev-mcp-content.php',
	'includes/class-picot-dev-mcp-migration.php',
	'includes/class-picot-dev-mcp-registry.php',
	'includes/class-picot-dev-mcp-abilities.php',
	'includes/class-picot-dev-mcp-server.php',
	'includes/class-picot-dev-mcp-admin.php',
	'includes/class-picot-dev-mcp-plugin.php',
);
foreach ( $picot_dev_mcp_files as $picot_dev_mcp_file ) {
	require_once PICOT_DEV_MCP_PATH . $picot_dev_mcp_file;
}

register_activation_hook( __FILE__, array( 'Picot_Dev_Mcp_Plugin', 'activate' ) );

/**
 * Whether the current admin screen should show dependency notices.
 *
 * @return bool
 */
function picot_dev_mcp_is_plugin_admin_screen() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen || empty( $screen->id ) ) {
		return false;
	}
	return in_array( $screen->id, array( 'plugins', 'plugin-install', 'settings_page_picot-developer-mcp' ), true );
}

if ( ! file_exists( PICOT_DEV_MCP_PATH . 'vendor/autoload_packages.php' ) && ! file_exists( PICOT_DEV_MCP_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) || ! picot_dev_mcp_is_plugin_admin_screen() ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Picot Developer MCP の依存パッケージがありません。プラグインディレクトリで composer install を実行してください。', 'picot-developer-mcp' );
			echo '</p></div>';
		}
	);
	return;
}

if ( file_exists( PICOT_DEV_MCP_PATH . 'vendor/autoload_packages.php' ) ) {
	require_once PICOT_DEV_MCP_PATH . 'vendor/autoload_packages.php';
} else {
	require_once PICOT_DEV_MCP_PATH . 'vendor/autoload.php';
}

/**
 * Bootstrap after plugins load.
 *
 * @return void
 */
function picot_dev_mcp_bootstrap() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) || ! picot_dev_mcp_is_plugin_admin_screen() ) {
					return;
				}
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Picot Developer MCP は WordPress 6.9 以降（Abilities API）が必要です。', 'picot-developer-mcp' ) . '</p></div>';
			}
		);
		return;
	}
	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) || ! picot_dev_mcp_is_plugin_admin_screen() ) {
					return;
				}
				echo '<div class="notice notice-error"><p>' . esc_html__( 'WordPress MCP Adapter を読み込めません。composer install を再実行してください。', 'picot-developer-mcp' ) . '</p></div>';
			}
		);
		return;
	}
	Picot_Dev_Mcp_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'picot_dev_mcp_bootstrap' );
