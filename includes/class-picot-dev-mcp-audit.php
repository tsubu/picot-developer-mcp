<?php
/**
 * Development history and audit log.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Audit class.
 */
class Picot_Dev_Mcp_Audit {

	const HISTORY_OPTION = 'picot_dev_mcp_history';
	const AUDIT_OPTION   = 'picot_dev_mcp_audit';

	/**
	 * Append a development history row.
	 *
	 * @param array $row Row.
	 * @return void
	 */
	public static function history( array $row ) {
		$entry = array(
			'at'          => wp_date( 'c' ),
			'type'        => sanitize_key( (string) ( $row['type'] ?? 'development' ) ),
			'target'      => sanitize_text_field( (string) ( $row['target'] ?? '' ) ),
			'action'      => sanitize_text_field( (string) ( $row['action'] ?? '' ) ),
			'result'      => sanitize_key( (string) ( $row['result'] ?? 'success' ) ),
			'snapshot'    => sanitize_text_field( (string) ( $row['snapshot'] ?? '' ) ),
			'files'       => array_values( array_map( 'strval', (array) ( $row['files'] ?? array() ) ) ),
			'message'     => Picot_Dev_Mcp_Errors::redact_text( (string) ( $row['message'] ?? '' ) ),
		);
		self::push( self::HISTORY_OPTION, $entry );
	}

	/**
	 * Append an audit row. The API key plaintext is never stored.
	 *
	 * @param array $row Row.
	 * @return void
	 */
	public static function audit( array $row ) {
		$token = Picot_Dev_Mcp_Auth::instance()->get_current_token();
		$user  = wp_get_current_user();
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$entry = array(
			'at'            => wp_date( 'c' ),
			'user_id'       => $user instanceof WP_User ? (int) $user->ID : 0,
			'user_login'    => $user instanceof WP_User ? $user->user_login : '',
			'api_key_id'    => is_array( $token ) ? sanitize_text_field( (string) ( $token['token_id'] ?? '' ) ) : '',
			'client'        => substr( $agent, 0, 180 ),
			'ability'       => sanitize_text_field( (string) ( $row['ability'] ?? '' ) ),
			'target'        => sanitize_text_field( (string) ( $row['target'] ?? '' ) ),
			'input_summary' => self::summarize_input( $row['input'] ?? array() ),
			'files'         => array_values( array_map( 'strval', (array) ( $row['files'] ?? array() ) ) ),
			'result'        => sanitize_key( (string) ( $row['result'] ?? 'success' ) ),
			'code'          => sanitize_key( (string) ( $row['code'] ?? '' ) ),
		);
		self::push( self::AUDIT_OPTION, $entry );
	}

	/**
	 * List history or audit rows, newest first.
	 *
	 * @param string $which history|audit.
	 * @param int    $limit Limit.
	 * @param int    $offset Offset.
	 * @return array
	 */
	public static function list_rows( $which, $limit = 50, $offset = 0 ) {
		$option = 'audit' === $which ? self::AUDIT_OPTION : self::HISTORY_OPTION;
		$rows   = get_option( $option, array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$rows   = array_reverse( $rows );
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		return array(
			'total' => count( $rows ),
			'items' => array_slice( $rows, $offset, $limit ),
		);
	}

	/**
	 * Short, redacted input summary.
	 *
	 * @param mixed $input Input.
	 * @return string
	 */
	public static function summarize_input( $input ) {
		if ( ! is_array( $input ) ) {
			return '';
		}
		$copy = $input;
		foreach ( array( 'content', 'replace', 'search', 'before', 'after', 'package', 'remote' ) as $key ) {
			if ( isset( $copy[ $key ] ) && is_string( $copy[ $key ] ) ) {
				$copy[ $key ] = '[len:' . strlen( $copy[ $key ] ) . ']';
			} elseif ( isset( $copy[ $key ] ) && is_array( $copy[ $key ] ) ) {
				$copy[ $key ] = '[items:' . count( $copy[ $key ] ) . ']';
			}
		}
		$json = wp_json_encode( Picot_Dev_Mcp_Errors::redact( $copy ), JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return '';
		}
		if ( strlen( $json ) > 500 ) {
			return substr( $json, 0, 500 ) . '…';
		}
		return $json;
	}

	/**
	 * Push a row into a capped option.
	 *
	 * @param string $option Option name.
	 * @param array  $entry  Entry.
	 * @return void
	 */
	private static function push( $option, array $entry ) {
		$rows = get_option( $option, array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$rows[] = $entry;
		$max    = (int) Picot_Dev_Mcp_Settings::instance()->all()['log_retention'];
		if ( count( $rows ) > $max ) {
			$rows = array_slice( $rows, -$max );
		}
		update_option( $option, $rows, false );
	}
}
