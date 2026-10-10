<?php
/**
 * Path sandbox. Writes stay inside the active theme or the selected plugin.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Paths class.
 */
class Picot_Dev_Mcp_Paths {

	/**
	 * Basenames that are never readable or writable.
	 *
	 * @var string[]
	 */
	const PROTECTED_BASENAMES = array(
		'wp-config.php',
		'.env',
		'.env.local',
		'.env.production',
		'.env.development',
		'id_rsa',
		'id_ed25519',
		'auth.json',
		'credentials.json',
		'.htpasswd',
	);

	/**
	 * Plugin slugs this MCP must not modify.
	 *
	 * @return string[]
	 */
	public static function locked_plugin_slugs() {
		return array( 'picot-developer-mcp' );
	}

	/**
	 * Active theme facts, read from WordPress on every call.
	 *
	 * @return array<string, mixed>
	 */
	public static function active_theme() {
		$theme      = wp_get_theme();
		$stylesheet = (string) $theme->get_stylesheet();
		$template   = (string) $theme->get_template();
		$is_child   = $stylesheet !== $template;
		$parent     = $is_child ? wp_get_theme( $template ) : null;
		return array(
			'name'         => $theme->get( 'Name' ),
			'stylesheet'   => $stylesheet,
			'template'     => $template,
			'version'      => $theme->get( 'Version' ),
			'is_block'     => $theme->is_block_theme(),
			'is_child'     => $is_child,
			'root'         => wp_normalize_path( (string) $theme->get_stylesheet_directory() ),
			'parent_root'  => ( $parent && $parent->exists() ) ? wp_normalize_path( (string) $parent->get_stylesheet_directory() ) : '',
			'parent_name'  => ( $parent && $parent->exists() ) ? $parent->get( 'Name' ) : '',
			'theme_json'   => self::theme_json_version( $theme ),
			'writable'     => is_writable( $theme->get_stylesheet_directory() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);
	}

	/**
	 * theme.json version, or 0 when the file is absent.
	 *
	 * @param WP_Theme $theme Theme.
	 * @return int
	 */
	public static function theme_json_version( WP_Theme $theme ) {
		$file = trailingslashit( $theme->get_stylesheet_directory() ) . 'theme.json';
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return ( is_array( $data ) && isset( $data['version'] ) ) ? (int) $data['version'] : 0;
	}

	/**
	 * Selected development plugin, or WP_Error.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function development_plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slug = Picot_Dev_Mcp_Settings::instance()->plugin_slug();
		if ( '' === $slug ) {
			return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '開発対象プラグインが選択されていません。', 'picot-developer-mcp' ) );
		}
		if ( in_array( $slug, self::locked_plugin_slugs(), true ) ) {
			return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( 'Picot Developer MCP自身は開発対象にできません。', 'picot-developer-mcp' ) );
		}
		$plugins = get_plugins();
		$file    = '';
		foreach ( $plugins as $plugin_file => $data ) {
			if ( dirname( $plugin_file ) === $slug || $plugin_file === $slug . '.php' ) {
				$file = $plugin_file;
				break;
			}
		}
		$root = wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug );
		if ( '' === $file && ! is_dir( $root ) ) {
			return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '選択中のプラグインディレクトリが見つかりません。', 'picot-developer-mcp' ) );
		}
		if ( '' === $file ) {
			$candidates = glob( $root . '/*.php' );
			if ( is_array( $candidates ) ) {
				foreach ( $candidates as $candidate ) {
					$plugin_file = $slug . '/' . basename( $candidate );
					if ( isset( $plugins[ $plugin_file ] ) ) {
						$file = $plugin_file;
						break;
					}
				}
			}
		}
		$data = ( '' !== $file && isset( $plugins[ $file ] ) ) ? $plugins[ $file ] : array();
		return array(
			'slug'    => $slug,
			'file'    => $file,
			'name'    => (string) ( $data['Name'] ?? $slug ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'root'    => $root,
			'active'  => '' !== $file && is_plugin_active( $file ),
			'writable'=> is_dir( $root ) && is_writable( $root ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);
	}

	/**
	 * Resolve a theme-relative path.
	 *
	 * @param string $relative Relative path.
	 * @param string $mode     read|write.
	 * @param string $scope    active|parent.
	 * @return string|WP_Error Absolute path.
	 */
	public static function resolve_theme( $relative, $mode = 'read', $scope = 'active' ) {
		$theme = self::active_theme();
		if ( 'parent' === $scope ) {
			if ( empty( $theme['is_child'] ) || '' === $theme['parent_root'] ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '親テーマはありません。', 'picot-developer-mcp' ) );
			}
			if ( 'write' === $mode ) {
				return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( '親テーマは読み取り専用です。子テーマ側にOverrideを作成してください。', 'picot-developer-mcp' ) );
			}
			return self::resolve_inside( $theme['parent_root'], $relative, 'read' );
		}
		return self::resolve_inside( $theme['root'], $relative, $mode );
	}

	/**
	 * Resolve a path inside the selected plugin.
	 *
	 * @param string $relative Relative path.
	 * @param string $mode     read|write.
	 * @return string|WP_Error
	 */
	public static function resolve_plugin( $relative, $mode = 'read' ) {
		$plugin = self::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		return self::resolve_inside( $plugin['root'], $relative, $mode );
	}

	/**
	 * Read a WordPress core file. Writes are refused.
	 *
	 * @param string $relative Path relative to ABSPATH, or wp-includes/… / wp-admin/….
	 * @param string $mode     read|write.
	 * @return string|WP_Error
	 */
	public static function resolve_core( $relative, $mode = 'read' ) {
		if ( 'write' === $mode ) {
			return Picot_Dev_Mcp_Errors::make( 'core_write_forbidden', __( 'WordPressコアは変更できません。', 'picot-developer-mcp' ) );
		}
		$relative = self::normalize_relative( $relative );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		$allowed = false;
		foreach ( array( 'wp-includes/', 'wp-admin/' ) as $prefix ) {
			if ( str_starts_with( $relative, $prefix ) || $relative === rtrim( $prefix, '/' ) ) {
				$allowed = true;
			}
		}
		if ( ! $allowed ) {
			return Picot_Dev_Mcp_Errors::make( 'core_write_forbidden', __( 'コア参照は wp-includes と wp-admin のみです。', 'picot-developer-mcp' ) );
		}
		return self::resolve_inside( wp_normalize_path( ABSPATH ), $relative, 'read' );
	}

	/**
	 * Resolve a relative path and keep it inside a root.
	 *
	 * @param string $root     Absolute root.
	 * @param string $relative Relative path.
	 * @param string $mode     read|write.
	 * @return string|WP_Error
	 */
	public static function resolve_inside( $root, $relative, $mode ) {
		$relative = self::normalize_relative( $relative );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		if ( self::is_protected( $relative ) ) {
			return Picot_Dev_Mcp_Errors::make( 'protected_file', __( '保護されたファイルにはアクセスできません。', 'picot-developer-mcp' ) );
		}

		$real_root = realpath( $root );
		if ( false === $real_root ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '対象ディレクトリが見つかりません。', 'picot-developer-mcp' ) );
		}
		$real_root = wp_normalize_path( $real_root );
		$intended  = wp_normalize_path( $real_root . '/' . $relative );
		if ( $intended !== $real_root && ! str_starts_with( $intended, $real_root . '/' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '対象ディレクトリの外にはアクセスできません。', 'picot-developer-mcp' ) );
		}

		if ( file_exists( $intended ) ) {
			$real = realpath( $intended );
			if ( false === $real ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'パスを解決できません。', 'picot-developer-mcp' ) );
			}
			$real = wp_normalize_path( $real );
			if ( $real !== $real_root && ! str_starts_with( $real, $real_root . '/' ) ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '対象ディレクトリの外にはアクセスできません。', 'picot-developer-mcp' ) );
			}
			return $real;
		}

		if ( 'write' !== $mode ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ファイルが見つかりません。', 'picot-developer-mcp' ) );
		}

		$cursor = dirname( $intended );
		$guard  = 0;
		while ( ! file_exists( $cursor ) && $guard < 12 ) {
			$next = dirname( $cursor );
			if ( $next === $cursor ) {
				break;
			}
			$cursor = $next;
			++$guard;
		}
		$real_cursor = realpath( $cursor );
		if ( false === $real_cursor ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '作成先ディレクトリが見つかりません。', 'picot-developer-mcp' ) );
		}
		$real_cursor = wp_normalize_path( $real_cursor );
		if ( $real_cursor !== $real_root && ! str_starts_with( $real_cursor, $real_root . '/' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '対象ディレクトリの外にはアクセスできません。', 'picot-developer-mcp' ) );
		}
		return $intended;
	}

	/**
	 * Normalize a user-supplied relative path.
	 *
	 * @param string $relative Relative path.
	 * @return string|WP_Error
	 */
	public static function normalize_relative( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_contains( $relative, '..' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'パスが不正です。', 'picot-developer-mcp' ) );
		}
		if ( strlen( $relative ) > 240 ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'パスが長すぎます。', 'picot-developer-mcp' ) );
		}
		return $relative;
	}

	/**
	 * Whether a relative path points at a protected file.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public static function is_protected( $relative ) {
		$base = strtolower( basename( str_replace( '\\', '/', $relative ) ) );
		if ( in_array( $base, self::PROTECTED_BASENAMES, true ) ) {
			return true;
		}
		if ( str_ends_with( $base, '.pem' ) || str_ends_with( $base, '.key' ) ) {
			return true;
		}
		if ( str_contains( strtolower( $relative ), 'wp-config.php' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Relative path from an absolute file to a root.
	 *
	 * @param string $root Absolute root.
	 * @param string $path Absolute file.
	 * @return string
	 */
	public static function relative_from( $root, $path ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		$path = wp_normalize_path( $path );
		if ( str_starts_with( $path, $root ) ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}
		return basename( $path );
	}
}
