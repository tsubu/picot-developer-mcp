<?php
/**
 * File snapshots and rollback.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Snapshot class.
 */
class Picot_Dev_Mcp_Snapshot {

	const INDEX_OPTION = 'picot_dev_mcp_snapshot_index';

	/**
	 * Capture files before a change.
	 *
	 * @param array $args {
	 *     @type string   $area    theme|plugin|settings.
	 *     @type string   $target  Slug.
	 *     @type string[] $files   Relative paths that already exist.
	 *     @type string[] $created Relative paths that will be created.
	 *     @type string[] $deleted Relative paths that will be deleted.
	 *     @type array    $options Option name => current value, for settings restore.
	 * }
	 * @return array|WP_Error Snapshot summary.
	 */
	public static function create( array $args ) {
		$area   = sanitize_key( (string) ( $args['area'] ?? 'theme' ) );
		$target = sanitize_text_field( (string) ( $args['target'] ?? '' ) );
		$id     = Picot_Dev_Mcp_Storage::next_id( 'DEV' );
		$dir    = Picot_Dev_Mcp_Storage::dir( 'snapshots' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$snap_dir = $dir . '/' . $id;
		if ( ! wp_mkdir_p( $snap_dir . '/files' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'Snapshotディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}

		$root = self::root_for( $area, $target );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$files = array();
		foreach ( array_unique( array_map( 'strval', (array) ( $args['files'] ?? array() ) ) ) as $relative ) {
			if ( '' === $relative ) {
				continue;
			}
			$absolute = self::absolute( $area, $relative );
			if ( is_wp_error( $absolute ) || ! is_file( $absolute ) ) {
				continue;
			}
			$stored = $snap_dir . '/files/' . md5( $relative );
			$copied = Picot_Dev_Mcp_Storage::copy_file( $absolute, $stored );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$files[] = array(
				'path'    => $relative,
				'existed' => true,
				'size'    => (int) filesize( $absolute ),
				'sha256'  => hash_file( 'sha256', $absolute ),
				'stored'  => 'files/' . md5( $relative ),
			);
		}

		$deleted_blobs = array();
		foreach ( array_unique( array_map( 'strval', (array) ( $args['deleted'] ?? array() ) ) ) as $relative ) {
			$absolute = self::absolute( $area, $relative );
			if ( is_wp_error( $absolute ) || ! is_file( $absolute ) ) {
				continue;
			}
			$stored = $snap_dir . '/files/del-' . md5( $relative );
			$copied = Picot_Dev_Mcp_Storage::copy_file( $absolute, $stored );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$deleted_blobs[] = array(
				'path'   => $relative,
				'stored' => 'files/del-' . md5( $relative ),
			);
		}

		$plugin = null;
		if ( 'plugin' === $area ) {
			$plugin = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $plugin ) ) {
				$plugin = null;
			}
		}

		$options = array();
		foreach ( (array) ( $args['options'] ?? array() ) as $name => $value ) {
			$name = (string) $name;
			if ( Picot_Dev_Mcp_Site::is_forbidden_option( $name ) ) {
				continue;
			}
			$options[ $name ] = get_option( $name, null );
		}

		$manifest = array(
			'id'          => $id,
			'created_at'  => wp_date( 'c' ),
			'area'        => $area,
			'target'      => $target,
			'files'       => $files,
			'created'     => array_values( array_map( 'strval', (array) ( $args['created'] ?? array() ) ) ),
			'deleted'     => $deleted_blobs,
			'options'     => $options,
			'theme'       => 'theme' === $area ? Picot_Dev_Mcp_Paths::active_theme() : null,
			'plugin'      => $plugin ? array(
				'slug'   => $plugin['slug'],
				'file'   => $plugin['file'],
				'active' => $plugin['active'],
			) : null,
		);
		unset( $manifest['theme']['root'], $manifest['theme']['parent_root'] );

		$written = Picot_Dev_Mcp_Storage::write_json( $snap_dir . '/manifest.json', $manifest );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		self::remember( $manifest );
		return array(
			'id'         => $id,
			'created_at' => $manifest['created_at'],
			'area'       => $area,
			'target'     => $target,
			'files'      => array_column( $files, 'path' ),
			'created'    => $manifest['created'],
		);
	}

	/**
	 * List snapshot summaries.
	 *
	 * @param int $limit Limit.
	 * @return array<string, mixed>
	 */
	public static function list_snapshots( $limit = 50 ) {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index = array_reverse( $index );
		return array(
			'items' => array_slice( $index, 0, max( 1, min( 100, (int) $limit ) ) ),
		);
	}

	/**
	 * Snapshot detail.
	 *
	 * @param string|array $id Snapshot id or input with snapshot_id/id.
	 * @return array|WP_Error
	 */
	public static function detail( $id ) {
		$path = self::manifest_path( self::normalize_id( $id ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		return Picot_Dev_Mcp_Storage::read_json( $path );
	}

	/**
	 * Restore a snapshot.
	 *
	 * @param string|array $id Snapshot id or input with snapshot_id/id.
	 * @return array|WP_Error
	 */
	public static function rollback( $id ) {
		$id = self::normalize_id( $id );
		$manifest = self::detail( $id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$dir = self::directory( $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$area   = (string) ( $manifest['area'] ?? '' );
		$target = (string) ( $manifest['target'] ?? '' );
		if ( 'theme' === $area ) {
			$active = Picot_Dev_Mcp_Paths::active_theme();
			if ( $active['stylesheet'] !== $target ) {
				return Picot_Dev_Mcp_Errors::make( 'theme_not_active', __( 'Snapshotのテーマは現在の有効テーマと一致しません。', 'picot-developer-mcp' ) );
			}
		}
		if ( 'plugin' === $area && Picot_Dev_Mcp_Settings::instance()->plugin_slug() !== $target ) {
			return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( 'Snapshotのプラグインは現在の開発対象と一致しません。', 'picot-developer-mcp' ) );
		}

		$restored = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $file ) {
			if ( empty( $file['path'] ) || empty( $file['stored'] ) ) {
				continue;
			}
			$absolute = self::absolute( $area, (string) $file['path'] );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			$copied = Picot_Dev_Mcp_Storage::copy_file( $dir . '/' . $file['stored'], $absolute );
			if ( is_wp_error( $copied ) ) {
				return Picot_Dev_Mcp_Errors::make( 'rollback_failed', __( 'ファイルを復元できません。', 'picot-developer-mcp' ), array( 'path' => $file['path'] ) );
			}
			$restored[] = $file['path'];
		}

		$removed = array();
		foreach ( (array) ( $manifest['created'] ?? array() ) as $relative ) {
			$absolute = self::absolute( $area, (string) $relative );
			if ( is_wp_error( $absolute ) || ! is_file( $absolute ) ) {
				continue;
			}
			wp_delete_file( $absolute );
			$removed[] = (string) $relative;
			self::remove_empty_parents( $area, dirname( (string) $relative ) );
		}

		foreach ( (array) ( $manifest['deleted'] ?? array() ) as $file ) {
			if ( empty( $file['path'] ) || empty( $file['stored'] ) ) {
				continue;
			}
			$absolute = self::absolute( $area, (string) $file['path'] );
			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}
			wp_mkdir_p( dirname( $absolute ) );
			$copied = Picot_Dev_Mcp_Storage::copy_file( $dir . '/' . $file['stored'], $absolute );
			if ( is_wp_error( $copied ) ) {
				return Picot_Dev_Mcp_Errors::make( 'rollback_failed', __( '削除ファイルを復元できません。', 'picot-developer-mcp' ) );
			}
			$restored[] = $file['path'];
		}

		foreach ( (array) ( $manifest['options'] ?? array() ) as $name => $value ) {
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
				'type'     => $area,
				'target'   => $target,
				'action'   => 'Rollback ' . $id,
				'result'   => 'success',
				'snapshot' => $id,
				'files'    => $restored,
			)
		);

		return array(
			'id'       => $id,
			'restored' => $restored,
			'removed'  => $removed,
		);
	}

	/**
	 * Remember a summary for the history screen.
	 *
	 * @param array $manifest Manifest.
	 * @return void
	 */
	private static function remember( array $manifest ) {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index[] = array(
			'id'         => $manifest['id'],
			'created_at' => $manifest['created_at'],
			'area'       => $manifest['area'],
			'target'     => $manifest['target'],
			'files'      => array_column( (array) $manifest['files'], 'path' ),
			'created'    => $manifest['created'],
		);
		if ( count( $index ) > 100 ) {
			$index = array_slice( $index, -100 );
		}
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Absolute manifest path.
	 *
	 * @param string $id Snapshot id.
	 * @return string|WP_Error
	 */
	private static function manifest_path( $id ) {
		$dir = self::directory( $id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$path = $dir . '/manifest.json';
		if ( ! is_readable( $path ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Snapshotが見つかりません。', 'picot-developer-mcp' ) );
		}
		return $path;
	}

	/**
	 * Remove directories left empty after deleting created files, staying inside the target root.
	 *
	 * @param string $area     Area.
	 * @param string $relative Relative directory.
	 * @return void
	 */
	private static function remove_empty_parents( $area, $relative ) {
		while ( '' !== $relative && '.' !== $relative ) {
			$absolute = self::absolute( $area, $relative );
			if ( is_wp_error( $absolute ) || ! is_dir( $absolute ) ) {
				return;
			}
			$entries = scandir( $absolute );
			if ( ! is_array( $entries ) || count( $entries ) > 2 || ! rmdir( $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				return;
			}
			$relative = dirname( $relative );
		}
	}

	/**
	 * Accept a string id or an ability-style input array.
	 *
	 * @param mixed $id Id or input.
	 * @return string
	 */
	private static function normalize_id( $id ) {
		if ( is_array( $id ) ) {
			$id = $id['snapshot_id'] ?? $id['id'] ?? '';
		}
		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * Snapshot directory after id validation.
	 *
	 * @param string $id Snapshot id.
	 * @return string|WP_Error
	 */
	private static function directory( $id ) {
		$id = self::normalize_id( $id );
		if ( ! preg_match( '/^DEV-\d{8}-\d{4}$/', $id ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Snapshot IDが不正です。', 'picot-developer-mcp' ) );
		}
		$dir = Picot_Dev_Mcp_Storage::dir( 'snapshots' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		return $dir . '/' . $id;
	}

	/**
	 * Root used only as a sanity check.
	 *
	 * @param string $area   Area.
	 * @param string $target Target slug.
	 * @return string|WP_Error
	 */
	private static function root_for( $area, $target ) {
		if ( 'theme' === $area ) {
			$theme = Picot_Dev_Mcp_Paths::active_theme();
			if ( $theme['stylesheet'] !== $target ) {
				return Picot_Dev_Mcp_Errors::make( 'theme_not_active', __( '有効なテーマだけをSnapshotできます。', 'picot-developer-mcp' ) );
			}
			return $theme['root'];
		}
		if ( 'plugin' === $area ) {
			$plugin = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $plugin ) ) {
				return $plugin;
			}
			if ( $plugin['slug'] !== $target ) {
				return Picot_Dev_Mcp_Errors::make( 'plugin_not_selected', __( '開発対象プラグインだけをSnapshotできます。', 'picot-developer-mcp' ) );
			}
			return $plugin['root'];
		}
		if ( 'settings' === $area || 'migration' === $area ) {
			return WP_CONTENT_DIR;
		}
		return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Snapshot対象が不正です。', 'picot-developer-mcp' ) );
	}

	/**
	 * Absolute path for a stored relative path.
	 *
	 * @param string $area     Area.
	 * @param string $relative Relative path.
	 * @return string|WP_Error
	 */
	private static function absolute( $area, $relative ) {
		if ( 'theme' === $area ) {
			return Picot_Dev_Mcp_Paths::resolve_theme( $relative, 'write', 'active' );
		}
		if ( 'plugin' === $area ) {
			return Picot_Dev_Mcp_Paths::resolve_plugin( $relative, 'write' );
		}
		return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Snapshot対象が不正です。', 'picot-developer-mcp' ) );
	}
}
