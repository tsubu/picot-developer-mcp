<?php
/**
 * Syntax, header, theme.json, and log validation.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Validation class.
 */
class Picot_Dev_Mcp_Validation {

	/**
	 * Validate changed files and recent PHP fatals.
	 *
	 * @param string[] $files   Absolute paths.
	 * @param int      $log_pos Debug log offset captured before the change.
	 * @param string   $area    theme|plugin.
	 * @return array<string, mixed>
	 */
	public static function run( array $files, $log_pos = 0, $area = '' ) {
		$checks = array();
		$fatal  = false;

		foreach ( $files as $file ) {
			$file = (string) $file;
			if ( ! is_file( $file ) ) {
				$checks[] = array(
					'check'  => 'file_existence',
					'path'   => $file,
					'ok'     => false,
					'detail' => 'missing',
				);
				$fatal = true;
				continue;
			}
			$checks[] = array(
				'check' => 'file_existence',
				'path'  => $file,
				'ok'    => true,
			);

			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( 'php' === $ext ) {
				$syntax = self::php_syntax( $file );
				$checks[] = $syntax;
				if ( empty( $syntax['ok'] ) ) {
					$fatal = true;
				}
			}
			if ( 'json' === $ext ) {
				$json = self::json_syntax( $file );
				$checks[] = $json;
				if ( empty( $json['ok'] ) ) {
					$fatal = true;
				}
				if ( 'theme.json' === basename( $file ) && ! empty( $json['ok'] ) ) {
					$checks[] = self::theme_json_shape( $file );
				}
			}
			if ( in_array( $ext, array( 'html', 'php' ), true ) ) {
				$checks[] = self::block_markup( $file );
			}
		}

		if ( 'theme' === $area ) {
			$checks[] = self::theme_header();
		}
		if ( 'plugin' === $area ) {
			$checks[] = self::plugin_header();
		}

		$logs = self::new_log_lines( (int) $log_pos );
		foreach ( $logs as $line ) {
			if ( preg_match( '/PHP (Fatal|Parse) error/i', $line ) ) {
				$fatal    = true;
				$checks[] = array(
					'check'  => 'php_fatal',
					'ok'     => false,
					'detail' => $line,
				);
			}
		}

		return array(
			'ok'     => ! $fatal,
			'fatal'  => $fatal,
			'checks' => $checks,
			'logs'   => $logs,
		);
	}

	/**
	 * php -l when exec is available, otherwise token_get_all.
	 *
	 * @param string $file Absolute PHP file.
	 * @return array<string, mixed>
	 */
	public static function php_syntax( $file ) {
		$lint = self::php_lint_binary( $file );
		if ( null !== $lint ) {
			return $lint;
		}
		return self::php_lint_tokens( $file );
	}

	/**
	 * Run the PHP binary's syntax check. Never accepts a caller-supplied command.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>|null Null when the binary cannot be executed.
	 */
	private static function php_lint_binary( $file ) {
		if ( ! function_exists( 'exec' ) ) {
			return null;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return null;
		}
		$php = self::cli_binary();
		if ( '' === $php ) {
			return null;
		}
		$cmd    = escapeshellarg( $php ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1';
		$output = array();
		$code   = 1;
		exec( $cmd, $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$text = Picot_Dev_Mcp_Errors::redact_text( implode( "\n", $output ) );
		return array(
			'check'  => 'php_syntax',
			'path'   => $file,
			'ok'     => 0 === (int) $code,
			'engine' => 'php -l',
			'detail' => $text,
		);
	}

	/**
	 * Locate a PHP CLI binary.
	 *
	 * Under FastCGI/FPM, PHP_BINARY is php-cgi or php-fpm; "php-cgi -l" waits on
	 * stdin instead of linting, which hangs the request until the gateway times out.
	 *
	 * @return string Empty when no CLI binary is available.
	 */
	private static function cli_binary() {
		if ( ! defined( 'PHP_BINARY' ) || ! is_string( PHP_BINARY ) || '' === PHP_BINARY ) {
			return '';
		}
		$candidates = array();
		if ( 'cli' === PHP_SAPI || 'php' === strtolower( basename( PHP_BINARY ) ) ) {
			$candidates[] = PHP_BINARY;
		}
		$candidates[] = dirname( PHP_BINARY ) . '/php';
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) && is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Fallback syntax check.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>
	 */
	private static function php_lint_tokens( $file ) {
		$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $src ) ) {
			return array(
				'check' => 'php_syntax',
				'path'  => $file,
				'ok'    => false,
				'engine'=> 'token_get_all',
				'detail'=> 'unreadable',
			);
		}
		try {
			token_get_all( $src, TOKEN_PARSE );
			$ok     = true;
			$detail = 'No syntax errors detected';
		} catch ( ParseError $e ) {
			$ok     = false;
			$detail = $e->getMessage();
		}
		return array(
			'check'  => 'php_syntax',
			'path'   => $file,
			'ok'     => $ok,
			'engine' => 'token_get_all',
			'detail' => Picot_Dev_Mcp_Errors::redact_text( $detail ),
		);
	}

	/**
	 * JSON decode check.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>
	 */
	public static function json_syntax( $file ) {
		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		json_decode( (string) $raw, true );
		$ok = JSON_ERROR_NONE === json_last_error();
		return array(
			'check'  => 'json_syntax',
			'path'   => $file,
			'ok'     => $ok,
			'detail' => $ok ? 'ok' : json_last_error_msg(),
		);
	}

	/**
	 * Minimal theme.json shape check.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>
	 */
	public static function theme_json_shape( $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ok   = is_array( $data ) && isset( $data['version'] ) && is_numeric( $data['version'] );
		if ( $ok && isset( $data['templateParts'] ) && ! is_array( $data['templateParts'] ) ) {
			$ok = false;
		}
		return array(
			'check' => 'theme_json',
			'path'  => $file,
			'ok'    => $ok,
		);
	}

	/**
	 * Rough block-comment balance.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>
	 */
	public static function block_markup( $file ) {
		$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! str_contains( $src, '<!-- wp:' ) ) {
			return array(
				'check' => 'block_markup',
				'path'  => $file,
				'ok'    => true,
				'detail'=> 'no-blocks',
			);
		}
		preg_match_all( '/<!-- wp:([^\s>]+)/', $src, $opens );
		preg_match_all( '/<!-- \/wp:([^\s]+)/', $src, $closes );
		$open_count  = isset( $opens[0] ) ? count( $opens[0] ) : 0;
		$close_count = isset( $closes[0] ) ? count( $closes[0] ) : 0;
		$self_closed = preg_match_all( '/<!-- wp:[^>]*\/-->/', $src );
		$expected    = max( 0, $open_count - (int) $self_closed );
		return array(
			'check'  => 'block_markup',
			'path'   => $file,
			'ok'     => $expected === $close_count,
			'detail' => 'open=' . $open_count . ' self=' . (int) $self_closed . ' close=' . $close_count,
		);
	}

	/**
	 * Active theme stylesheet header.
	 *
	 * @return array<string, mixed>
	 */
	public static function theme_header() {
		$theme = wp_get_theme();
		$ok    = $theme->exists() && '' !== (string) $theme->get( 'Name' );
		return array(
			'check'  => 'theme_header',
			'ok'     => $ok,
			'detail' => (string) $theme->get( 'Name' ),
		);
	}

	/**
	 * Selected plugin header.
	 *
	 * @return array<string, mixed>
	 */
	public static function plugin_header() {
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( is_wp_error( $plugin ) ) {
			return array(
				'check'  => 'plugin_header',
				'ok'     => false,
				'detail' => $plugin->get_error_code(),
			);
		}
		return array(
			'check'  => 'plugin_header',
			'ok'     => '' !== $plugin['file'] && '' !== $plugin['name'],
			'detail' => $plugin['name'],
			'active' => (bool) $plugin['active'],
		);
	}

	/**
	 * Byte offset of the debug log, used to read only new lines.
	 *
	 * @return int
	 */
	public static function log_offset() {
		$path = self::debug_log_path();
		if ( '' === $path || ! is_file( $path ) ) {
			return 0;
		}
		$size = filesize( $path );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * New debug-log lines since an offset, with secrets removed.
	 *
	 * @param int $offset Byte offset.
	 * @return string[]
	 */
	public static function new_log_lines( $offset ) {
		$path = self::debug_log_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}
		$size = filesize( $path );
		if ( false === $size || $size <= $offset ) {
			return array();
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}
		fseek( $handle, max( 0, (int) $offset ) );
		$chunk = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$chunk = Picot_Dev_Mcp_Errors::redact_text( (string) $chunk );
		$lines = preg_split( "/\r\n|\n|\r/", $chunk );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );
		return array_slice( $lines, -40 );
	}

	/**
	 * Tail of the debug log for validation/logs.
	 *
	 * @param int $lines Line count.
	 * @return array<string, mixed>
	 */
	public static function recent_logs( $lines = 80 ) {
		$path = self::debug_log_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return array(
				'path'    => $path,
				'available' => false,
				'lines'   => array(),
			);
		}
		$all = file( $path, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all = array_slice( $all, -max( 1, min( 200, (int) $lines ) ) );
		$all = array_map(
			static function ( $line ) {
				return Picot_Dev_Mcp_Errors::redact_text( (string) $line );
			},
			$all
		);
		return array(
			'path'      => $path,
			'available' => true,
			'lines'     => $all,
		);
	}

	/**
	 * Configured debug.log path.
	 *
	 * @return string
	 */
	public static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			return WP_CONTENT_DIR . '/debug.log';
		}
		$fallback = WP_CONTENT_DIR . '/debug.log';
		return is_file( $fallback ) ? $fallback : '';
	}
}
