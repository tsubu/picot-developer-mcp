<?php
/**
 * Migration sessions: export, dry-run, import, rollback.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Migration class.
 */
class Picot_Dev_Mcp_Migration {

	const INDEX_OPTION = 'picot_dev_mcp_migration_index';

	/**
	 * Export a package. Binary files are transfer handles, not bytes.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function export( array $input ) {
		$type = sanitize_key( (string) ( $input['type'] ?? 'bundle' ) );
		$package = array(
			'type'   => $type,
			'source' => array(
				'home_url'        => home_url( '/' ),
				'uploads_baseurl' => (string) ( wp_upload_dir( null, false )['baseurl'] ?? '' ),
				'stylesheet'      => get_stylesheet(),
				'theme_type'      => Picot_Dev_Mcp_Theme::type_label( Picot_Dev_Mcp_Paths::active_theme() ),
				'theme_json'      => (int) Picot_Dev_Mcp_Paths::active_theme()['theme_json'],
				'fingerprint'     => Picot_Dev_Mcp_Settings::instance()->fingerprint(),
				'plugin_slug'     => Picot_Dev_Mcp_Settings::instance()->plugin_slug(),
			),
			'files'    => array(),
			'posts'    => array(),
			'terms'    => array(),
			'media'    => array(),
			'settings' => array(),
		);

		if ( in_array( $type, array( 'theme', 'theme_components', 'bundle' ), true ) ) {
			$paths = array();
			if ( ! empty( $input['paths'] ) && is_array( $input['paths'] ) ) {
				$paths = array_map( 'strval', $input['paths'] );
			} else {
				$index = Picot_Dev_Mcp_Theme::component_list( array() );
				foreach ( (array) ( $index['items'] ?? array() ) as $group ) {
					foreach ( (array) $group as $path ) {
						$paths[] = $path;
					}
				}
			}
			$package['files'] = self::export_files( 'theme', $paths );
			$package['manifest'] = Picot_Dev_Mcp_Manifest::build( Picot_Dev_Mcp_Paths::active_theme()['root'] );
		}

		if ( in_array( $type, array( 'plugin', 'bundle' ), true ) ) {
			$plugin = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $plugin ) ) {
				return $plugin;
			}
			$paths = array();
			if ( ! empty( $input['paths'] ) && is_array( $input['paths'] ) && 'plugin' === $type ) {
				$paths = array_map( 'strval', $input['paths'] );
			} else {
				$tree = Picot_Dev_Mcp_Files::list_tree( $plugin['root'], 500 );
				if ( is_wp_error( $tree ) ) {
					return $tree;
				}
				foreach ( $tree as $item ) {
					if ( empty( $item['dir'] ) ) {
						$paths[] = (string) $item['path'];
					}
				}
			}
			$package['files'] = array_merge( $package['files'], self::export_files( 'plugin', $paths ) );
			$package['manifest'] = Picot_Dev_Mcp_Manifest::build( $plugin['root'] );
			$package['source']['plugin_slug'] = $plugin['slug'];
		}

		if ( in_array( $type, array( 'posts', 'pages', 'content', 'bundle' ), true ) ) {
			if ( 'posts' === $type ) {
				$input['post_types'] = array( 'post' );
			} elseif ( 'pages' === $type ) {
				$input['post_types'] = array( 'page' );
			}
			$content = Picot_Dev_Mcp_Content::export_posts( $input );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$package['posts'] = $content['posts'];
			$package['terms'] = $content['terms'];
			$package['media'] = $content['media'];
		}

		if ( in_array( $type, array( 'settings', 'bundle' ), true ) ) {
			$package['settings'] = Picot_Dev_Mcp_Site::settings_export( $input );
		}

		if ( 'terms' === $type ) {
			$package['terms'] = self::export_all_terms();
		}

		return $package;
	}

	/**
	 * Import a package. Dry run is the default.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function import( array $input ) {
		$package = isset( $input['package'] ) && is_array( $input['package'] ) ? $input['package'] : array();
		if ( empty( $package ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'package が必要です。', 'picot-developer-mcp' ) );
		}
		$package = self::normalize_package( $package );
		$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
		if ( ! $dry_run && Picot_Dev_Mcp_Settings::instance()->is_safety_enabled( 'dry_run_before_migration' ) && empty( $input['confirm_apply'] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( '本適用には confirm_apply: true が必要です。先に dry_run の結果を確認してください。', 'picot-developer-mcp' ) );
		}
		$policy = sanitize_key( (string) ( $input['conflict'] ?? 'ask' ) );
		if ( ! in_array( $policy, array( 'skip', 'overwrite', 'rename', 'merge', 'ask' ), true ) ) {
			$policy = 'ask';
		}

		$plan = self::plan( $package, $policy, $input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( $dry_run ) {
			$plan['dry_run'] = true;
			$plan['id'] = '';
			return $plan;
		}

		$gate = Picot_Dev_Mcp_Permissions::authorize_production_write( $input, false );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( ! empty( $plan['blocked'] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_conflict', __( '互換性の警告があるため移植を中止しました。', 'picot-developer-mcp' ), $plan );
		}

		return self::apply_plan( $package, $plan, $input );
	}

	/**
	 * List migration sessions.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_sessions( array $input ) {
		unset( $input );
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		return array( 'items' => array_reverse( $index ) );
	}

	/**
	 * Session detail.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function detail( array $input ) {
		return self::read_session( (string) ( $input['migration_id'] ?? $input['id'] ?? '' ) );
	}

	/**
	 * Roll back a migration session.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function rollback( array $input ) {
		$session = self::read_session( (string) ( $input['migration_id'] ?? $input['id'] ?? '' ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$removed_posts = array();
		foreach ( (array) ( $session['created_posts'] ?? array() ) as $post_id ) {
			$post_id = (int) $post_id;
			if ( (string) get_post_meta( $post_id, '_picot_dev_mcp_migration', true ) !== (string) $session['id'] ) {
				continue;
			}
			wp_delete_post( $post_id, true );
			$removed_posts[] = $post_id;
		}
		$removed_media = array();
		foreach ( (array) ( $session['created_media'] ?? array() ) as $media_id ) {
			$media_id = (int) $media_id;
			if ( (string) get_post_meta( $media_id, '_picot_dev_mcp_migration', true ) !== (string) $session['id'] ) {
				continue;
			}
			wp_delete_attachment( $media_id, true );
			$removed_media[] = $media_id;
		}
		$removed_terms = array();
		foreach ( (array) ( $session['created_terms'] ?? array() ) as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}
			$object = get_term( (int) ( $term['id'] ?? 0 ), (string) ( $term['taxonomy'] ?? '' ) );
			if ( $object instanceof WP_Term && 0 === (int) $object->count ) {
				$deleted = wp_delete_term( (int) $object->term_id, $object->taxonomy );
				if ( ! is_wp_error( $deleted ) ) {
					$removed_terms[] = (int) $object->term_id;
				}
			}
		}
		if ( ! empty( $session['snapshot_id'] ) ) {
			$rolled = Picot_Dev_Mcp_Snapshot::rollback( (string) $session['snapshot_id'] );
			if ( is_wp_error( $rolled ) ) {
				return $rolled;
			}
		}
		foreach ( (array) ( $session['options_before'] ?? array() ) as $name => $value ) {
			if ( Picot_Dev_Mcp_Site::is_forbidden_option( (string) $name ) ) {
				continue;
			}
			if ( null === $value ) {
				delete_option( (string) $name );
			} else {
				update_option( (string) $name, $value, true );
			}
		}
		Picot_Dev_Mcp_Audit::history(
			array(
				'type'     => 'migration',
				'target'   => (string) $session['id'],
				'action'   => 'Rollback migration',
				'result'   => 'success',
				'snapshot' => (string) ( $session['snapshot_id'] ?? '' ),
			)
		);
		return array(
			'id'            => $session['id'],
			'removed_posts' => $removed_posts,
			'removed_media' => $removed_media,
			'removed_terms' => $removed_terms,
			'snapshot_id'   => (string) ( $session['snapshot_id'] ?? '' ),
		);
	}

	/**
	 * Build a plan without writing.
	 *
	 * @param array $package Package.
	 * @param string $policy Conflict policy.
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function plan( array $package, $policy, array $input ) {
		$plan = array(
			'dry_run'   => true,
			'policy'    => $policy,
			'files'     => array( 'new' => array(), 'modified' => array(), 'skip' => array(), 'delete' => array() ),
			'media'     => array( 'new' => 0, 'existing' => 0, 'conflict' => array() ),
			'posts'     => array( 'create' => array(), 'update' => array(), 'skip' => array() ),
			'terms'     => array( 'create' => array(), 'existing' => array() ),
			'settings'  => array( 'change' => array(), 'skip' => array(), 'unknown' => array() ),
			'conflicts' => array(),
			'warnings'  => array(),
			'blocked'   => false,
		);

		$warning = Picot_Dev_Mcp_Site::theme_mismatch_warning( (array) ( $package['source'] ?? array() ) );
		if ( '' !== $warning && ! empty( $package['files'] ) && self::package_has_theme_files( $package ) ) {
			$plan['warnings'][] = 'theme_compatibility';
			if ( empty( $input['acknowledge_theme_mismatch'] ) ) {
				$plan['blocked'] = true;
			}
		}

		$area = self::file_area( $package );
		if ( 'plugin' === $area ) {
			$plugin = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $plugin ) ) {
				return $plugin;
			}
			$source_slug = (string) ( $package['source']['plugin_slug'] ?? '' );
			if ( '' !== $source_slug && $source_slug !== $plugin['slug'] ) {
				return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '移植先の開発対象プラグインがパッケージと一致しません。', 'picot-developer-mcp' ) );
			}
			$root = $plugin['root'];
		} else {
			$root = Picot_Dev_Mcp_Paths::active_theme()['root'];
		}

		if ( ! empty( $package['files'] ) && is_array( $package['files'] ) ) {
			foreach ( $package['files'] as $file ) {
				if ( ! is_array( $file ) || empty( $file['path'] ) ) {
					continue;
				}
				$relative = (string) $file['path'];
				$file_area = ( isset( $file['area'] ) && 'plugin' === $file['area'] ) ? 'plugin' : 'theme';
				if ( 'plugin' === $file_area ) {
					$plugin = Picot_Dev_Mcp_Paths::development_plugin();
					if ( is_wp_error( $plugin ) ) {
						return $plugin;
					}
					$source_slug = (string) ( $package['source']['plugin_slug'] ?? '' );
					if ( '' !== $source_slug && $source_slug !== $plugin['slug'] ) {
						return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '移植先の開発対象プラグインがパッケージと一致しません。', 'picot-developer-mcp' ) );
					}
				}
				$absolute = 'plugin' === $file_area
					? Picot_Dev_Mcp_Paths::resolve_plugin( $relative, 'write' )
					: Picot_Dev_Mcp_Paths::resolve_theme( $relative, 'write', 'active' );
				if ( is_wp_error( $absolute ) ) {
					$plan['conflicts'][] = array( 'path' => $relative, 'area' => $file_area, 'code' => $absolute->get_error_code() );
					continue;
				}
				$ref = array( 'area' => $file_area, 'path' => $relative );
				if ( ! is_file( $absolute ) ) {
					$plan['files']['new'][] = $ref;
					continue;
				}
				$local_hash = hash_file( 'sha256', $absolute );
				$remote_hash = (string) ( $file['sha256'] ?? '' );
				if ( '' !== $remote_hash && hash_equals( $local_hash, $remote_hash ) ) {
					$plan['files']['skip'][] = $ref;
					continue;
				}
				if ( 'ask' === $policy || 'skip' === $policy ) {
					$plan['files']['skip'][] = $ref;
					$plan['conflicts'][] = array( 'path' => $relative, 'area' => $file_area, 'status' => 'modified', 'policy' => $policy );
					continue;
				}
				$plan['files']['modified'][] = $ref;
			}
		}

		foreach ( (array) ( $package['media'] ?? array() ) as $media ) {
			if ( ! is_array( $media ) ) {
				continue;
			}
			$found = Picot_Dev_Mcp_Content::find_media( (string) ( $media['sha256'] ?? '' ), (string) ( $media['filename'] ?? '' ) );
			if ( $found > 0 ) {
				++$plan['media']['existing'];
			} elseif ( -1 === $found ) {
				$plan['media']['conflict'][] = (string) ( $media['filename'] ?? '' );
				$plan['conflicts'][] = array( 'filename' => $media['filename'] ?? '', 'status' => 'name_conflict' );
			} else {
				++$plan['media']['new'];
			}
		}

		foreach ( (array) ( $package['terms'] ?? array() ) as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}
			$existing = get_term_by( 'slug', (string) ( $term['slug'] ?? '' ), (string) ( $term['taxonomy'] ?? 'category' ) );
			if ( $existing instanceof WP_Term ) {
				$plan['terms']['existing'][] = $term['slug'];
			} else {
				$plan['terms']['create'][] = $term['slug'];
			}
		}

		foreach ( (array) ( $package['posts'] ?? array() ) as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$existing = get_page_by_path( (string) ( $post['slug'] ?? '' ), OBJECT, (string) ( $post['type'] ?? 'post' ) );
			if ( ! $existing instanceof WP_Post ) {
				$plan['posts']['create'][] = $post['slug'];
				continue;
			}
			if ( in_array( $policy, array( 'overwrite', 'merge' ), true ) ) {
				$plan['posts']['update'][] = $post['slug'];
			} elseif ( 'rename' === $policy ) {
				$plan['posts']['create'][] = $post['slug'] . '-migrated';
			} else {
				$plan['posts']['skip'][] = $post['slug'];
				$plan['conflicts'][] = array( 'slug' => $post['slug'], 'status' => 'exists' );
			}
		}

		foreach ( (array) ( $package['settings']['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$name = (string) $item['name'];
			$class = Picot_Dev_Mcp_Site::classify_option( $name );
			if ( 'forbidden' === $class ) {
				$plan['settings']['skip'][] = $name;
				continue;
			}
			if ( 'unknown' === $class ) {
				$plan['settings']['unknown'][] = $name;
				$plan['warnings'][] = 'unknown_setting:' . $name;
				if ( empty( $input['include_unknown'] ) ) {
					continue;
				}
			}
			if ( 'restricted' === $class && empty( $input['include_restricted'] ) ) {
				$plan['settings']['skip'][] = $name;
				continue;
			}
			$current = get_option( $name, null );
			if ( wp_json_encode( $current ) !== wp_json_encode( $item['value'] ?? null ) ) {
				$plan['settings']['change'][] = $name;
			}
		}

		$plan['counts'] = array(
			'files_new'      => count( $plan['files']['new'] ),
			'files_modified' => count( $plan['files']['modified'] ),
			'files_delete'   => 0,
			'media_new'      => $plan['media']['new'],
			'media_existing' => $plan['media']['existing'],
			'posts_create'   => count( $plan['posts']['create'] ),
			'posts_update'   => count( $plan['posts']['update'] ),
			'settings_change'=> count( $plan['settings']['change'] ),
			'conflicts'      => count( $plan['conflicts'] ),
		);
		return $plan;
	}

	/**
	 * Apply a reviewed plan.
	 *
	 * @param array $package Package.
	 * @param array $plan    Plan.
	 * @param array $input   Input.
	 * @return array|WP_Error
	 */
	private static function apply_plan( array $package, array $plan, array $input ) {
		$id = Picot_Dev_Mcp_Storage::next_id( 'MIG' );
		$session = array(
			'id'             => $id,
			'created_at'     => wp_date( 'c' ),
			'source'         => $package['source'] ?? array(),
			'created_posts'  => array(),
			'created_media'  => array(),
			'created_terms'  => array(),
			'mappings'       => array( 'posts' => array(), 'media' => array(), 'terms' => array() ),
			'warnings'       => $plan['warnings'],
			'errors'         => array(),
			'snapshot_id'    => '',
			'options_before' => array(),
		);

		$target = get_stylesheet();

		$source_home = (string) ( $package['source']['home_url'] ?? '' );
		$source_uploads = (string) ( $package['source']['uploads_baseurl'] ?? '' );
		$peer = (string) ( $input['peer'] ?? ( $package['source']['home_url'] ?? '' ) );

		foreach ( (array) ( $package['media'] ?? array() ) as $media ) {
			if ( ! is_array( $media ) ) {
				continue;
			}
			$imported = self::import_media( $media, $plan, $peer, $id );
			if ( is_wp_error( $imported ) ) {
				$session['errors'][] = $imported->get_error_code();
				continue;
			}
			if ( ! empty( $imported['id'] ) ) {
				$session['mappings']['media'][ (int) $media['source_id'] ] = (int) $imported['id'];
				if ( ! empty( $imported['created'] ) ) {
					$session['created_media'][] = (int) $imported['id'];
				}
			}
		}

		foreach ( (array) ( $package['terms'] ?? array() ) as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}
			$taxonomy = sanitize_key( (string) ( $term['taxonomy'] ?? '' ) );
			if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				continue;
			}
			$existing = get_term_by( 'slug', (string) $term['slug'], $taxonomy );
			if ( $existing instanceof WP_Term ) {
				$session['mappings']['terms'][ (int) ( $term['source_id'] ?? 0 ) ] = (int) $existing->term_id;
				continue;
			}
			$parent = 0;
			if ( ! empty( $term['parent_slug'] ) ) {
				$parent_term = get_term_by( 'slug', (string) $term['parent_slug'], $taxonomy );
				if ( $parent_term instanceof WP_Term ) {
					$parent = (int) $parent_term->term_id;
				}
			}
			$created = wp_insert_term(
				(string) ( $term['name'] ?? $term['slug'] ),
				$taxonomy,
				array(
					'slug'        => (string) $term['slug'],
					'description' => (string) ( $term['description'] ?? '' ),
					'parent'      => $parent,
				)
			);
			if ( is_wp_error( $created ) ) {
				$session['errors'][] = $created->get_error_code();
				continue;
			}
			$session['created_terms'][] = array( 'id' => (int) $created['term_id'], 'taxonomy' => $taxonomy );
			$session['mappings']['terms'][ (int) ( $term['source_id'] ?? 0 ) ] = (int) $created['term_id'];
		}

		$policy = (string) $plan['policy'];
		foreach ( (array) ( $package['posts'] ?? array() ) as $post ) {
			if ( ! is_array( $post ) || ! in_array( (string) ( $post['type'] ?? '' ), array( 'post', 'page' ), true ) ) {
				continue;
			}
			$imported = self::import_post( $post, $policy, $session, $source_uploads, $source_home );
			if ( is_wp_error( $imported ) ) {
				$session['errors'][] = $imported->get_error_message();
			}
		}

		$file_result = self::import_files( $package, $plan, $peer );
		if ( is_wp_error( $file_result ) ) {
			self::write_session( $session );
			return $file_result;
		}
		if ( ! empty( $file_result['snapshot_id'] ) ) {
			$session['snapshot_id'] = $file_result['snapshot_id'];
		}

		foreach ( (array) ( $package['settings']['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = (string) ( $item['name'] ?? '' );
			if ( ! in_array( $name, $plan['settings']['change'], true ) ) {
				continue;
			}
			if ( ! array_key_exists( $name, $session['options_before'] ) ) {
				$session['options_before'][ $name ] = get_option( $name, null );
			}
			update_option( $name, $item['value'], true );
		}
		if ( in_array( 'permalink_structure', $plan['settings']['change'], true ) ) {
			flush_rewrite_rules( false );
		}

		$validation = is_array( $file_result ) && isset( $file_result['validation'] ) ? $file_result['validation'] : array( 'ok' => true );

		$session['completed_at'] = wp_date( 'c' );
		$session['validation'] = $validation;
		self::write_session( $session );
		Picot_Dev_Mcp_Audit::history(
			array(
				'type'     => 'migration',
				'target'   => $target,
				'action'   => 'Import ' . (string) ( $package['type'] ?? 'bundle' ),
				'result'   => empty( $session['errors'] ) ? 'success' : 'warning',
				'snapshot' => $session['snapshot_id'],
			)
		);
		return array(
			'id'         => $id,
			'dry_run'    => false,
			'mappings'   => $session['mappings'],
			'warnings'   => $session['warnings'],
			'errors'     => $session['errors'],
			'snapshot'   => $session['snapshot_id'],
			'validation' => $validation,
			'counts'     => $plan['counts'],
		);
	}

	/**
	 * Import one media item.
	 *
	 * @param array  $media Media descriptor.
	 * @param array  $plan  Plan.
	 * @param string $peer  Peer id or URL.
	 * @param string $migration_id Session id.
	 * @return array|WP_Error
	 */
	private static function import_media( array $media, array $plan, $peer, $migration_id ) {
		$hash = (string) ( $media['sha256'] ?? '' );
		$name = (string) ( $media['filename'] ?? 'file' );
		$found = Picot_Dev_Mcp_Content::find_media( $hash, $name );
		if ( $found > 0 ) {
			return array( 'id' => $found, 'created' => false );
		}
		if ( -1 === $found && in_array( (string) $plan['policy'], array( 'ask', 'skip' ), true ) ) {
			return array( 'id' => 0, 'created' => false, 'conflict' => true );
		}
		if ( empty( $media['transfer'] ) || ! is_array( $media['transfer'] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'メディア転送情報がありません。', 'picot-developer-mcp' ) );
		}
		$tmp = Picot_Dev_Mcp_Transfer::pull(
			$peer,
			(string) ( $media['transfer']['token'] ?? '' ),
			(string) ( $media['transfer']['path'] ?? '' ),
			$hash
		);
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			(string) ( $media['title'] ?? $name )
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		wp_update_post(
			array(
				'ID'           => (int) $attachment_id,
				'post_excerpt' => (string) ( $media['caption'] ?? '' ),
				'post_content' => (string) ( $media['description'] ?? '' ),
			)
		);
		if ( ! empty( $media['alt'] ) ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $media['alt'] ) );
		}
		update_post_meta( (int) $attachment_id, '_picot_dev_mcp_sha256', $hash );
		update_post_meta( (int) $attachment_id, '_picot_dev_mcp_migration', $migration_id );
		return array( 'id' => (int) $attachment_id, 'created' => true );
	}

	/**
	 * Import one post.
	 *
	 * @param array  $post Post row.
	 * @param string $policy Policy.
	 * @param array  $session Session, passed by reference.
	 * @param string $source_uploads Source uploads URL.
	 * @param string $source_home Source home URL.
	 * @return true|WP_Error
	 */
	private static function import_post( array $post, $policy, array &$session, $source_uploads, $source_home ) {
		$type = (string) $post['type'];
		$slug = (string) $post['slug'];
		$existing = get_page_by_path( $slug, OBJECT, $type );
		$rename = false;
		if ( $existing instanceof WP_Post && 'rename' === $policy ) {
			$slug = $slug . '-migrated';
			$existing = null;
			$rename = true;
		}
		if ( $existing instanceof WP_Post && in_array( $policy, array( 'ask', 'skip' ), true ) ) {
			$session['mappings']['posts'][ (int) $post['source_id'] ] = (int) $existing->ID;
			return true;
		}
		$content = Picot_Dev_Mcp_Content::rewrite_content( (string) $post['content'], $session['mappings']['media'], $source_uploads, $source_home );
		$excerpt = Picot_Dev_Mcp_Content::rewrite_urls( (string) ( $post['excerpt'] ?? '' ), $source_home, $source_uploads, home_url(), (string) ( wp_upload_dir( null, false )['baseurl'] ?? '' ) );
		$author = 0;
		if ( ! empty( $post['author_login'] ) ) {
			$user = get_user_by( 'login', (string) $post['author_login'] );
			if ( $user instanceof WP_User ) {
				$author = (int) $user->ID;
			} else {
				$session['warnings'][] = 'author_unmatched:' . $post['author_login'];
				$author = get_current_user_id();
			}
		}
		$data = array(
			'post_type'     => $type,
			'post_name'     => $slug,
			'post_status'   => (string) ( $post['status'] ?? 'draft' ),
			'post_title'    => (string) ( $post['title'] ?? '' ),
			'post_content'  => $content,
			'post_excerpt'  => $excerpt,
			'post_date_gmt' => (string) ( $post['date_gmt'] ?? '' ),
			'menu_order'    => (int) ( $post['menu_order'] ?? 0 ),
			'post_author'   => $author,
		);
		if ( $existing instanceof WP_Post && in_array( $policy, array( 'overwrite', 'merge' ), true ) ) {
			$data['ID'] = (int) $existing->ID;
			$post_id = wp_update_post( wp_slash( $data ), true );
			$created = false;
		} else {
			$post_id = wp_insert_post( wp_slash( $data ), true );
			$created = true;
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;
		$session['mappings']['posts'][ (int) $post['source_id'] ] = $post_id;
		if ( $created ) {
			$session['created_posts'][] = $post_id;
			update_post_meta( $post_id, '_picot_dev_mcp_migration', $session['id'] );
		}
		if ( ! empty( $post['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( (string) $post['template'] ) );
		}
		foreach ( (array) ( $post['terms'] ?? array() ) as $taxonomy => $slugs ) {
			if ( ! in_array( (string) $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				continue;
			}
			$ids = array();
			foreach ( (array) $slugs as $term_slug ) {
				$term = get_term_by( 'slug', (string) $term_slug, (string) $taxonomy );
				if ( $term instanceof WP_Term ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( ! empty( $ids ) ) {
				if ( 'merge' === $policy && $existing instanceof WP_Post ) {
					wp_set_post_terms( $post_id, $ids, (string) $taxonomy, true );
				} else {
					wp_set_post_terms( $post_id, $ids, (string) $taxonomy, false );
				}
			}
		}
		if ( ! empty( $post['featured_id'] ) && isset( $session['mappings']['media'][ (int) $post['featured_id'] ] ) ) {
			set_post_thumbnail( $post_id, (int) $session['mappings']['media'][ (int) $post['featured_id'] ] );
		}
		foreach ( (array) ( $post['meta'] ?? array() ) as $key => $value ) {
			if ( Picot_Dev_Mcp_Content::skip_meta_key( (string) $key ) || '_thumbnail_id' === $key ) {
				continue;
			}
			$value = Picot_Dev_Mcp_Content::rewrite_meta_value( $value, $session['mappings']['media'], $source_uploads, $source_home );
			update_post_meta( $post_id, (string) $key, $value );
		}
		if ( ! empty( $post['blocks'] ) && class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			foreach ( (array) $post['blocks'] as $block_name ) {
				if ( is_string( $block_name ) && str_contains( $block_name, '/' ) && ! $registry->is_registered( $block_name ) ) {
					$session['warnings'][] = 'missing_block:' . $block_name;
				}
			}
		}
		unset( $rename );
		return true;
	}

	/**
	 * Write planned files.
	 *
	 * @param array  $package Package.
	 * @param array  $plan    Plan.
	 * @param string $area    theme|plugin.
	 * @param string $peer    Peer.
	 * @return true|WP_Error
	 */
	private static function import_files( array $package, array $plan, $peer ) {
		$wanted = array();
		foreach ( array_merge( $plan['files']['new'], $plan['files']['modified'] ) as $ref ) {
			if ( is_array( $ref ) && ! empty( $ref['path'] ) ) {
				$wanted[ ( $ref['area'] ?? 'theme' ) . '|' . $ref['path'] ] = true;
			}
		}
		$grouped = array( 'theme' => array(), 'plugin' => array() );
		foreach ( (array) ( $package['files'] ?? array() ) as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) ) {
				continue;
			}
			$area = ( isset( $file['area'] ) && 'plugin' === $file['area'] ) ? 'plugin' : 'theme';
			if ( empty( $wanted[ $area . '|' . $file['path'] ] ) ) {
				continue;
			}
			if ( isset( $file['content'] ) && is_string( $file['content'] ) ) {
				$content = $file['content'];
			} elseif ( ! empty( $file['transfer'] ) && is_array( $file['transfer'] ) ) {
				$tmp = Picot_Dev_Mcp_Transfer::pull( $peer, (string) $file['transfer']['token'], (string) $file['transfer']['path'], (string) ( $file['sha256'] ?? '' ) );
				if ( is_wp_error( $tmp ) ) {
					return $tmp;
				}
				$content = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				wp_delete_file( $tmp );
			} else {
				continue;
			}
			$grouped[ $area ][] = array(
				'op'        => 'write',
				'path'      => (string) $file['path'],
				'content'   => $content,
				'overwrite' => true,
			);
		}
		$snapshot_id = '';
		$validation  = array( 'ok' => true );
		foreach ( $grouped as $area => $changes ) {
			if ( empty( $changes ) ) {
				continue;
			}
			$result = Picot_Dev_Mcp_Change::apply(
				array(
					'area'    => $area,
					'action'  => 'Migration files',
					'input'   => array( 'confirm_production' => true ),
					'changes' => $changes,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['snapshot']['id'] ) ) {
				$snapshot_id = (string) $result['snapshot']['id'];
			}
			if ( isset( $result['validation'] ) ) {
				$validation = $result['validation'];
			}
		}
		return array(
			'snapshot_id' => $snapshot_id,
			'validation'  => $validation,
		);
	}

	/**
	 * Export text files inline and other files as transfer handles.
	 *
	 * @param string   $area  theme|plugin.
	 * @param string[] $paths Relative paths.
	 * @return array<int, array<string, mixed>>
	 */
	private static function export_files( $area, array $paths ) {
		$out = array();
		foreach ( $paths as $relative ) {
			$absolute = 'plugin' === $area
				? Picot_Dev_Mcp_Paths::resolve_plugin( $relative, 'read' )
				: Picot_Dev_Mcp_Paths::resolve_theme( $relative, 'read', 'active' );
			if ( is_wp_error( $absolute ) || ! is_file( $absolute ) ) {
				continue;
			}
			$size = (int) filesize( $absolute );
			$hash = hash_file( 'sha256', $absolute );
			$ext  = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
			$row  = array(
				'area'   => $area,
				'path'   => $relative,
				'size'   => $size,
				'sha256' => $hash,
			);
			if ( in_array( $ext, Picot_Dev_Mcp_Files::TEXT_EXTENSIONS, true ) && $size <= 200 * KB_IN_BYTES ) {
				$row['content'] = (string) file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			} else {
				$transfer = Picot_Dev_Mcp_Transfer::issue( $absolute, 'file:' . $relative );
				if ( ! is_wp_error( $transfer ) ) {
					$row['transfer'] = $transfer;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Normalize package shapes accepted from AI clients.
	 *
	 * Settings may arrive as export `{ items: [...] }` or a flat `{ name: value }` map.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	private static function normalize_package( array $package ) {
		if ( empty( $package['settings'] ) || ! is_array( $package['settings'] ) ) {
			return $package;
		}
		$settings = $package['settings'];
		if ( isset( $settings['items'] ) && is_array( $settings['items'] ) ) {
			$items = array();
			foreach ( $settings['items'] as $key => $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) ) {
					$items[] = array(
						'name'  => (string) $item['name'],
						'class' => (string) ( $item['class'] ?? Picot_Dev_Mcp_Site::classify_option( (string) $item['name'] ) ),
						'value' => $item['value'] ?? null,
					);
					continue;
				}
				if ( is_string( $key ) && '' !== $key ) {
					$items[] = array(
						'name'  => $key,
						'class' => Picot_Dev_Mcp_Site::classify_option( $key ),
						'value' => $item,
					);
				}
			}
			$package['settings']['items'] = $items;
			return $package;
		}

		$items = array();
		foreach ( $settings as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || in_array( $name, array( 'home_url', 'items' ), true ) ) {
				continue;
			}
			$items[] = array(
				'name'  => $name,
				'class' => Picot_Dev_Mcp_Site::classify_option( $name ),
				'value' => $value,
			);
		}
		$package['settings'] = array(
			'home_url' => (string) ( $settings['home_url'] ?? '' ),
			'items'    => $items,
		);
		return $package;
	}

	/**
	 * Export categories and tags.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function export_all_terms() {
		$rows = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$rows[] = array(
					'taxonomy'    => $taxonomy,
					'slug'        => $term->slug,
					'name'        => $term->name,
					'description' => $term->description,
					'parent_slug' => $term->parent ? (string) get_term_field( 'slug', (int) $term->parent, $taxonomy ) : '',
					'source_id'   => (int) $term->term_id,
				);
			}
		}
		return $rows;
	}

	/**
	 * Whether the package carries theme files.
	 *
	 * @param array $package Package.
	 * @return bool
	 */
	private static function package_has_theme_files( array $package ) {
		foreach ( (array) ( $package['files'] ?? array() ) as $file ) {
			if ( is_array( $file ) && ( $file['area'] ?? 'theme' ) === 'theme' ) {
				return true;
			}
		}
		return in_array( (string) ( $package['type'] ?? '' ), array( 'theme', 'theme_components' ), true );
	}

	/**
	 * File destination area.
	 *
	 * @param array $package Package.
	 * @return string
	 */
	private static function file_area( array $package ) {
		$type = (string) ( $package['type'] ?? '' );
		if ( 'plugin' === $type ) {
			return 'plugin';
		}
		foreach ( (array) ( $package['files'] ?? array() ) as $file ) {
			if ( is_array( $file ) && ( $file['area'] ?? '' ) === 'plugin' ) {
				return 'plugin';
			}
		}
		return 'theme';
	}

	/**
	 * Persist a session.
	 *
	 * @param array $session Session.
	 * @return void
	 */
	private static function write_session( array $session ) {
		$dir = Picot_Dev_Mcp_Storage::dir( 'migrations' );
		if ( is_wp_error( $dir ) ) {
			return;
		}
		Picot_Dev_Mcp_Storage::write_json( $dir . '/' . $session['id'] . '.json', $session );
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index = array_values(
			array_filter(
				$index,
				static function ( $row ) use ( $session ) {
					return is_array( $row ) && ( $row['id'] ?? '' ) !== $session['id'];
				}
			)
		);
		$index[] = array(
			'id'         => $session['id'],
			'created_at' => $session['created_at'] ?? '',
			'source'     => $session['source']['home_url'] ?? '',
			'snapshot'   => $session['snapshot_id'] ?? '',
		);
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Read a session file.
	 *
	 * @param string $id Session id.
	 * @return array|WP_Error
	 */
	private static function read_session( $id ) {
		if ( ! preg_match( '/^MIG-\d{8}-\d{4}$/', (string) $id ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Migration IDが不正です。', 'picot-developer-mcp' ) );
		}
		$dir = Picot_Dev_Mcp_Storage::dir( 'migrations' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		return Picot_Dev_Mcp_Storage::read_json( $dir . '/' . $id . '.json' );
	}
}
