<?php
/**
 * Site inspection, safe settings, and local-vs-remote comparison.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Site class.
 */
class Picot_Dev_Mcp_Site {

	/**
	 * Core options that may be compared and migrated.
	 *
	 * @return string[]
	 */
	public static function safe_options() {
		$options = array(
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
			'posts_per_page',
			'posts_per_rss',
			'rss_use_excerpt',
			'permalink_structure',
			'blog_public',
			'default_comment_status',
			'default_ping_status',
			'comment_moderation',
			'show_on_front',
			'blogname',
			'blogdescription',
			'WPLANG',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
			'thumbnail_crop',
		);
		/**
		 * Filter options treated as safe to compare and migrate.
		 *
		 * @param string[] $options Option names.
		 */
		return apply_filters( 'picot_dev_mcp_safe_options', $options );
	}

	/**
	 * Options that are visible in compare but not imported unless explicitly forced.
	 *
	 * @return string[]
	 */
	public static function restricted_options() {
		return array(
			'users_can_register',
			'default_role',
			'admin_email',
			'page_on_front',
			'page_for_posts',
		);
	}

	/**
	 * Whether an option must never be migrated.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_forbidden_option( $name ) {
		$name = (string) $name;
		$exact = array(
			'siteurl',
			'home',
			'blog_charset',
			'db_version',
			'initial_db_version',
			'cron',
			'rewrite_rules',
			'active_plugins',
			'uninstall_plugins',
			'recently_edited',
			'auto_updater.lock',
			'recovery_keys',
			'auth_key',
			'auth_salt',
			'template',
			'stylesheet',
		);
		if ( in_array( $name, $exact, true ) ) {
			return true;
		}
		if ( str_starts_with( $name, '_transient' ) || str_starts_with( $name, '_site_transient' ) ) {
			return true;
		}
		if ( str_starts_with( $name, 'picot_dev_mcp' ) || str_starts_with( $name, 'picot_mcp' ) ) {
			return true;
		}
		if ( preg_match( '/(password|passwd|secret|api[_-]?key|salt|token|credential|nonce)/i', $name ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Classify an option.
	 *
	 * @param string $name Option name.
	 * @return string safe|restricted|forbidden|unknown
	 */
	public static function classify_option( $name ) {
		if ( self::is_forbidden_option( $name ) ) {
			return 'forbidden';
		}
		if ( in_array( $name, self::safe_options(), true ) ) {
			return 'safe';
		}
		if ( in_array( $name, self::restricted_options(), true ) ) {
			return 'restricted';
		}
		return 'unknown';
	}

	/**
	 * Environment and target summary.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function inspect( array $input ) {
		unset( $input );
		$theme  = Picot_Dev_Mcp_Paths::active_theme();
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		$uploads = wp_upload_dir( null, false );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = array(
					'file'    => $file,
					'name'    => (string) ( $data['Name'] ?? '' ),
					'version' => (string) ( $data['Version'] ?? '' ),
				);
			}
		}
		unset( $theme['root'], $theme['parent_root'] );
		return array(
			'home_url'     => home_url( '/' ),
			'site_url'     => site_url( '/' ),
			'fingerprint'  => Picot_Dev_Mcp_Settings::instance()->fingerprint(),
			'environment'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			'wordpress'    => get_bloginfo( 'version' ),
			'php'          => PHP_VERSION,
			'language'     => get_locale(),
			'timezone'     => wp_timezone_string(),
			'permalink'    => (string) get_option( 'permalink_structure' ),
			'uploads'      => array(
				'baseurl' => (string) ( $uploads['baseurl'] ?? '' ),
				'basedir_writable' => ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			),
			'upload_max'   => ini_get( 'upload_max_filesize' ),
			'theme'        => $theme,
			'theme_type'   => Picot_Dev_Mcp_Theme::type_label( $theme ),
			'plugin'       => is_wp_error( $plugin ) ? null : array(
				'slug'    => $plugin['slug'],
				'name'    => $plugin['name'],
				'version' => $plugin['version'],
				'active'  => $plugin['active'],
			),
			'active_plugins' => $active,
			'features'     => array(
				'block_theme' => ! empty( $theme['is_block'] ),
				'https'       => is_ssl(),
			),
		);
	}

	/**
	 * Read classified settings.
	 *
	 * @param array $input Input. names[] optional.
	 * @return array
	 */
	public static function settings_inspect( array $input ) {
		$names = array();
		if ( ! empty( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_map( 'strval', $input['names'] );
		} else {
			$names = array_merge( self::safe_options(), self::restricted_options() );
		}
		$items = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			$class = self::classify_option( $name );
			if ( 'forbidden' === $class ) {
				$items[] = array(
					'name'  => $name,
					'class' => 'forbidden',
				);
				continue;
			}
			$items[] = array(
				'name'  => $name,
				'class' => $class,
				'value' => get_option( $name, null ),
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * Export safe settings for migration.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function settings_export( array $input ) {
		$include_unknown = ! empty( $input['include_unknown'] );
		$names = array();
		if ( ! empty( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_map( 'strval', $input['names'] );
		} else {
			$names = self::safe_options();
		}
		$items = array();
		foreach ( $names as $name ) {
			$class = self::classify_option( $name );
			if ( 'forbidden' === $class ) {
				continue;
			}
			if ( 'unknown' === $class && ! $include_unknown ) {
				continue;
			}
			if ( 'restricted' === $class && empty( $input['include_restricted'] ) ) {
				continue;
			}
			$items[] = array(
				'name'  => $name,
				'class' => $class,
				'value' => get_option( $name, null ),
			);
		}
		return array(
			'home_url' => home_url( '/' ),
			'items'    => $items,
		);
	}

	/**
	 * Compare local state to a remote payload collected from the other site.
	 *
	 * Status is from this site's point of view:
	 * new = remote only, missing = local only, modified = both but different, same = equal hash or value.
	 *
	 * @param string $subject theme|plugin|manifest|settings|content|environment.
	 * @param array  $remote  Remote payload.
	 * @return array|WP_Error
	 */
	public static function compare( $subject, array $remote ) {
		$subject = sanitize_key( $subject );
		if ( 'environment' === $subject ) {
			$local = self::inspect( array() );
			$keys  = array( 'wordpress', 'php', 'language', 'timezone', 'permalink', 'environment' );
			$rows  = array();
			foreach ( $keys as $key ) {
				$rows[] = array(
					'key'    => $key,
					'local'  => $local[ $key ] ?? null,
					'remote' => $remote[ $key ] ?? null,
					'status' => (string) ( $local[ $key ] ?? '' ) === (string) ( $remote[ $key ] ?? '' ) ? 'same' : 'modified',
				);
			}
			$local_theme  = $local['theme']['stylesheet'] ?? '';
			$remote_theme = $remote['theme']['stylesheet'] ?? ( $remote['stylesheet'] ?? '' );
			$rows[] = array(
				'key'     => 'theme',
				'local'   => $local_theme,
				'remote'  => $remote_theme,
				'status'  => $local_theme === $remote_theme ? 'same' : 'modified',
				'warning' => $local_theme !== $remote_theme ? 'theme_compatibility' : '',
			);
			return array( 'subject' => 'environment', 'rows' => $rows );
		}

		if ( 'settings' === $subject ) {
			$local = self::settings_inspect( array( 'names' => array_column( (array) ( $remote['items'] ?? array() ), 'name' ) ) );
			if ( empty( $remote['items'] ) ) {
				$local = self::settings_inspect( array() );
			}
			$remote_map = array();
			foreach ( (array) ( $remote['items'] ?? array() ) as $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) ) {
					$remote_map[ (string) $item['name'] ] = $item['value'] ?? null;
				}
			}
			$rows = array();
			foreach ( $local['items'] as $item ) {
				$name = (string) $item['name'];
				if ( 'forbidden' === ( $item['class'] ?? '' ) ) {
					continue;
				}
				$has_remote = array_key_exists( $name, $remote_map );
				$status = 'missing';
				if ( $has_remote ) {
					$status = wp_json_encode( $item['value'] ?? null ) === wp_json_encode( $remote_map[ $name ] ) ? 'same' : 'modified';
				}
				$rows[] = array(
					'name'   => $name,
					'class'  => $item['class'] ?? 'unknown',
					'local'  => $item['value'] ?? null,
					'remote' => $has_remote ? $remote_map[ $name ] : null,
					'status' => $status,
				);
			}
			foreach ( $remote_map as $name => $value ) {
				$known = false;
				foreach ( $rows as $row ) {
					if ( $row['name'] === $name ) {
						$known = true;
					}
				}
				if ( ! $known && ! self::is_forbidden_option( $name ) ) {
					$rows[] = array(
						'name'   => $name,
						'class'  => self::classify_option( $name ),
						'local'  => null,
						'remote' => $value,
						'status' => 'new',
					);
				}
			}
			return array( 'subject' => 'settings', 'rows' => $rows );
		}

		if ( 'manifest' === $subject || 'theme' === $subject || 'plugin' === $subject ) {
			$local_files = array();
			if ( 'plugin' === $subject ) {
				$plugin = Picot_Dev_Mcp_Paths::development_plugin();
				if ( is_wp_error( $plugin ) ) {
					return $plugin;
				}
				$local_files = Picot_Dev_Mcp_Manifest::build( $plugin['root'] );
			} elseif ( 'theme' === $subject ) {
				$theme = Picot_Dev_Mcp_Paths::active_theme();
				$local_files = Picot_Dev_Mcp_Manifest::build( $theme['root'] );
			} else {
				$local_files = (array) ( $remote['local_files'] ?? array() );
				if ( empty( $local_files ) ) {
					$theme = Picot_Dev_Mcp_Paths::active_theme();
					$local_files = Picot_Dev_Mcp_Manifest::build( $theme['root'] );
				}
			}
			$remote_files = (array) ( $remote['files'] ?? $remote['manifest'] ?? array() );
			return array(
				'subject' => $subject,
				'files'   => Picot_Dev_Mcp_Manifest::compare( $local_files, $remote_files ),
				'theme_warning' => self::theme_mismatch_warning( $remote ),
			);
		}

		if ( 'content' === $subject ) {
			return array(
				'subject' => 'content',
				'posts'   => self::compare_posts( (array) ( $remote['posts'] ?? array() ) ),
			);
		}

		return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '比較対象が不正です。', 'picot-developer-mcp' ) );
	}

	/**
	 * Warn when the remote theme slug differs from the active theme.
	 *
	 * @param array $remote Remote payload.
	 * @return string
	 */
	public static function theme_mismatch_warning( array $remote ) {
		$remote_slug = (string) ( $remote['stylesheet'] ?? ( $remote['theme']['stylesheet'] ?? '' ) );
		if ( '' === $remote_slug ) {
			return '';
		}
		$local = Picot_Dev_Mcp_Paths::active_theme();
		if ( $local['stylesheet'] !== $remote_slug ) {
			return 'theme_compatibility';
		}
		return '';
	}

	/**
	 * Compare posts by slug and content hash.
	 *
	 * @param array $remote_posts Remote posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function compare_posts( array $remote_posts ) {
		$rows = array();
		$seen = array();
		foreach ( $remote_posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$slug = (string) ( $post['slug'] ?? '' );
			$type = (string) ( $post['type'] ?? 'post' );
			if ( '' === $slug ) {
				continue;
			}
			$seen[ $type . ':' . $slug ] = true;
			$local = get_page_by_path( $slug, OBJECT, $type );
			$remote_hash = (string) ( $post['hash'] ?? hash( 'sha256', (string) ( $post['title'] ?? '' ) . (string) ( $post['content'] ?? '' ) ) );
			if ( ! $local instanceof WP_Post ) {
				$rows[] = array( 'type' => $type, 'slug' => $slug, 'status' => 'new' );
				continue;
			}
			$local_hash = hash( 'sha256', $local->post_title . $local->post_content );
			$rows[] = array(
				'type'   => $type,
				'slug'   => $slug,
				'status' => hash_equals( $local_hash, $remote_hash ) ? 'same' : 'modified',
			);
		}
		return $rows;
	}
}
