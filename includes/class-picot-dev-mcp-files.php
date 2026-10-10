<?php
/**
 * Read-only file helpers inside an already authorized root.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Files class.
 */
class Picot_Dev_Mcp_Files {

	const TEXT_EXTENSIONS = array( 'php', 'json', 'css', 'js', 'html', 'htm', 'md', 'txt', 'xml', 'svg' );

	/**
	 * List files under a root.
	 *
	 * @param string $root  Absolute root.
	 * @param int    $limit Maximum entries.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function list_tree( $root, $limit = 500 ) {
		if ( ! is_dir( $root ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ディレクトリが見つかりません。', 'picot-developer-mcp' ) );
		}
		$items = array();
		$iter  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iter as $file ) {
			/** @var SplFileInfo $file */
			$absolute = wp_normalize_path( $file->getPathname() );
			$relative = Picot_Dev_Mcp_Paths::relative_from( $root, $absolute );
			if ( self::skip_relative( $relative ) ) {
				continue;
			}
			$items[] = array(
				'path'  => $relative,
				'dir'   => $file->isDir(),
				'size'  => $file->isFile() ? (int) $file->getSize() : 0,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( (string) $a['path'], (string) $b['path'] );
			}
		);
		return $items;
	}

	/**
	 * Read a text file with a size cap.
	 *
	 * @param string $absolute Absolute path.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read( $absolute ) {
		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ファイルを読めません。', 'picot-developer-mcp' ) );
		}
		$size = (int) filesize( $absolute );
		$hash = hash_file( 'sha256', $absolute );
		if ( $size > MB_IN_BYTES ) {
			return array(
				'bytes'   => $size,
				'sha256'  => $hash,
				'omitted' => true,
				'reason'  => 'too_large_for_context',
			);
		}
		$ext = strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::TEXT_EXTENSIONS, true ) && 'json' !== $ext ) {
			return array(
				'bytes'   => $size,
				'sha256'  => $hash,
				'omitted' => true,
				'reason'  => 'binary',
			);
		}
		$content = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $content ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ファイルを読めません。', 'picot-developer-mcp' ) );
		}
		if ( str_contains( $content, '-----BEGIN ' ) && str_contains( $content, 'PRIVATE KEY-----' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'protected_file', __( '秘密鍵を含むファイルは返せません。', 'picot-developer-mcp' ) );
		}
		return array(
			'bytes'   => $size,
			'sha256'  => $hash,
			'content' => $content,
		);
	}

	/**
	 * Literal search.
	 *
	 * @param string   $root       Root.
	 * @param string   $query      Query.
	 * @param string[] $extensions Extensions without dots. Empty means php and json.
	 * @param int      $limit      Match limit.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search( $root, $query, array $extensions = array(), $limit = 50 ) {
		$query = (string) $query;
		if ( strlen( $query ) < 2 ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '検索語は2文字以上にしてください。', 'picot-developer-mcp' ) );
		}
		if ( empty( $extensions ) ) {
			$extensions = array( 'php', 'json' );
		}
		$extensions = array_map( 'strtolower', $extensions );
		$hits       = array();
		$iter       = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$absolute = wp_normalize_path( $file->getPathname() );
			$relative = Picot_Dev_Mcp_Paths::relative_from( $root, $absolute );
			if ( self::skip_relative( $relative ) ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $extensions, true ) ) {
				continue;
			}
			if ( $file->getSize() > 512 * KB_IN_BYTES ) {
				continue;
			}
			$contents = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_string( $contents ) || ! str_contains( $contents, $query ) ) {
				continue;
			}
			$line_no = 1;
			foreach ( preg_split( "/\r\n|\n|\r/", $contents ) as $line ) {
				if ( is_string( $line ) && str_contains( $line, $query ) ) {
					$hits[] = array(
						'path' => $relative,
						'line' => $line_no,
						'text' => substr( Picot_Dev_Mcp_Errors::redact_text( $line ), 0, 240 ),
					);
					if ( count( $hits ) >= $limit ) {
						return $hits;
					}
				}
				++$line_no;
			}
		}
		return $hits;
	}

	/**
	 * Skip VCS and dependency directories.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public static function skip_relative( $relative ) {
		$relative = str_replace( '\\', '/', $relative );
		foreach ( array( '.git/', 'node_modules/', '.svn/' ) as $prefix ) {
			if ( str_starts_with( $relative, $prefix ) || str_contains( $relative, '/' . $prefix ) ) {
				return true;
			}
		}
		$base = basename( $relative );
		return in_array( $base, array( '.DS_Store', '.htaccess' ), true ) || Picot_Dev_Mcp_Paths::is_protected( $relative );
	}
}
