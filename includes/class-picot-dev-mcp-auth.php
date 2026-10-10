<?php
/**
 * Bearer authentication for the developer MCP route.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Auth class.
 */
class Picot_Dev_Mcp_Auth {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Token verified for this request.
	 *
	 * @var array|null
	 */
	private $current_token = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Current token.
	 *
	 * @return array|null
	 */
	public function get_current_token() {
		return $this->current_token;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'determine_current_user', array( $this, 'maybe_authenticate_bearer' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 99 );
	}

	/**
	 * Authenticate Bearer pmcp_dev_… on our MCP route only.
	 *
	 * @param int|false $user_id Current user.
	 * @return int|false
	 */
	public function maybe_authenticate_bearer( $user_id ) {
		if ( ! $this->is_mcp_request() ) {
			return $user_id;
		}
		$token = $this->extract_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}
		$verified = Picot_Dev_Mcp_Api_Key::verify( $token );
		if ( is_wp_error( $verified ) ) {
			return $user_id;
		}
		$this->current_token = $verified;
		return (int) $verified['user_id'];
	}

	/**
	 * Force Bearer auth on the MCP route.
	 *
	 * @param WP_Error|true|null $result Prior result.
	 * @return WP_Error|true|null
	 */
	public function rest_authentication_errors( $result ) {
		if ( ! $this->is_mcp_request() ) {
			return $result;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$check = $this->authenticate_mcp_request( null );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return true;
	}

	/**
	 * Transport permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function transport_permission( $request ) {
		return $this->authenticate_mcp_request( $request );
	}

	/**
	 * Shared authentication.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return true|WP_Error
	 */
	private function authenticate_mcp_request( $request ) {
		if ( ! Picot_Dev_Mcp_Settings::instance()->is_enabled() ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( 'MCPサーバーは無効です。', 'picot-developer-mcp' ) );
		}

		$origin = $this->validate_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$token = $this->extract_bearer_token( $request );
		if ( ! $token ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'Authorization: Bearer APIキーが必要です。', 'picot-developer-mcp' ) );
		}

		$current = $this->current_token;
		if ( is_array( $current ) && ! empty( $current['hash'] ) && hash_equals( (string) $current['hash'], Picot_Dev_Mcp_Api_Key::hash( $token ) ) ) {
			wp_set_current_user( (int) $current['user_id'] );
			return is_user_logged_in()
				? true
				: Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( '認証に失敗しました。', 'picot-developer-mcp' ) );
		}

		$verified = Picot_Dev_Mcp_Api_Key::verify( $token );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$this->current_token = $verified;
		wp_set_current_user( (int) $verified['user_id'] );
		if ( ! is_user_logged_in() ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( '認証に失敗しました。', 'picot-developer-mcp' ) );
		}
		return true;
	}

	/**
	 * Allow missing Origin. When present, it must match this site.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return true|WP_Error
	 */
	public function validate_origin( $request = null ) {
		$origin = '';
		if ( $request instanceof WP_REST_Request ) {
			$origin = (string) $request->get_header( 'origin' );
		} elseif ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}
		if ( '' === $origin ) {
			return true;
		}
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$allowed     = array_filter(
			array(
				wp_parse_url( home_url(), PHP_URL_HOST ),
				wp_parse_url( site_url(), PHP_URL_HOST ),
			)
		);
		if ( ! is_string( $origin_host ) || '' === $origin_host ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'Originが許可されていません。', 'picot-developer-mcp' ) );
		}
		$origin_host = strtolower( $origin_host );
		foreach ( $allowed as $host ) {
			if ( is_string( $host ) && strtolower( $host ) === $origin_host ) {
				return true;
			}
		}
		return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'Originが許可されていません。', 'picot-developer-mcp' ) );
	}

	/**
	 * Extract bearer token.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return string|null
	 */
	public function extract_bearer_token( $request = null ) {
		$header = '';
		if ( $request instanceof WP_REST_Request ) {
			$header = (string) $request->get_header( 'authorization' );
		}
		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( ! preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return null;
		}
		return $matches[1];
	}

	/**
	 * Whether the current HTTP request targets our MCP endpoint.
	 *
	 * @return bool
	 */
	private function is_mcp_request() {
		$ns    = preg_quote( Picot_Dev_Mcp_Settings::instance()->route_namespace(), '#' );
		$route = preg_quote( Picot_Dev_Mcp_Settings::instance()->route(), '#' );

		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$rest_route = (string) $GLOBALS['wp']->query_vars['rest_route'];
			if ( preg_match( '#^/' . $ns . '/' . $route . '/?$#', $rest_route ) ) {
				return true;
			}
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri   = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( preg_match( '#/wp-json/' . $ns . '/' . $route . '/?$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#(?:^|&)rest_route=/' . $ns . '/' . $route . '/?(?:&|$)#', $query ) ) {
			return true;
		}
		return false;
	}
}
