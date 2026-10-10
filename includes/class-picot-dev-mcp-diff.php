<?php
/**
 * Line diff used by theme/diff and plugin/diff.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Diff class.
 */
class Picot_Dev_Mcp_Diff {

	/**
	 * Build a unified diff. Large files return a hash summary.
	 *
	 * @param string $before Previous contents.
	 * @param string $after  Next contents.
	 * @param string $path   Display path.
	 * @return array<string, mixed>
	 */
	public static function unified( $before, $after, $path = 'file' ) {
		$before = (string) $before;
		$after  = (string) $after;
		if ( hash_equals( hash( 'sha256', $before ), hash( 'sha256', $after ) ) ) {
			return array(
				'status' => 'same',
				'path'   => $path,
				'patch'  => '',
			);
		}

		$a = preg_split( "/\r\n|\n|\r/", $before );
		$b = preg_split( "/\r\n|\n|\r/", $after );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			$a = array();
			$b = array();
		}

		if ( count( $a ) > 1200 || count( $b ) > 1200 ) {
			return array(
				'status'       => 'modified',
				'path'         => $path,
				'truncated'    => true,
				'before_sha256'=> hash( 'sha256', $before ),
				'after_sha256' => hash( 'sha256', $after ),
				'before_lines' => count( $a ),
				'after_lines'  => count( $b ),
				'patch'        => '',
			);
		}

		$prefix = 0;
		$max    = min( count( $a ), count( $b ) );
		while ( $prefix < $max && $a[ $prefix ] === $b[ $prefix ] ) {
			++$prefix;
		}
		$suffix = 0;
		while ( $suffix < ( count( $a ) - $prefix ) && $suffix < ( count( $b ) - $prefix ) && $a[ count( $a ) - 1 - $suffix ] === $b[ count( $b ) - 1 - $suffix ] ) {
			++$suffix;
		}

		$removed = array_slice( $a, $prefix, count( $a ) - $prefix - $suffix );
		$added   = array_slice( $b, $prefix, count( $b ) - $prefix - $suffix );
		$start_a = max( 1, $prefix + 1 );
		$start_b = max( 1, $prefix + 1 );
		$lines   = array(
			'--- a/' . $path,
			'+++ b/' . $path,
			sprintf( '@@ -%d,%d +%d,%d @@', $start_a, count( $removed ), $start_b, count( $added ) ),
		);
		foreach ( $removed as $line ) {
			$lines[] = '-' . $line;
		}
		foreach ( $added as $line ) {
			$lines[] = '+' . $line;
		}

		return array(
			'status' => 'modified',
			'path'   => $path,
			'patch'  => implode( "\n", $lines ),
		);
	}
}
