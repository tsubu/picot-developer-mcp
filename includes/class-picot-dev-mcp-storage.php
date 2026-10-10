<?php
/**
 * Private on-disk storage for snapshots and migration sessions.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Storage class.
 */
class Picot_Dev_Mcp_Storage {

	/**
	 * Base directory under uploads.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', (string) $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::directory_name( $uploads['basedir'] );
		if ( ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ストレージディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * Storage directory name with a random suffix.
	 *
	 * Snapshot ids and blob names are predictable, and .htaccess is ignored by nginx,
	 * so the directory itself must not be guessable. A legacy fixed-name directory is moved once.
	 *
	 * @param string $basedir Uploads base directory.
	 * @return string
	 */
	private static function directory_name( $basedir ) {
		$name = (string) get_option( 'picot_dev_mcp_storage_dir', '' );
		if ( preg_match( '/^picot-developer-mcp-[a-z0-9]{20}$/', $name ) ) {
			return $name;
		}
		$name   = 'picot-developer-mcp-' . strtolower( wp_generate_password( 20, false, false ) );
		$legacy = trailingslashit( $basedir ) . 'picot-developer-mcp';
		if ( is_dir( $legacy ) && ! rename( $legacy, trailingslashit( $basedir ) . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return 'picot-developer-mcp';
		}
		update_option( 'picot_dev_mcp_storage_dir', $name, false );
		return $name;
	}

	/**
	 * Subdirectory, created when missing.
	 *
	 * @param string $name snapshots|migrations|packages.
	 * @return string|WP_Error
	 */
	public static function dir( $name ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$name = sanitize_key( $name );
		$dir  = $base . '/' . $name;
		if ( ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ストレージディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * Next id such as DEV-20260926-0001.
	 *
	 * @param string $prefix DEV or MIG.
	 * @return string
	 */
	public static function next_id( $prefix ) {
		$prefix = preg_replace( '/[^A-Z]/', '', strtoupper( (string) $prefix ) );
		if ( '' === $prefix ) {
			$prefix = 'DEV';
		}
		$day = wp_date( 'Ymd' );
		$key = 'picot_dev_mcp_seq_' . $prefix . '_' . $day;
		$n   = (int) get_option( $key, 0 ) + 1;
		update_option( $key, $n, false );
		return sprintf( '%s-%s-%04d', $prefix, $day, $n );
	}

	/**
	 * Write JSON atomically.
	 *
	 * @param string $path Absolute path.
	 * @param mixed  $data Data.
	 * @return true|WP_Error
	 */
	public static function write_json( $path, $data ) {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'JSONを生成できません。', 'picot-developer-mcp' ) );
		}
		return self::put_file( $path, $json );
	}

	/**
	 * Read JSON file.
	 *
	 * @param string $path Absolute path.
	 * @return mixed|WP_Error
	 */
	public static function read_json( $path ) {
		if ( ! is_readable( $path ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '記録ファイルが見つかりません。', 'picot-developer-mcp' ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( '記録ファイルを読めません。', 'picot-developer-mcp' ) );
		}
		return $data;
	}

	/**
	 * Atomic file write.
	 *
	 * @param string $path    Destination.
	 * @param string $content Contents.
	 * @return true|WP_Error
	 */
	public static function put_file( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		$tmp = $path . '.picot-tmp-' . wp_generate_password( 6, false, false );
		$ok  = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $ok ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ファイルを書き込めません。', 'picot-developer-mcp' ) );
		}
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ファイルを確定できません。', 'picot-developer-mcp' ) );
		}
		return true;
	}

	/**
	 * Copy a file into storage.
	 *
	 * @param string $source Source absolute path.
	 * @param string $dest   Destination absolute path.
	 * @return true|WP_Error
	 */
	public static function copy_file( $source, $dest ) {
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		if ( ! is_readable( $source ) ) {
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'コピー元を読めません。', 'picot-developer-mcp' ) );
		}
		if ( ! copy( $source, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return Picot_Dev_Mcp_Errors::make( 'snapshot_failed', __( 'ファイルをコピーできません。', 'picot-developer-mcp' ) );
		}
		return true;
	}

	/**
	 * Delete a directory tree that lives under our storage root.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function delete_tree( $dir ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return;
		}
		$real_base = realpath( $base );
		$real_dir  = realpath( $dir );
		if ( ! is_string( $real_base ) || ! is_string( $real_dir ) ) {
			return;
		}
		$real_base = wp_normalize_path( $real_base );
		$real_dir  = wp_normalize_path( $real_dir );
		if ( $real_dir !== $real_base && ! str_starts_with( $real_dir, $real_base . '/' ) ) {
			return;
		}
		self::rrmdir( $real_dir );
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $dir Absolute real path.
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Block web access to a storage directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function protect( $dir ) {
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$htaccess = $dir . '/.htaccess';
		// Rewrite the 1.0.0 rules, whose bare "Deny from all" errors on Apache 2.4 without mod_access_compat.
		if ( ! file_exists( $htaccess ) || ! str_contains( (string) file_get_contents( $htaccess ), 'IfModule' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
