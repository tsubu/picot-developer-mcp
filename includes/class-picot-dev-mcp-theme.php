<?php
/**
 * Active-theme inspection and component authoring.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Theme class.
 */
class Picot_Dev_Mcp_Theme {

	/**
	 * Standard block template slugs that do not need customTemplates.
	 *
	 * @var string[]
	 */
	const CORE_TEMPLATES = array(
		'index', 'home', 'front-page', 'singular', 'single', 'page', 'archive', 'search', '404',
		'attachment', 'author', 'category', 'tag', 'taxonomy', 'date', 'home',
	);

	/**
	 * Inspect the active theme. A requested slug that is not active is rejected.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function inspect( array $input ) {
		$theme = Picot_Dev_Mcp_Paths::active_theme();
		if ( ! empty( $input['stylesheet'] ) && sanitize_key( (string) $input['stylesheet'] ) !== $theme['stylesheet'] ) {
			return Picot_Dev_Mcp_Errors::make( 'theme_not_active', __( '指定されたテーマは現在有効ではありません。テーマ名の指定はできません。', 'picot-developer-mcp' ) );
		}
		$summary = $theme;
		unset( $summary['root'], $summary['parent_root'] );
		$summary['type'] = self::type_label( $theme );
		$summary['components'] = self::component_index( $theme );
		return $summary;
	}

	/**
	 * List files in the active theme or, read-only, the parent.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function files_list( array $input ) {
		$scope = ( isset( $input['scope'] ) && 'parent' === $input['scope'] ) ? 'parent' : 'active';
		$root  = self::root( $scope, 'read' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$items = Picot_Dev_Mcp_Files::list_tree( $root );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		return array(
			'scope' => $scope,
			'items' => $items,
		);
	}

	/**
	 * Read one theme file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_read( array $input ) {
		$scope = ( isset( $input['scope'] ) && 'parent' === $input['scope'] ) ? 'parent' : 'active';
		if ( isset( $input['scope'] ) && 'core' === $input['scope'] ) {
			$path = Picot_Dev_Mcp_Paths::resolve_core( (string) ( $input['path'] ?? '' ), 'read' );
		} else {
			$path = Picot_Dev_Mcp_Paths::resolve_theme( (string) ( $input['path'] ?? '' ), 'read', $scope );
		}
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$data = Picot_Dev_Mcp_Files::read( $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['path'] = (string) ( $input['path'] ?? '' );
		return $data;
	}

	/**
	 * Search PHP/JSON in the active theme, parent theme, or core.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function search( array $input ) {
		$scope = sanitize_key( (string) ( $input['scope'] ?? 'active' ) );
		if ( 'core' === $scope ) {
			$root = wp_normalize_path( ABSPATH . 'wp-includes' );
		} elseif ( 'parent' === $scope ) {
			$root = self::root( 'parent', 'read' );
		} else {
			$root = self::root( 'active', 'read' );
		}
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$extensions = array();
		if ( ! empty( $input['extensions'] ) && is_array( $input['extensions'] ) ) {
			$extensions = array_map( 'sanitize_key', $input['extensions'] );
		}
		$hits = Picot_Dev_Mcp_Files::search( $root, (string) ( $input['query'] ?? '' ), $extensions );
		if ( is_wp_error( $hits ) ) {
			return $hits;
		}
		return array(
			'scope' => $scope ? $scope : 'active',
			'hits'  => $hits,
		);
	}

	/**
	 * Create a file in the active theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_create( array $input ) {
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'theme',
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
	 * Patch a file in the active theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function file_patch( array $input ) {
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'theme',
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
	 * List theme components.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function component_list( array $input ) {
		unset( $input );
		$theme = Picot_Dev_Mcp_Paths::active_theme();
		return array(
			'stylesheet' => $theme['stylesheet'],
			'type'       => self::type_label( $theme ),
			'items'      => self::component_index( $theme ),
		);
	}

	/**
	 * Inspect one component file.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function component_inspect( array $input ) {
		return self::file_read( $input );
	}

	/**
	 * Create a template, template part, pattern, style variation, or asset.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function component_create( array $input ) {
		$type  = sanitize_key( (string) ( $input['type'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $input['title'] ?? $slug ) );
		if ( '' === $slug ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'slug が必要です。', 'picot-developer-mcp' ) );
		}
		$theme   = Picot_Dev_Mcp_Paths::active_theme();
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$changes = array();

		if ( 'template' === $type ) {
			if ( $theme['is_block'] ) {
				$path = 'templates/' . $slug . '.html';
				$body = '' !== $content ? $content : "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:post-content /--></div>\n<!-- /wp:group -->\n";
				$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => $body );
				if ( ! in_array( $slug, self::CORE_TEMPLATES, true ) ) {
					$json = self::theme_json_with_custom_template( $slug, $title );
					if ( ! is_wp_error( $json ) ) {
						$changes[] = $json;
					}
				}
			} else {
				$path = $slug . '.php';
				$body = '' !== $content ? $content : "<?php\n/**\n * Template Name: " . $title . "\n *\n * @package " . $theme['stylesheet'] . "\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\nget_header();\n?>\n<main id=\"primary\" class=\"site-main\">\n\t<?php\n\twhile ( have_posts() ) :\n\t\tthe_post();\n\t\tthe_content();\n\tendwhile;\n\t?>\n</main>\n<?php\nget_footer();\n";
				$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => $body );
			}
		} elseif ( 'template-part' === $type ) {
			$area = sanitize_key( (string) ( $input['area'] ?? 'uncategorized' ) );
			if ( ! in_array( $area, array( 'header', 'footer', 'uncategorized' ), true ) ) {
				$area = 'uncategorized';
			}
			if ( $theme['is_block'] ) {
				$path = 'parts/' . $slug . '.html';
				$body = '' !== $content ? $content : "<!-- wp:group {\"tagName\":\"div\"} -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->\n";
				$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => $body );
				$json = self::theme_json_with_template_part( $slug, $title, $area );
				if ( is_wp_error( $json ) ) {
					return $json;
				}
				$changes[] = $json;
			} else {
				$path = 'template-parts/' . $slug . '.php';
				$body = '' !== $content ? $content : "<?php\n/**\n * Template part: " . $title . "\n *\n * @package " . $theme['stylesheet'] . "\n */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n";
				$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => $body );
			}
		} elseif ( 'pattern' === $type ) {
			$path = 'patterns/' . $slug . '.php';
			$block = '' !== $content ? $content : "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading --><h2 class=\"wp-block-heading\">" . esc_html( $title ) . "</h2><!-- /wp:heading --></div>\n<!-- /wp:group -->";
			$body  = "<?php\n/**\n * Title: " . $title . "\n * Slug: " . $theme['stylesheet'] . '/' . $slug . "\n * Categories: featured\n * Description: " . sanitize_text_field( (string) ( $input['description'] ?? $title ) ) . "\n *\n * @package " . $theme['stylesheet'] . "\n */\n?>\n" . $block . "\n";
			$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => $body );
		} elseif ( 'style-variation' === $type || 'style' === $type ) {
			$path = 'styles/' . $slug . '.json';
			if ( '' === $content ) {
				$body = wp_json_encode(
					array(
						'$schema' => 'https://schemas.wp.org/trunk/theme.json',
						'version' => $theme['theme_json'] > 0 ? (int) $theme['theme_json'] : 3,
						'title'   => $title,
						'styles'  => array(
							'color' => array(
								'background' => '#111111',
								'text'       => '#f5f5f5',
							),
						),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
			} else {
				json_decode( $content, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return Picot_Dev_Mcp_Errors::make( 'validation_failed', __( 'Style VariationのJSONが不正です。', 'picot-developer-mcp' ) );
				}
				$body = $content;
			}
			$changes[] = array( 'op' => 'write', 'path' => $path, 'content' => (string) $body );
		} elseif ( 'asset' === $type ) {
			$asset_path = Picot_Dev_Mcp_Paths::normalize_relative( (string) ( $input['path'] ?? '' ) );
			if ( is_wp_error( $asset_path ) ) {
				return $asset_path;
			}
			if ( ! str_starts_with( $asset_path, 'assets/' ) ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Assetは assets/ 以下に作成してください。', 'picot-developer-mcp' ) );
			}
			$changes[] = array( 'op' => 'write', 'path' => $asset_path, 'content' => $content );
		} else {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '未知のコンポーネント種別です。', 'picot-developer-mcp' ) );
		}

		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'theme',
				'action'  => 'Create ' . $type,
				'input'   => $input,
				'changes' => $changes,
			)
		);
	}

	/**
	 * Update a component with a patch or full contents.
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
	 * Delete a component file and its theme.json entry.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function component_delete( array $input ) {
		$path = (string) ( $input['path'] ?? '' );
		$changes = array(
			array(
				'op'   => 'delete',
				'path' => $path,
			),
		);
		$slug = sanitize_title( pathinfo( $path, PATHINFO_FILENAME ) );
		if ( str_starts_with( $path, 'parts/' ) ) {
			$json = self::theme_json_without( 'templateParts', $slug );
			if ( is_array( $json ) ) {
				$changes[] = $json;
			}
		}
		return Picot_Dev_Mcp_Change::apply(
			array(
				'area'    => 'theme',
				'action'  => 'Delete component',
				'input'   => $input,
				'changes' => $changes,
			)
		);
	}

	/**
	 * Diff a theme file against its latest snapshot or an inline before string.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function diff( array $input ) {
		return self::diff_file( 'theme', $input );
	}

	/**
	 * Shared file diff.
	 *
	 * @param string $area  theme|plugin.
	 * @param array  $input Input.
	 * @return array|WP_Error
	 */
	public static function diff_file( $area, array $input ) {
		$path = (string) ( $input['path'] ?? '' );
		$absolute = 'theme' === $area
			? Picot_Dev_Mcp_Paths::resolve_theme( $path, 'read', 'active' )
			: Picot_Dev_Mcp_Paths::resolve_plugin( $path, 'read' );
		$after = '';
		if ( ! is_wp_error( $absolute ) && is_file( $absolute ) ) {
			$after = (string) file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} elseif ( is_wp_error( $absolute ) && empty( $input['after'] ) ) {
			return $absolute;
		}
		if ( isset( $input['after'] ) ) {
			$after = (string) $input['after'];
		}
		$before = isset( $input['before'] ) ? (string) $input['before'] : self::before_from_snapshot( $area, $path, (string) ( $input['snapshot_id'] ?? '' ) );
		return Picot_Dev_Mcp_Diff::unified( $before, $after, $path );
	}

	/**
	 * Validate the active theme.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function validate( array $input ) {
		unset( $input );
		$theme = Picot_Dev_Mcp_Paths::active_theme();
		$files = array( $theme['root'] . '/style.css' );
		$json  = $theme['root'] . '/theme.json';
		if ( is_file( $json ) ) {
			$files[] = $json;
		}
		$functions = $theme['root'] . '/functions.php';
		if ( is_file( $functions ) ) {
			$files[] = $functions;
		}
		$result = Picot_Dev_Mcp_Validation::run( $files, Picot_Dev_Mcp_Validation::log_offset(), 'theme' );
		$result['theme'] = array(
			'stylesheet' => $theme['stylesheet'],
			'type'       => self::type_label( $theme ),
		);
		return $result;
	}

	/**
	 * Load the previous file body from a snapshot.
	 *
	 * @param string $area        theme|plugin.
	 * @param string $path        Relative path.
	 * @param string $snapshot_id Optional id. Latest match is used when empty.
	 * @return string
	 */
	private static function before_from_snapshot( $area, $path, $snapshot_id ) {
		if ( '' !== $snapshot_id ) {
			$detail = Picot_Dev_Mcp_Snapshot::detail( $snapshot_id );
			return self::extract_snapshot_file( $detail, $path );
		}
		$list = Picot_Dev_Mcp_Snapshot::list_snapshots( 30 );
		foreach ( (array) ( $list['items'] ?? array() ) as $item ) {
			if ( ( $item['area'] ?? '' ) !== $area ) {
				continue;
			}
			$detail = Picot_Dev_Mcp_Snapshot::detail( (string) $item['id'] );
			$body   = self::extract_snapshot_file( $detail, $path );
			if ( '' !== $body ) {
				return $body;
			}
		}
		return '';
	}

	/**
	 * Read one file out of a snapshot manifest.
	 *
	 * @param mixed  $detail Manifest.
	 * @param string $path   Relative path.
	 * @return string
	 */
	private static function extract_snapshot_file( $detail, $path ) {
		if ( is_wp_error( $detail ) || ! is_array( $detail ) ) {
			return '';
		}
		foreach ( (array) ( $detail['files'] ?? array() ) as $file ) {
			if ( ( $file['path'] ?? '' ) !== $path || empty( $file['stored'] ) || empty( $detail['id'] ) ) {
				continue;
			}
			$dir = Picot_Dev_Mcp_Storage::dir( 'snapshots' );
			if ( is_wp_error( $dir ) ) {
				return '';
			}
			$stored = $dir . '/' . $detail['id'] . '/' . $file['stored'];
			if ( ! is_readable( $stored ) ) {
				return '';
			}
			return (string) file_get_contents( $stored ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return '';
	}

	/**
	 * Theme type label.
	 *
	 * @param array $theme Theme info.
	 * @return string
	 */
	public static function type_label( array $theme ) {
		if ( ! empty( $theme['is_child'] ) ) {
			return 'child';
		}
		return ! empty( $theme['is_block'] ) ? 'block' : 'classic';
	}

	/**
	 * Component file index.
	 *
	 * @param array $theme Theme info.
	 * @return array<string, string[]>
	 */
	private static function component_index( array $theme ) {
		$root = (string) $theme['root'];
		return array(
			'templates' => self::glob_relative( $root, ! empty( $theme['is_block'] ) ? 'templates/*.html' : '*.php' ),
			'parts'     => self::glob_relative( $root, ! empty( $theme['is_block'] ) ? 'parts/*.html' : 'template-parts/*.php' ),
			'patterns'  => self::glob_relative( $root, 'patterns/*.php' ),
			'styles'    => self::glob_relative( $root, 'styles/*.json' ),
		);
	}

	/**
	 * Glob files as theme-relative paths.
	 *
	 * @param string $root    Theme root.
	 * @param string $pattern Pattern relative to the theme.
	 * @return string[]
	 */
	private static function glob_relative( $root, $pattern ) {
		$matches = glob( trailingslashit( $root ) . $pattern );
		if ( ! is_array( $matches ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches as $match ) {
			$out[] = Picot_Dev_Mcp_Paths::relative_from( $root, $match );
		}
		sort( $out );
		return $out;
	}

	/**
	 * Root for a scope.
	 *
	 * @param string $scope active|parent.
	 * @param string $mode  read|write.
	 * @return string|WP_Error
	 */
	private static function root( $scope, $mode ) {
		$probe = Picot_Dev_Mcp_Paths::resolve_theme( 'style.css', 'read', $scope );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		return wp_normalize_path( dirname( $probe ) );
	}

	/**
	 * theme.json change that adds a template part entry.
	 *
	 * @param string $slug  Slug.
	 * @param string $title Title.
	 * @param string $area  Area.
	 * @return array|WP_Error Change row.
	 */
	private static function theme_json_with_template_part( $slug, $title, $area ) {
		$data = self::read_theme_json();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! isset( $data['templateParts'] ) || ! is_array( $data['templateParts'] ) ) {
			$data['templateParts'] = array();
		}
		foreach ( $data['templateParts'] as $part ) {
			if ( is_array( $part ) && ( $part['name'] ?? '' ) === $slug ) {
				return array(
					'op'        => 'write',
					'path'      => 'theme.json',
					'content'   => self::encode_theme_json( $data ),
					'overwrite' => true,
				);
			}
		}
		$data['templateParts'][] = array(
			'name'  => $slug,
			'title' => $title,
			'area'  => $area,
		);
		return array(
			'op'        => 'write',
			'path'      => 'theme.json',
			'content'   => self::encode_theme_json( $data ),
			'overwrite' => true,
		);
	}

	/**
	 * theme.json change that adds a custom template entry.
	 *
	 * @param string $slug  Slug.
	 * @param string $title Title.
	 * @return array|WP_Error
	 */
	private static function theme_json_with_custom_template( $slug, $title ) {
		$data = self::read_theme_json();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! isset( $data['customTemplates'] ) || ! is_array( $data['customTemplates'] ) ) {
			$data['customTemplates'] = array();
		}
		foreach ( $data['customTemplates'] as $template ) {
			if ( is_array( $template ) && ( $template['name'] ?? '' ) === $slug ) {
				return array(
					'op'        => 'write',
					'path'      => 'theme.json',
					'content'   => self::encode_theme_json( $data ),
					'overwrite' => true,
				);
			}
		}
		$data['customTemplates'][] = array(
			'name'  => $slug,
			'title' => $title,
		);
		return array(
			'op'        => 'write',
			'path'      => 'theme.json',
			'content'   => self::encode_theme_json( $data ),
			'overwrite' => true,
		);
	}

	/**
	 * Remove a named entry from a theme.json array.
	 *
	 * @param string $key  templateParts|customTemplates.
	 * @param string $slug Name.
	 * @return array|null Change row, or null when theme.json is absent.
	 */
	private static function theme_json_without( $key, $slug ) {
		$file = Picot_Dev_Mcp_Paths::active_theme()['root'] . '/theme.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = self::read_theme_json();
		if ( is_wp_error( $data ) || empty( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return null;
		}
		$data[ $key ] = array_values(
			array_filter(
				$data[ $key ],
				static function ( $item ) use ( $slug ) {
					return ! is_array( $item ) || ( $item['name'] ?? '' ) !== $slug;
				}
			)
		);
		return array(
			'op'        => 'write',
			'path'      => 'theme.json',
			'content'   => self::encode_theme_json( $data ),
			'overwrite' => true,
		);
	}

	/**
	 * Read theme.json or start a version 3 document.
	 *
	 * @return array|WP_Error
	 */
	private static function read_theme_json() {
		$file = Picot_Dev_Mcp_Paths::active_theme()['root'] . '/theme.json';
		if ( ! is_file( $file ) ) {
			return array(
				'$schema' => 'https://schemas.wp.org/trunk/theme.json',
				'version' => 3,
			);
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return Picot_Dev_Mcp_Errors::make( 'validation_failed', __( 'theme.json を解釈できません。', 'picot-developer-mcp' ) );
		}
		return $data;
	}

	/**
	 * Encode theme.json.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function encode_theme_json( array $data ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
}
