<?php
/**
 * Development-target plugin authoring.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Plugin_Dev class.
 */
class Picot_Dev_Mcp_Plugin_Dev {

	/**
	 * Component types v1 can scaffold.
	 *
	 * @return string[]
	 */
	public static function component_types() {
		return array(
			'settings-page',
			'rest-api',
			'custom-post-type',
			'custom-taxonomy',
			'gutenberg-block',
			'shortcode',
			'cron',
			'admin-page',
			'admin-table',
			'database-table',
			'cli-command',
		);
	}

	/**
	 * Inspect the selected plugin. Other plugins are metadata only.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function inspect( array $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}
			$installed[] = array(
				'slug'    => $slug,
				'file'    => $file,
				'name'    => (string) ( $data['Name'] ?? '' ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => is_plugin_active( $file ),
			);
		}
		$selected = Picot_Dev_Mcp_Paths::development_plugin();
		$payload  = array(
			'selected'  => is_wp_error( $selected ) ? null : self::public_plugin( $selected ),
			'installed' => $installed,
			'error'     => is_wp_error( $selected ) ? $selected->get_error_code() : '',
		);
		if ( ! empty( $input['slug'] ) ) {
			$slug = sanitize_key( (string) $input['slug'] );
			$current = is_wp_error( $selected ) ? '' : $selected['slug'];
			if ( $slug !== $current ) {
				return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '書き込みも詳細解析も、選択中の開発対象プラグインだけが対象です。', 'picot-developer-mcp' ), $payload );
			}
		}
		if ( is_wp_error( $selected ) ) {
			return $payload;
		}
		$payload['files'] = Picot_Dev_Mcp_Files::list_tree( $selected['root'], 400 );
		return $payload;
	}

	/**
	 * List files in the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function files_list( array $input ) {
		unset( $input );
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$items = Picot_Dev_Mcp_Files::list_tree( $plugin['root'] );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		return array(
			'slug'  => $plugin['slug'],
			'items' => $items,
		);
	}

	/**
	 * Read a file in the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_read( array $input ) {
		$path = Picot_Dev_Mcp_Paths::resolve_plugin( (string) ( $input['path'] ?? '' ), 'read' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$data = Picot_Dev_Mcp_Files::read( $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['path'] = (string) $input['path'];
		return $data;
	}

	/**
	 * Search the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function search( array $input ) {
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$extensions = array();
		if ( ! empty( $input['extensions'] ) && is_array( $input['extensions'] ) ) {
			$extensions = array_map( 'sanitize_key', $input['extensions'] );
		}
		$hits = Picot_Dev_Mcp_Files::search( $plugin['root'], (string) ( $input['query'] ?? '' ), $extensions );
		if ( is_wp_error( $hits ) ) {
			return $hits;
		}
		return array(
			'slug' => $plugin['slug'],
			'hits' => $hits,
		);
	}

	/**
	 * Create a new plugin and select it as the development target.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create( array $input ) {
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $name || '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,60}$/', $slug ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Plugin Name と Plugin Slug が必要です。Slugは英小文字・数字・ハイフンです。', 'picot-developer-mcp' ) );
		}
		if ( in_array( $slug, Picot_Dev_Mcp_Paths::locked_plugin_slugs(), true ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'このSlugは予約されています。', 'picot-developer-mcp' ) );
		}
		$root = wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug );
		if ( file_exists( $root ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '同じSlugのプラグインが既にあります。', 'picot-developer-mcp' ) );
		}

		$description = sanitize_text_field( (string) ( $input['description'] ?? '' ) );
		$text_domain = sanitize_title( (string) ( $input['text_domain'] ?? $slug ) );
		$namespace   = self::sanitize_namespace( (string) ( $input['namespace'] ?? '' ), $slug );
		$const       = strtoupper( str_replace( '-', '_', $slug ) );

		$files = array(
			$slug . '.php'                 => self::main_plugin_php( $name, $description, $text_domain, $namespace, $const ),
			'uninstall.php'                => "<?php\n/**\n * Uninstall.\n *\n * @package " . $namespace . "\n */\n\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}\n",
			'readme.txt'                   => "== " . $name . " ==\n\n" . $description . "\n\n== Description ==\n\n" . $description . "\n",
			'includes/components-loader.php' => "<?php\n/**\n * Load generated components.\n *\n * @package " . $namespace . "\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n\$picot_dev_component_dir = __DIR__ . '/components';\nif ( is_dir( \$picot_dev_component_dir ) ) {\n\tforeach ( glob( \$picot_dev_component_dir . '/*.php' ) as \$picot_dev_component_file ) {\n\t\tif ( is_file( \$picot_dev_component_file ) ) {\n\t\t\trequire_once \$picot_dev_component_file;\n\t\t}\n\t}\n}\n",
			'includes/index.php'           => "<?php\n// Silence is golden.\n",
			'includes/components/index.php'=> "<?php\n// Silence is golden.\n",
			'admin/index.php'              => "<?php\n// Silence is golden.\n",
			'public/index.php'             => "<?php\n// Silence is golden.\n",
			'assets/index.php'             => "<?php\n// Silence is golden.\n",
			'languages/index.php'          => "<?php\n// Silence is golden.\n",
		);

		if ( ! wp_mkdir_p( $root ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'プラグインディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}

		$previous = Picot_Dev_Mcp_Settings::instance()->plugin_slug();
		Picot_Dev_Mcp_Settings::instance()->update( array( 'plugin_slug' => $slug ) );

		$changes = array();
		foreach ( $files as $path => $content ) {
			$changes[] = array(
				'op'      => 'write',
				'path'    => $path,
				'content' => $content,
			);
		}
		$result = Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'plugin',
				'action'  => 'Create plugin',
				'input'   => $input,
				'changes' => $changes,
			)
		);
		if ( is_wp_error( $result ) ) {
			Picot_Dev_Mcp_Settings::instance()->update( array( 'plugin_slug' => $previous ) );
			return $result;
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		$result['slug']      = $slug;
		$result['namespace'] = $namespace;
		$result['selected']  = true;
		return $result;
	}

	/**
	 * Create a file in the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_create( array $input ) {
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'plugin',
				'action'  => 'Create file',
				'input'   => $input,
				'changes' => array(
					array(
						'op'        => 'write',
						'path'      => (string) ( $input['path'] ?? '' ),
						'content'   => (string) ( $input['content'] ?? '' ),
						'overwrite' => ! empty( $input['overwrite'] ),
					),
				),
			)
		);
	}

	/**
	 * Patch a file in the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_patch( array $input ) {
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'plugin',
				'action'  => 'Patch file',
				'input'   => $input,
				'changes' => array(
					array(
						'op'      => 'patch',
						'path'    => (string) ( $input['path'] ?? '' ),
						'search'  => (string) ( $input['search'] ?? '' ),
						'replace' => (string) ( $input['replace'] ?? '' ),
						'all'     => ! empty( $input['all'] ),
					),
				),
			)
		);
	}

	/**
	 * Delete a file in the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_delete( array $input ) {
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'plugin',
				'action'  => 'Delete file',
				'input'   => $input,
				'changes' => array(
					array(
						'op'   => 'delete',
						'path' => (string) ( $input['path'] ?? '' ),
					),
				),
			)
		);
	}

	/**
	 * Add a component scaffold.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function component_create( array $input ) {
		$type = sanitize_title( (string) ( $input['type'] ?? '' ) );
		if ( ! in_array( $type, self::component_types(), true ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '未知のPlugin Componentです。', 'picot-developer-mcp' ), array( 'types' => self::component_types() ) );
		}
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$slug = sanitize_title( (string) ( $input['slug'] ?? $type ) );
		if ( '' === $slug ) {
			$slug = $type;
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? $slug ) );
		$namespace = self::detect_namespace( $plugin['root'], $plugin['slug'] );
		$file = 'includes/components/class-' . $slug . '.php';
		if ( 'gutenberg-block' === $type ) {
			$file = 'includes/components/class-' . $slug . '-block.php';
		}
		$content = self::component_php( $type, $slug, $title, $namespace, $plugin['slug'] );
		$changes = array(
			array(
				'op'      => 'write',
				'path'    => $file,
				'content' => $content,
			),
		);
		if ( 'gutenberg-block' === $type ) {
			$changes[] = array(
				'op'      => 'write',
				'path'    => 'includes/components/' . $slug . '-block.json',
				'content' => (string) wp_json_encode(
					array(
						'apiVersion' => 3,
						'name'       => $plugin['slug'] . '/' . $slug,
						'title'      => $title,
						'category'   => 'widgets',
						'icon'       => 'star-filled',
						'textdomain' => $plugin['slug'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				) . "\n",
			);
		}
		$result = Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'plugin',
				'action'  => 'Create component ' . $type,
				'input'   => $input,
				'changes' => $changes,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['component'] = array(
			'type' => $type,
			'slug' => $slug,
			'file' => $file,
		);
		return $result;
	}

	/**
	 * Update a component file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function component_update( array $input ) {
		if ( isset( $input['search'] ) ) {
			return self::file_patch( $input );
		}
		$input['overwrite'] = true;
		return self::file_create( $input );
	}

	/**
	 * Diff a plugin file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function diff( array $input ) {
		return Picot_Dev_Mcp_Theme::diff_file( 'plugin', $input );
	}

	/**
	 * Validate the selected plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function validate( array $input ) {
		unset( $input );
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$files = array();
		if ( '' !== $plugin['file'] ) {
			$files[] = WP_PLUGIN_DIR . '/' . $plugin['file'];
		}
		$tree = Picot_Dev_Mcp_Files::list_tree( $plugin['root'], 200 );
		if ( is_array( $tree ) ) {
			foreach ( $tree as $item ) {
				if ( ! empty( $item['dir'] ) ) {
					continue;
				}
				$ext = strtolower( pathinfo( (string) $item['path'], PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'php', 'json' ), true ) ) {
					$files[] = $plugin['root'] . '/' . $item['path'];
				}
			}
		}
		$files  = array_values( array_unique( $files ) );
		$result = Picot_Dev_Mcp_Validation::run( $files, Picot_Dev_Mcp_Validation::log_offset(), 'plugin' );
		$result['plugin'] = self::public_plugin( $plugin );
		return $result;
	}

	/**
	 * Zip the selected plugin and return a one-time transfer handle. Bytes stay out of the model context.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function package( array $input ) {
		unset( $input );
		if ( ! class_exists( 'ZipArchive' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'ZipArchiveが利用できません。', 'picot-developer-mcp' ) );
		}
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$dir = Picot_Dev_Mcp_Storage::dir( 'packages' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$zip_path = $dir . '/' . $plugin['slug'] . '-' . wp_date( 'Ymd-His' ) . '.zip';
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'パッケージを作成できません。', 'picot-developer-mcp' ) );
		}
		$tree = Picot_Dev_Mcp_Files::list_tree( $plugin['root'], 2000 );
		if ( is_wp_error( $tree ) ) {
			$zip->close();
			return $tree;
		}
		foreach ( $tree as $item ) {
			if ( ! empty( $item['dir'] ) || Picot_Dev_Mcp_Paths::is_protected( (string) $item['path'] ) ) {
				continue;
			}
			$absolute = $plugin['root'] . '/' . $item['path'];
			if ( is_file( $absolute ) ) {
				$zip->addFile( $absolute, $plugin['slug'] . '/' . $item['path'] );
			}
		}
		$zip->close();
		$token = Picot_Dev_Mcp_Transfer::issue( $zip_path, 'package:' . $plugin['slug'] );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return array(
			'slug'     => $plugin['slug'],
			'bytes'    => (int) filesize( $zip_path ),
			'sha256'   => hash_file( 'sha256', $zip_path ),
			'transfer' => $token,
			'manifest' => Picot_Dev_Mcp_Manifest::build( $plugin['root'] ),
		);
	}

	/**
	 * Plugin row safe for clients.
	 *
	 * @param array $plugin Plugin info.
	 * @return array<string, mixed>
	 */
	private static function public_plugin( array $plugin ) {
		return array(
			'slug'     => $plugin['slug'],
			'file'     => $plugin['file'],
			'name'     => $plugin['name'],
			'version'  => $plugin['version'],
			'active'   => $plugin['active'],
			'writable' => $plugin['writable'],
		);
	}

	/**
	 * Main plugin file.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @param string $text_domain Text domain.
	 * @param string $namespace   PHP namespace.
	 * @param string $const       Constant prefix.
	 * @return string
	 */
	private static function main_plugin_php( $name, $description, $text_domain, $namespace, $const ) {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Description: {$description}\n * Version: 0.1.0\n * Requires at least: 6.9\n * Requires PHP: 8.3\n * Text Domain: {$text_domain}\n * Domain Path: /languages\n *\n * @package {$namespace}\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\ndefine( '{$const}_VERSION', '0.1.0' );\ndefine( '{$const}_FILE', __FILE__ );\ndefine( '{$const}_PATH', plugin_dir_path( __FILE__ ) );\n\nrequire_once {$const}_PATH . 'includes/components-loader.php';\n";
	}

	/**
	 * PHP namespace from input, or a slug-based fallback.
	 *
	 * @param string $namespace Raw namespace.
	 * @param string $slug      Slug.
	 * @return string
	 */
	public static function sanitize_namespace( $namespace, $slug ) {
		$namespace = trim( str_replace( '/', '\\', $namespace ), '\\' );
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $namespace ) ) {
			return $namespace;
		}
		$parts = array_map(
			static function ( $part ) {
				$part = preg_replace( '/[^A-Za-z0-9]/', '', $part );
				return ucfirst( (string) $part );
			},
			explode( '-', $slug )
		);
		$built = implode( '_', array_filter( $parts ) );
		return '' !== $built ? $built : 'Plugin';
	}

	/**
	 * Detect an existing namespace.
	 *
	 * @param string $root Root.
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function detect_namespace( $root, $slug ) {
		$files = glob( $root . '/*.php' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( preg_match( '/^\s*namespace\s+([^;]+);/m', $src, $matches ) ) {
					return self::sanitize_namespace( trim( $matches[1] ), $slug );
				}
			}
		}
		return self::sanitize_namespace( '', $slug );
	}

	/**
	 * Component PHP source.
	 *
	 * @param string $type      Type.
	 * @param string $slug      Slug.
	 * @param string $title     Title.
	 * @param string $namespace Namespace.
	 * @param string $domain    Text domain / plugin slug.
	 * @return string
	 */
	private static function component_php( $type, $slug, $title, $namespace, $domain ) {
		$class = self::sanitize_namespace( '', $slug ) . '_' . self::sanitize_namespace( '', $type );
		$hook  = str_replace( '-', '_', $domain . '_' . $slug );
		$header = "<?php\n/**\n * {$title}\n *\n * @package {$namespace}\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n";
		switch ( $type ) {
			case 'settings-page':
			case 'admin-page':
				return $header . "add_action( 'admin_menu', '{$hook}_admin_menu' );\n\n/**\n * Register the admin page.\n *\n * @return void\n */\nfunction {$hook}_admin_menu() {\n\tadd_options_page(\n\t\t" . var_export( $title, true ) . ",\n\t\t" . var_export( $title, true ) . ",\n\t\t'manage_options',\n\t\t" . var_export( $domain . '-' . $slug, true ) . ",\n\t\t'{$hook}_render_page'\n\t);\n}\n\n/**\n * Render the page.\n *\n * @return void\n */\nfunction {$hook}_render_page() {\n\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\treturn;\n\t}\n\techo '<div class=\"wrap\"><h1>' . esc_html( " . var_export( $title, true ) . " ) . '</h1></div>';\n}\n";
			case 'rest-api':
				return $header . "add_action( 'rest_api_init', '{$hook}_register_rest' );\n\n/**\n * Register a read-only REST route.\n *\n * @return void\n */\nfunction {$hook}_register_rest() {\n\tregister_rest_route(\n\t\t" . var_export( $domain . '/v1', true ) . ",\n\t\t" . var_export( '/' . $slug, true ) . ",\n\t\tarray(\n\t\t\t'methods'             => 'GET',\n\t\t\t'callback'            => '{$hook}_rest_callback',\n\t\t\t'permission_callback' => static function () {\n\t\t\t\treturn current_user_can( 'manage_options' );\n\t\t\t},\n\t\t)\n\t);\n}\n\n/**\n * REST callback.\n *\n * @return array\n */\nfunction {$hook}_rest_callback() {\n\treturn array(\n\t\t'ok'   => true,\n\t\t'slug' => " . var_export( $slug, true ) . ",\n\t);\n}\n";
			case 'custom-post-type':
				return $header . "add_action( 'init', '{$hook}_register_cpt' );\n\n/**\n * Register the post type.\n *\n * @return void\n */\nfunction {$hook}_register_cpt() {\n\tregister_post_type(\n\t\t" . var_export( str_replace( '-', '_', $slug ), true ) . ",\n\t\tarray(\n\t\t\t'labels'       => array( 'name' => " . var_export( $title, true ) . " ),\n\t\t\t'public'       => true,\n\t\t\t'show_in_rest' => true,\n\t\t\t'supports'     => array( 'title', 'editor', 'thumbnail' ),\n\t\t\t'has_archive'  => true,\n\t\t)\n\t);\n}\n";
			case 'custom-taxonomy':
				return $header . "add_action( 'init', '{$hook}_register_taxonomy' );\n\n/**\n * Register the taxonomy.\n *\n * @return void\n */\nfunction {$hook}_register_taxonomy() {\n\tregister_taxonomy(\n\t\t" . var_export( str_replace( '-', '_', $slug ), true ) . ",\n\t\tarray( 'post' ),\n\t\tarray(\n\t\t\t'labels'       => array( 'name' => " . var_export( $title, true ) . " ),\n\t\t\t'public'       => true,\n\t\t\t'show_in_rest' => true,\n\t\t\t'hierarchical' => true,\n\t\t)\n\t);\n}\n";
			case 'gutenberg-block':
				return $header . "add_action( 'init', '{$hook}_register_block' );\n\n/**\n * Register the block from block.json.\n *\n * @return void\n */\nfunction {$hook}_register_block() {\n\t\$json = __DIR__ . " . var_export( '/' . $slug . '-block.json', true ) . ";\n\tif ( file_exists( \$json ) && function_exists( 'register_block_type' ) ) {\n\t\tregister_block_type( \$json );\n\t}\n}\n";
			case 'shortcode':
				$class_name = var_export( $slug, true );
				return $header . "add_shortcode( " . var_export( $slug, true ) . ", '{$hook}_shortcode' );\n\n/**\n * Shortcode callback.\n *\n * @param array|string \$atts Attributes.\n * @return string\n */\nfunction {$hook}_shortcode( \$atts ) {\n\t\$atts = shortcode_atts( array( 'title' => " . var_export( $title, true ) . " ), \$atts, " . var_export( $slug, true ) . " );\n\treturn '<div class=\"' . esc_attr( {$class_name} ) . '\">' . esc_html( \$atts['title'] ) . '</div>';\n}\n";
			case 'cron':
				return $header . "add_action( 'init', '{$hook}_schedule' );\nadd_action( '{$hook}_event', '{$hook}_run' );\n\n/**\n * Schedule a daily event.\n *\n * @return void\n */\nfunction {$hook}_schedule() {\n\tif ( ! wp_next_scheduled( '{$hook}_event' ) ) {\n\t\twp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', '{$hook}_event' );\n\t}\n}\n\n/**\n * Cron callback.\n *\n * @return void\n */\nfunction {$hook}_run() {\n\tdo_action( '{$hook}_cron_run' );\n}\n";
			case 'admin-table':
				return $header . "add_action( 'admin_menu', '{$hook}_table_menu' );\n\n/**\n * Admin table screen.\n *\n * @return void\n */\nfunction {$hook}_table_menu() {\n\tadd_management_page( " . var_export( $title, true ) . ", " . var_export( $title, true ) . ", 'manage_options', " . var_export( $domain . '-' . $slug, true ) . ", '{$hook}_render_table' );\n}\n\n/**\n * Render a read-only table shell.\n *\n * @return void\n */\nfunction {$hook}_render_table() {\n\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\treturn;\n\t}\n\techo '<div class=\"wrap\"><h1>' . esc_html( " . var_export( $title, true ) . " ) . '</h1><table class=\"widefat striped\"><thead><tr><th>' . esc_html__( 'Name', " . var_export( $domain, true ) . " ) . '</th></tr></thead><tbody><tr><td>' . esc_html( " . var_export( $title, true ) . " ) . '</td></tr></tbody></table></div>';\n}\n";
			case 'database-table':
				return $header . "add_action( 'admin_init', '{$hook}_install_table' );\n\n/**\n * Create a custom table with dbDelta. Does not run arbitrary SQL from input.\n *\n * @return void\n */\nfunction {$hook}_install_table() {\n\t\$flag = '{$hook}_table_version';\n\tif ( '1' === get_option( \$flag ) ) {\n\t\treturn;\n\t}\n\tglobal \$wpdb;\n\trequire_once ABSPATH . 'wp-admin/includes/upgrade.php';\n\t\$table = \$wpdb->prefix . " . var_export( str_replace( '-', '_', $slug ), true ) . ";\n\t\$sql   = \"CREATE TABLE {\$table} (\n\t\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\t\ttitle text NOT NULL,\n\t\tcreated_at datetime NOT NULL,\n\t\tPRIMARY KEY  (id)\n\t) {\$wpdb->get_charset_collate()};\";\n\tdbDelta( \$sql );\n\tupdate_option( \$flag, '1', false );\n}\n";
			case 'cli-command':
				return $header . "if ( defined( 'WP_CLI' ) && WP_CLI ) {\n\tWP_CLI::add_command( " . var_export( $domain . ' ' . $slug, true ) . ", '{$hook}_cli' );\n}\n\n/**\n * WP-CLI command.\n *\n * @return void\n */\nfunction {$hook}_cli() {\n\tif ( class_exists( 'WP_CLI' ) ) {\n\t\tWP_CLI::success( " . var_export( $title, true ) . " );\n\t}\n}\n";
			default:
				return $header . "// {$class}\n";
		}
	}
}
