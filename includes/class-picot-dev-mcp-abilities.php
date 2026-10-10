<?php
/**
 * Ability registration and the five public MCP tools.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Abilities class.
 */
class Picot_Dev_Mcp_Abilities {

	/**
	 * Public MCP tools.
	 *
	 * @var string[]
	 */
	const GATEWAY = array(
		'picot-developer/dev-discover',
		'picot-developer/dev-inspect',
		'picot-developer/dev-execute',
		'picot-developer/dev-compare',
		'picot-developer/dev-history',
	);

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
	 * Hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the picot-developer category.
	 *
	 * @return void
	 */
	public function register_category() {
		wp_register_ability_category(
			'picot-developer',
			array(
				'label'       => __( 'Picot Developer', 'picot-developer-mcp' ),
				'description' => __( 'テーマ・プラグイン開発とサイト間移植。', 'picot-developer-mcp' ),
			)
		);
	}

	/**
	 * Register gateway tools and internal abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		$this->register_gateway();
		foreach ( Picot_Dev_Mcp_Registry::catalog() as $alias => $def ) {
			$alias = (string) $alias;
			wp_register_ability(
				Picot_Dev_Mcp_Registry::ability_name( $alias ),
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => 'picot-developer',
					'input_schema'        => self::loose_object_schema(),
					'output_schema'       => self::result_schema(),
					'execute_callback'    => static function ( $input ) use ( $alias ) {
						return Picot_Dev_Mcp_Registry::invoke( $alias, $input );
					},
					'permission_callback' => static function ( $input ) use ( $alias ) {
						return Picot_Dev_Mcp_Registry::permission( $alias, $input );
					},
					'meta'                => array(
						'public'      => false,
						'show_in_rest'=> false,
						'annotations' => array(
							'readonly'    => ! empty( $def['readonly'] ),
							'destructive' => ! empty( $def['writes'] ),
							'idempotent'  => false,
						),
					),
				)
			);
		}
	}

	/**
	 * Gateway tools exposed on the MCP server.
	 *
	 * @return void
	 */
	private function register_gateway() {
		$schema = self::loose_object_schema();
		$out    = self::result_schema();
		$tools  = array(
			'picot-developer/dev-discover' => array(
				'label'       => __( '開発機能を探す', 'picot-developer-mcp' ),
				'description' => __( '利用可能な Developer Ability を検索する。group: plugin, theme, component, migration, validation, snapshot, settings, site。', 'picot-developer-mcp' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'discover' ),
			),
			'picot-developer/dev-inspect'  => array(
				'label'       => __( '開発対象を調べる', 'picot-developer-mcp' ),
				'description' => __( 'target: active_theme, development_plugin, component, migration, snapshot, site。テーマ名は指定しない。', 'picot-developer-mcp' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'inspect' ),
			),
			'picot-developer/dev-execute'  => array(
				'label'       => __( '開発操作を実行する', 'picot-developer-mcp' ),
				'description' => __( 'ability と input で変更系Abilityを実行する。Productionでは confirm_production が必要。', 'picot-developer-mcp' ),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'execute' ),
			),
			'picot-developer/dev-compare'  => array(
				'label'       => __( 'サイトを比較する', 'picot-developer-mcp' ),
				'description' => __( 'subject: theme, plugin, manifest, settings, content, environment。remote に相手サイトの取得結果を渡す。', 'picot-developer-mcp' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'compare' ),
			),
			'picot-developer/dev-history'  => array(
				'label'       => __( '履歴を取得する', 'picot-developer-mcp' ),
				'description' => __( 'type: development, migration, snapshot, validation, audit。', 'picot-developer-mcp' ),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'history' ),
			),
		);
		foreach ( $tools as $name => $tool ) {
			wp_register_ability(
				$name,
				array(
					'label'               => $tool['label'],
					'description'         => $tool['description'],
					'category'            => 'picot-developer',
					'input_schema'        => $schema,
					'output_schema'       => $out,
					'execute_callback'    => $tool['callback'],
					'permission_callback' => array( 'Picot_Dev_Mcp_Permissions', 'authorize_gateway' ),
					'meta'                => array(
						'public'      => true,
						'show_in_rest'=> false,
						'annotations' => array(
							'readonly'    => $tool['readonly'],
							'destructive' => ! $tool['readonly'],
							'idempotent'  => $tool['readonly'],
						),
					),
				)
			);
		}
	}

	/**
	 * dev_discover.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function discover( $input ) {
		$input = is_array( $input ) ? $input : array();
		return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Registry::discover( $input ) );
	}

	/**
	 * dev_inspect.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function inspect( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$target = sanitize_key( (string) ( $input['target'] ?? 'site' ) );
		$map    = array(
			'active_theme'         => 'theme/inspect',
			'development_plugin'   => 'plugin/inspect',
			'component'            => 'theme/component-inspect',
			'migration'            => 'migration/detail',
			'snapshot'             => 'snapshot/detail',
			'site'                 => 'site/inspect',
		);
		if ( 'component' === $target && ! empty( $input['plugin'] ) ) {
			$map['component'] = 'plugin/file-read';
		}
		$alias = $map[ $target ] ?? '';
		if ( '' === $alias ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'inspect の target が不正です。', 'picot-developer-mcp' ) ) );
		}
		return Picot_Dev_Mcp_Registry::dispatch( $alias, $input );
	}

	/**
	 * dev_execute.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$alias = (string) ( $input['ability'] ?? '' );
		$inner = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		if ( ! empty( $input['confirm_production'] ) ) {
			$inner['confirm_production'] = true;
		}
		if ( '' === $alias ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ability が必要です。', 'picot-developer-mcp' ) ) );
		}
		return Picot_Dev_Mcp_Registry::dispatch( $alias, $inner );
	}

	/**
	 * dev_compare.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function compare( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$subject = sanitize_key( (string) ( $input['subject'] ?? '' ) );
		$alias   = 'compare/' . $subject;
		if ( ! isset( Picot_Dev_Mcp_Registry::catalog()[ $alias ] ) ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'compare の subject が不正です。', 'picot-developer-mcp' ) ) );
		}
		return Picot_Dev_Mcp_Registry::dispatch( $alias, $input );
	}

	/**
	 * dev_history.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function history( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = sanitize_key( (string) ( $input['type'] ?? 'development' ) );
		if ( 'snapshot' === $type ) {
			return Picot_Dev_Mcp_Registry::dispatch( 'snapshot/list', $input );
		}
		if ( 'migration' === $type ) {
			return Picot_Dev_Mcp_Registry::dispatch( 'migration/list', $input );
		}
		if ( 'validation' === $type ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Validation::recent_logs( (int) ( $input['limit'] ?? 40 ) ) );
		}
		$which = 'audit' === $type ? 'audit' : 'history';
		return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Audit::list_rows( $which, (int) ( $input['limit'] ?? 50 ), (int) ( $input['offset'] ?? 0 ) ) );
	}

	/**
	 * Object schema that keeps caller fields.
	 *
	 * @return array<string, mixed>
	 */
	public static function loose_object_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
	}

	/**
	 * Result schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function result_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array( 'description' => 'Result payload.' ),
				'error'   => array( 'type' => 'string' ),
				'code'    => array( 'type' => 'string' ),
			),
		);
	}
}
