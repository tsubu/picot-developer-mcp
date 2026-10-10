<?php
/**
 * Internal ability catalog. MCP exposes five gateway tools that dispatch here.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Registry class.
 */
class Picot_Dev_Mcp_Registry {

	/**
	 * Catalog of developer abilities.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function catalog() {
		$items = array(
			'theme/inspect'            => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'read_files', 'label' => 'テーマ解析', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'inspect' ) ),
			'theme/files-list'         => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'read_files', 'label' => 'テーマファイル一覧', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'files_list' ) ),
			'theme/file-read'          => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'read_files', 'label' => 'テーマファイル読取', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'file_read' ) ),
			'theme/search'             => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'read_files', 'label' => 'テーマ検索', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'search' ) ),
			'theme/file-create'        => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'テーマファイル作成', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'file_create' ) ),
			'theme/file-patch'         => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'テーマファイルPatch', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'file_patch' ) ),
			'theme/component-list'     => array( 'group' => 'component', 'feature' => 'theme_components', 'operation' => 'read_files', 'label' => 'Theme Component一覧', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'component_list' ) ),
			'theme/component-inspect'  => array( 'group' => 'component', 'feature' => 'theme_components', 'operation' => 'read_files', 'label' => 'Theme Component詳細', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'component_inspect' ) ),
			'theme/component-create'   => array( 'group' => 'component', 'feature' => 'theme_components', 'operation' => 'create_components', 'writes' => true, 'label' => 'Theme Component作成', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'component_create' ) ),
			'theme/component-update'   => array( 'group' => 'component', 'feature' => 'theme_components', 'operation' => 'write_files', 'writes' => true, 'label' => 'Theme Component更新', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'component_update' ) ),
			'theme/component-delete'   => array( 'group' => 'component', 'feature' => 'theme_components', 'operation' => 'delete_files', 'writes' => true, 'label' => 'Theme Component削除', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'component_delete' ) ),
			'theme/diff'               => array( 'group' => 'theme', 'feature' => 'theme_dev', 'operation' => 'read_files', 'label' => 'テーマ差分', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'diff' ) ),
			'theme/validate'           => array( 'group' => 'validation', 'feature' => 'validation', 'operation' => 'validate', 'label' => 'テーマ検証', 'handler' => array( 'Picot_Dev_Mcp_Theme', 'validate' ) ),
			'plugin/inspect'           => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグイン解析', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'inspect' ) ),
			'plugin/files-list'        => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグインファイル一覧', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'files_list' ) ),
			'plugin/file-read'         => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグインファイル読取', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'file_read' ) ),
			'plugin/search'            => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグイン検索', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'search' ) ),
			'plugin/create'            => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'プラグイン作成', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'create' ) ),
			'plugin/file-create'       => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'プラグインファイル作成', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'file_create' ) ),
			'plugin/file-patch'        => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'プラグインファイルPatch', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'file_patch' ) ),
			'plugin/file-delete'       => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'delete_files', 'writes' => true, 'label' => 'プラグインファイル削除', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'file_delete' ) ),
			'plugin/component-create'  => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'create_components', 'writes' => true, 'label' => 'Plugin Component作成', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'component_create' ) ),
			'plugin/component-update'  => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'write_files', 'writes' => true, 'label' => 'Plugin Component更新', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'component_update' ) ),
			'plugin/diff'              => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグイン差分', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'diff' ) ),
			'plugin/validate'          => array( 'group' => 'validation', 'feature' => 'validation', 'operation' => 'validate', 'label' => 'プラグイン検証', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'validate' ) ),
			'plugin/package'           => array( 'group' => 'plugin', 'feature' => 'plugin_dev', 'operation' => 'read_files', 'label' => 'プラグインパッケージ', 'handler' => array( 'Picot_Dev_Mcp_Plugin_Dev', 'package' ) ),
			'snapshot/list'            => array( 'group' => 'snapshot', 'feature' => '', 'operation' => 'read_files', 'label' => 'Snapshot一覧', 'handler' => array( __CLASS__, 'snapshot_list' ) ),
			'snapshot/detail'          => array( 'group' => 'snapshot', 'feature' => '', 'operation' => 'read_files', 'label' => 'Snapshot詳細', 'handler' => array( __CLASS__, 'snapshot_detail' ) ),
			'snapshot/create'          => array( 'group' => 'snapshot', 'feature' => '', 'operation' => 'write_files', 'writes' => true, 'label' => 'Snapshot作成', 'handler' => array( __CLASS__, 'snapshot_create' ) ),
			'snapshot/rollback'        => array( 'group' => 'snapshot', 'feature' => '', 'operation' => 'write_files', 'writes' => true, 'rollback' => true, 'label' => 'Rollback', 'handler' => array( __CLASS__, 'snapshot_rollback' ) ),
			'site/inspect'             => array( 'group' => 'site', 'feature' => '', 'operation' => 'read_files', 'label' => 'サイト情報', 'handler' => array( 'Picot_Dev_Mcp_Site', 'inspect' ) ),
			'compare/theme'            => array( 'group' => 'migration', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => 'テーマ比較', 'handler' => array( __CLASS__, 'compare_theme' ) ),
			'compare/plugin'           => array( 'group' => 'migration', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => 'プラグイン比較', 'handler' => array( __CLASS__, 'compare_plugin' ) ),
			'compare/manifest'         => array( 'group' => 'migration', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => 'Manifest比較', 'handler' => array( __CLASS__, 'compare_manifest' ) ),
			'compare/settings'         => array( 'group' => 'settings', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => '設定比較', 'handler' => array( __CLASS__, 'compare_settings' ) ),
			'compare/content'          => array( 'group' => 'migration', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => 'コンテンツ比較', 'handler' => array( __CLASS__, 'compare_content' ) ),
			'compare/environment'      => array( 'group' => 'site', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => '環境比較', 'handler' => array( __CLASS__, 'compare_environment' ) ),
			'migration/export'         => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'label' => '移植エクスポート', 'handler' => array( 'Picot_Dev_Mcp_Migration', 'export' ) ),
			'migration/import'         => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'writes' => true, 'label' => '移植インポート', 'handler' => array( 'Picot_Dev_Mcp_Migration', 'import' ) ),
			'migration/dry-run'        => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'label' => '移植Dry Run', 'handler' => array( __CLASS__, 'migration_dry_run' ) ),
			'migration/list'           => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'label' => 'Migration履歴', 'handler' => array( 'Picot_Dev_Mcp_Migration', 'list_sessions' ) ),
			'migration/detail'         => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'label' => 'Migration詳細', 'handler' => array( 'Picot_Dev_Mcp_Migration', 'detail' ) ),
			'migration/rollback'       => array( 'group' => 'migration', 'feature' => 'site_migration', 'operation' => 'migration', 'writes' => true, 'rollback' => true, 'label' => 'Migration Rollback', 'handler' => array( 'Picot_Dev_Mcp_Migration', 'rollback' ) ),
			'settings/inspect'         => array( 'group' => 'settings', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => '設定参照', 'handler' => array( 'Picot_Dev_Mcp_Site', 'settings_inspect' ) ),
			'settings/compare'         => array( 'group' => 'settings', 'feature' => 'site_compare', 'operation' => 'site_compare', 'label' => '設定比較', 'handler' => array( __CLASS__, 'compare_settings' ) ),
			'settings/export'          => array( 'group' => 'settings', 'feature' => 'site_migration', 'operation' => 'migration', 'label' => '設定エクスポート', 'handler' => array( 'Picot_Dev_Mcp_Site', 'settings_export' ) ),
			'settings/import'          => array( 'group' => 'settings', 'feature' => 'site_migration', 'operation' => 'migration', 'writes' => true, 'label' => '設定インポート', 'handler' => array( __CLASS__, 'settings_import' ) ),
			'validation/run'           => array( 'group' => 'validation', 'feature' => 'validation', 'operation' => 'validate', 'label' => '検証実行', 'handler' => array( __CLASS__, 'validation_run' ) ),
			'validation/logs'          => array( 'group' => 'validation', 'feature' => 'validation', 'operation' => 'read_files', 'label' => 'ログ参照', 'handler' => array( __CLASS__, 'validation_logs' ) ),
		);

		foreach ( $items as $alias => $item ) {
			$items[ $alias ]['alias'] = $alias;
			$items[ $alias ]['readonly'] = empty( $item['writes'] );
			$description = self::description( $alias );
			$items[ $alias ]['description'] = '' !== $description ? $description : (string) $item['label'];
		}
		return $items;
	}

	/**
	 * Ability name in the WordPress Abilities API.
	 *
	 * @param string $alias Alias such as theme/inspect.
	 * @return string
	 */
	public static function ability_name( $alias ) {
		return 'picot-developer/' . str_replace( '/', '--', (string) $alias );
	}

	/**
	 * Resolve an alias from either form.
	 *
	 * @param string $name Alias or ability name.
	 * @return string
	 */
	public static function alias_from( $name ) {
		$name = (string) $name;
		if ( str_starts_with( $name, 'picot-developer/' ) ) {
			return str_replace( '--', '/', substr( $name, strlen( 'picot-developer/' ) ) );
		}
		return $name;
	}

	/**
	 * Short description for discovery.
	 *
	 * @param string $alias Alias.
	 * @return string
	 */
	private static function description( $alias ) {
		$notes = array(
			'theme/inspect'           => '現在有効なテーマだけを返す。別テーマの指定は theme_not_active。',
			'theme/file-read'         => 'scope=parent は子テーマ利用時の読み取り専用。scope=core は wp-includes と wp-admin の参照のみ。',
			'theme/component-create'  => 'type: template, template-part, pattern, style-variation, asset。',
			'plugin/create'           => '新規プラグインを作り、開発対象に設定する。',
			'plugin/file-patch'       => 'search / replace。Version など複数一致は all: true が必要。',
			'plugin/component-create' => 'type: settings-page, rest-api, custom-post-type, custom-taxonomy, gutenberg-block, shortcode, cron, admin-page, admin-table, database-table, cli-command。',
			'migration/import'        => '標準は dry_run。本適用は dry_run: false と confirm_apply: true。settings は items[] または name=>value。',
			'migration/export'        => 'テキストは同梱し、バイナリはワンタイム転送トークンにする。',
			'compare/theme'           => 'remote は相手サイトの manifest または site/inspect。new は remote のみ。',
			'settings/import'         => 'siteurl, home, 秘密情報, transient は移植しない。',
		);
		return $notes[ $alias ] ?? '';
	}

	/**
	 * Permission callback body.
	 *
	 * @param string $alias Alias.
	 * @param mixed  $input Input.
	 * @return true|WP_Error
	 */
	public static function permission( $alias, $input ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $alias ] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '未知のAbilityです。', 'picot-developer-mcp' ) );
		}
		return Picot_Dev_Mcp_Permissions::authorize( $catalog[ $alias ], is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute one internal ability and audit it.
	 *
	 * @param string $alias Alias.
	 * @param mixed  $input Input.
	 * @return mixed
	 */
	public static function invoke( $alias, $input ) {
		$catalog = self::catalog();
		$input   = is_array( $input ) ? $input : array();
		if ( ! isset( $catalog[ $alias ] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '未知のAbilityです。', 'picot-developer-mcp' ) );
		}
		$handler = $catalog[ $alias ]['handler'];
		$result  = call_user_func( $handler, $input );
		$target  = $input['path'] ?? $input['slug'] ?? $input['snapshot_id'] ?? $input['migration_id'] ?? '';
		if ( is_array( $target ) ) {
			$target = wp_json_encode( $target );
		} elseif ( ! is_scalar( $target ) ) {
			$target = '';
		}
		Picot_Dev_Mcp_Audit::audit(
			array(
				'ability' => $alias,
				'target'  => (string) $target,
				'input'   => $input,
				'files'   => is_array( $result ) ? (array) ( $result['files'] ?? array() ) : array(),
				'result'  => is_wp_error( $result ) ? 'error' : 'success',
				'code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
			)
		);
		return $result;
	}

	/**
	 * Dispatch from a gateway tool. Permission errors keep their stable codes.
	 *
	 * @param string $alias Alias.
	 * @param mixed  $input Input.
	 * @return array
	 */
	public static function dispatch( $alias, $input ) {
		$alias = self::alias_from( $alias );
		$name  = self::ability_name( $alias );
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Errors::make( 'feature_disabled', __( 'Abilities APIが利用できません。', 'picot-developer-mcp' ) ) );
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return Picot_Dev_Mcp_Errors::format( Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '未知のAbilityです。', 'picot-developer-mcp' ) ) );
		}
		$input = is_array( $input ) ? $input : array();
		$perm  = $ability->check_permissions( $input );
		if ( true !== $perm ) {
			$error = is_wp_error( $perm ) ? $perm : Picot_Dev_Mcp_Errors::make( 'permission_denied', __( '操作が拒否されました。', 'picot-developer-mcp' ) );
			Picot_Dev_Mcp_Audit::audit(
				array(
					'ability' => $alias,
					'input'   => $input,
					'result'  => 'error',
					'code'    => $error->get_error_code(),
				)
			);
			return Picot_Dev_Mcp_Errors::format( $error );
		}
		return Picot_Dev_Mcp_Errors::format( $ability->execute( $input ) );
	}

	/**
	 * Search the catalog.
	 *
	 * @param array $input Input.
	 * @return array<string, mixed>
	 */
	public static function discover( array $input ) {
		$query = strtolower( (string) ( $input['query'] ?? '' ) );
		$group = sanitize_key( (string) ( $input['group'] ?? '' ) );
		$items = array();
		foreach ( self::catalog() as $alias => $def ) {
			if ( '' !== $group && $def['group'] !== $group && ! str_contains( $alias, $group ) ) {
				continue;
			}
			$hay = strtolower( $alias . ' ' . $def['label'] . ' ' . $def['description'] . ' ' . $def['group'] );
			if ( '' !== $query && ! str_contains( $hay, $query ) ) {
				continue;
			}
			$items[] = array(
				'ability'     => $alias,
				'group'       => $def['group'],
				'label'       => $def['label'],
				'description' => $def['description'],
				'readonly'    => ! empty( $def['readonly'] ),
				'feature'     => $def['feature'],
				'operation'   => $def['operation'],
			);
		}
		return array(
			'tools'  => array( 'dev_discover', 'dev_inspect', 'dev_execute', 'dev_compare', 'dev_history' ),
			'groups' => array( 'plugin', 'theme', 'component', 'migration', 'validation', 'snapshot', 'settings', 'site' ),
			'items'  => $items,
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function snapshot_list( array $input ) {
		return Picot_Dev_Mcp_Snapshot::list_snapshots( (int) ( $input['limit'] ?? 50 ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function snapshot_detail( array $input ) {
		return Picot_Dev_Mcp_Snapshot::detail( (string) ( $input['snapshot_id'] ?? $input['id'] ?? '' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function snapshot_create( array $input ) {
		$area = ( isset( $input['area'] ) && 'plugin' === $input['area'] ) ? 'plugin' : 'theme';
		if ( 'plugin' === $area ) {
			$plugin = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $plugin ) ) {
				return $plugin;
			}
			$root   = $plugin['root'];
			$target = $plugin['slug'];
		} else {
			$theme  = Picot_Dev_Mcp_Paths::active_theme();
			$root   = $theme['root'];
			$target = $theme['stylesheet'];
		}
		$paths = array();
		if ( ! empty( $input['paths'] ) && is_array( $input['paths'] ) ) {
			$paths = array_map( 'strval', $input['paths'] );
		} else {
			$tree = Picot_Dev_Mcp_Files::list_tree( $root, 300 );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			foreach ( $tree as $item ) {
				if ( empty( $item['dir'] ) ) {
					$paths[] = (string) $item['path'];
				}
			}
		}
		return Picot_Dev_Mcp_Snapshot::create(
			array(
				'area'   => $area,
				'target' => $target,
				'files'  => $paths,
			)
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function snapshot_rollback( array $input ) {
		return Picot_Dev_Mcp_Snapshot::rollback( (string) ( $input['snapshot_id'] ?? $input['id'] ?? '' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_theme( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'theme', (array) ( $input['remote'] ?? array() ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_plugin( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'plugin', (array) ( $input['remote'] ?? array() ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_manifest( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'manifest', (array) ( $input['remote'] ?? array() ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_settings( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'settings', (array) ( $input['remote'] ?? $input ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_content( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'content', (array) ( $input['remote'] ?? $input ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function compare_environment( array $input ) {
		return Picot_Dev_Mcp_Site::compare( 'environment', (array) ( $input['remote'] ?? $input ) );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function migration_dry_run( array $input ) {
		$input['dry_run'] = true;
		return Picot_Dev_Mcp_Migration::import( $input );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function settings_import( array $input ) {
		if ( empty( $input['package'] ) || ! is_array( $input['package'] ) ) {
			$items = (array) ( $input['items'] ?? array() );
			if ( empty( $items ) && ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
				$items = $input['settings'];
			}
			$input['package'] = array(
				'type'     => 'settings',
				'source'   => (array) ( $input['source'] ?? array() ),
				'settings' => array(
					'items' => $items,
				),
			);
		}
		return Picot_Dev_Mcp_Migration::import( $input );
	}

	/**
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function validation_run( array $input ) {
		$target = sanitize_key( (string) ( $input['target'] ?? 'theme' ) );
		if ( 'plugin' === $target ) {
			return Picot_Dev_Mcp_Plugin_Dev::validate( $input );
		}
		return Picot_Dev_Mcp_Theme::validate( $input );
	}

	/**
	 * @param array $input Input.
	 * @return array<string, mixed>
	 */
	public static function validation_logs( array $input ) {
		return Picot_Dev_Mcp_Validation::recent_logs( (int) ( $input['lines'] ?? 80 ) );
	}
}
