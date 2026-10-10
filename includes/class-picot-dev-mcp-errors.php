<?php
/**
 * Structured errors for AI clients.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Errors class.
 */
class Picot_Dev_Mcp_Errors {

	/**
	 * Stable error codes from the v1 spec.
	 *
	 * @var string[]
	 */
	const CODES = array(
		'authentication_failed',
		'feature_disabled',
		'permission_denied',
		'invalid_target',
		'theme_not_active',
		'plugin_not_selected',
		'core_write_forbidden',
		'protected_file',
		'snapshot_failed',
		'validation_failed',
		'migration_conflict',
		'migration_failed',
		'rollback_failed',
	);

	/**
	 * Create a WP_Error whose message includes the stable code.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message without secrets.
	 * @param array  $data    Extra data safe to return to the client.
	 * @return WP_Error
	 */
	public static function make( $code, $message, $data = array() ) {
		$code    = sanitize_key( (string) $code );
		$message = self::redact_text( (string) $message );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['code'] = $code;
		return new WP_Error( $code, '[' . $code . '] ' . $message, $data );
	}

	/**
	 * Normalize a handler result for MCP tool output.
	 *
	 * Failure is returned as `{ success: false, error: json }` so the MCP Adapter
	 * surfaces the structured payload (code, message, data) in the tool error text.
	 *
	 * @param mixed $result Result or WP_Error.
	 * @return array
	 */
	public static function format( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$payload = array(
				'success' => false,
				'code'    => $result->get_error_code(),
				'error'   => self::plain_message( $result ),
			);
			if ( is_array( $data ) ) {
				unset( $data['status'] );
				if ( ! empty( $data ) ) {
					$payload['data'] = self::redact( $data );
				}
			}
			return array(
				'success' => false,
				'code'    => $payload['code'],
				'error'   => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			);
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Message without the leading [code] prefix.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function plain_message( WP_Error $error ) {
		$message = $error->get_error_message();
		$code    = $error->get_error_code();
		$prefix  = '[' . $code . '] ';
		if ( str_starts_with( $message, $prefix ) ) {
			return substr( $message, strlen( $prefix ) );
		}
		return $message;
	}

	/**
	 * Remove secret-like values from a structure.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && self::is_secret_key( $key ) ) {
					$clean[ $key ] = '[redacted]';
					continue;
				}
				$clean[ $key ] = self::redact( $item );
			}
			return $clean;
		}
		if ( is_string( $value ) ) {
			return self::redact_text( $value );
		}
		return $value;
	}

	/**
	 * Whether an array key looks like a secret.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		return (bool) preg_match( '/(password|passwd|secret|api[_-]?key|token|salt|credential|authorization|private[_-]?key|session)/i', $key );
	}

	/**
	 * Strip bearer tokens, API keys, and private key blocks from text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/pmcp_dev_[a-zA-Z0-9]+/', 'pmcp_dev_[redacted]', $text );
		$text = preg_replace( '/pmcp_[a-zA-Z0-9]+/', 'pmcp_[redacted]', $text );
		$text = preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', $text );
		$text = preg_replace( '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----/', '[redacted private key]', $text );
		return $text;
	}
}
