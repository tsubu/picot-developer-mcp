<?php
/**
 * MCP Adapter server for the five developer tools.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Server class.
 */
class Picot_Dev_Mcp_Server {

	const SERVER_ID = 'picot-developer-mcp';

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
	 * Register the server when the adapter boots.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		\WP\MCP\Core\McpAdapter::instance();
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
		add_filter( 'mcp_adapter_tool_name', array( $this, 'filter_tool_name' ), 10, 2 );
	}

	/**
	 * Create the HTTP MCP server.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter.
	 * @return void
	 */
	public function register_server( $adapter ) {
		if ( ! Picot_Dev_Mcp_Settings::instance()->is_enabled() ) {
			return;
		}
		$adapter->create_server(
			self::SERVER_ID,
			Picot_Dev_Mcp_Settings::instance()->route_namespace(),
			Picot_Dev_Mcp_Settings::instance()->route(),
			'Picot Developer MCP',
			__( 'Active theme and selected plugin development, with site-to-site migration.', 'picot-developer-mcp' ),
			PICOT_DEV_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			Picot_Dev_Mcp_Abilities::GATEWAY,
			array(),
			array(),
			array( Picot_Dev_Mcp_Auth::instance(), 'transport_permission' )
		);
	}

	/**
	 * Stable tool names from the spec.
	 *
	 * @param string      $name    Sanitized name.
	 * @param \WP_Ability $ability Ability.
	 * @return string
	 */
	public function filter_tool_name( $name, $ability ) {
		$map = array(
			'picot-developer/dev-discover' => 'dev_discover',
			'picot-developer/dev-inspect'  => 'dev_inspect',
			'picot-developer/dev-execute'  => 'dev_execute',
			'picot-developer/dev-compare'  => 'dev_compare',
			'picot-developer/dev-history'  => 'dev_history',
		);
		$ability_name = $ability->get_name();
		return $map[ $ability_name ] ?? $name;
	}

	/**
	 * Public MCP endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( Picot_Dev_Mcp_Settings::instance()->route_namespace() . '/' . Picot_Dev_Mcp_Settings::instance()->route() );
	}
}
