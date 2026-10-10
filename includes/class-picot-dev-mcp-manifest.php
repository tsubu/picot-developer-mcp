<?php
/**
 * File manifests keyed by sha256, not modification time.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Manifest class.
 */
class Picot_Dev_Mcp_Manifest {

	/**
	 * Build a manifest for a directory.
	 *
	 * @param string $root Absolute root.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( $root ) {
		$items = Picot_Dev_Mcp_Files::list_tree( $root, 2000 );
		if ( is_wp_error( $items ) ) {
			return array();
		}
		$manifest = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['dir'] ) ) {
				continue;
			}
			$path = $root . '/' . $item['path'];
			if ( ! is_file( $path ) || Picot_Dev_Mcp_Paths::is_protected( (string) $item['path'] ) ) {
				continue;
			}
			$manifest[] = array(
				'path'   => (string) $item['path'],
				'size'   => (int) $item['size'],
				'sha256' => hash_file( 'sha256', $path ),
			);
		}
		return $manifest;
	}

	/**
	 * Compare a local manifest to a remote manifest.
	 *
	 * new = remote only, missing = local only.
	 *
	 * @param array $local  Local files.
	 * @param array $remote Remote files.
	 * @return array<string, mixed>
	 */
	public static function compare( array $local, array $remote ) {
		$local_map  = self::index( $local );
		$remote_map = self::index( $remote );
		$rows       = array();
		$counts     = array(
			'same'     => 0,
			'modified' => 0,
			'new'      => 0,
			'missing'  => 0,
		);
		$paths = array_unique( array_merge( array_keys( $local_map ), array_keys( $remote_map ) ) );
		sort( $paths );
		foreach ( $paths as $path ) {
			$has_local  = isset( $local_map[ $path ] );
			$has_remote = isset( $remote_map[ $path ] );
			if ( $has_local && $has_remote ) {
				$status = hash_equals( (string) $local_map[ $path ]['sha256'], (string) $remote_map[ $path ]['sha256'] ) ? 'same' : 'modified';
			} elseif ( $has_remote ) {
				$status = 'new';
			} else {
				$status = 'missing';
			}
			++$counts[ $status ];
			if ( 'same' === $status ) {
				continue;
			}
			$rows[] = array(
				'path'   => $path,
				'status' => $status,
				'local'  => $has_local ? $local_map[ $path ] : null,
				'remote' => $has_remote ? $remote_map[ $path ] : null,
			);
		}
		return array(
			'counts' => $counts,
			'rows'   => $rows,
		);
	}

	/**
	 * Index manifest rows by path.
	 *
	 * @param array $files Files.
	 * @return array<string, array<string, mixed>>
	 */
	private static function index( array $files ) {
		$map = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) ) {
				continue;
			}
			$map[ (string) $file['path'] ] = array(
				'path'   => (string) $file['path'],
				'size'   => (int) ( $file['size'] ?? 0 ),
				'sha256' => (string) ( $file['sha256'] ?? '' ),
			);
		}
		return $map;
	}
}
