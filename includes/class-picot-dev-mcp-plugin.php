<?php
/**
 * Plugin orchestrator.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Plugin class.
 */
class Picot_Dev_Mcp_Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot components.
	 *
	 * @return void
	 */
	public function init() {
		Picot_Dev_Mcp_Capabilities::register();
		Picot_Dev_Mcp_Settings::instance();
		Picot_Dev_Mcp_Auth::instance()->init();
		Picot_Dev_Mcp_Abilities::instance()->init();
		Picot_Dev_Mcp_Server::instance()->init();
		add_action( 'rest_api_init', array( 'Picot_Dev_Mcp_Transfer', 'register_routes' ) );
		if ( is_admin() ) {
			Picot_Dev_Mcp_Admin::instance()->init();
		}
	}

	/**
	 * Activation defaults.
	 *
	 * @return void
	 */
	public static function activate() {
		Picot_Dev_Mcp_Capabilities::register();
		if ( null === get_option( PICOT_DEV_MCP_OPTION, null ) ) {
			add_option( PICOT_DEV_MCP_OPTION, Picot_Dev_Mcp_Settings::defaults(), '', false );
		}
		Picot_Dev_Mcp_Settings::instance()->all();
		Picot_Dev_Mcp_Storage::base_dir();
	}
}
