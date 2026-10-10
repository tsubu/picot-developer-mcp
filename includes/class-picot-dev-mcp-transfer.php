<?php
/**
 * One-time transfer tokens and peer-locked HTTPS pulls.
 *
 * Binary payloads are not placed in the MCP response. The client receives a
 * short-lived token; the destination pulls bytes only from a registered peer.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Transfer class.
 */
class Picot_Dev_Mcp_Transfer {

	const OPTION = 'picot_dev_mcp_transfer_tokens';

	/**
	 * Register REST routes for identity and file transfer.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'picot-developer-mcp/v1',
			'/identity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_identity' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'picot-developer-mcp/v1',
			'/transfer',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_transfer' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public fingerprint. No secrets.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_identity() {
		return rest_ensure_response(
			array(
				'name'        => get_bloginfo( 'name' ),
				'home_url'    => home_url( '/' ),
				'fingerprint' => Picot_Dev_Mcp_Settings::instance()->fingerprint(),
			)
		);
	}

	/**
	 * Stream one file for a single-use token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_transfer( WP_REST_Request $request ) {
		$token = (string) $request->get_header( 'x-picot-transfer-token' );
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		$path = (string) $request->get_param( 'path' );
		$file = self::consume( $token, $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '転送ファイルを読めません。', 'picot-developer-mcp' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Picot-Sha256: ' . hash_file( 'sha256', $file ) );
		header( 'X-Robots-Tag: noindex' );
		fpassthru( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Issue a one-time token for one absolute file.
	 *
	 * @param string $absolute Absolute path inside an allowed root.
	 * @param string $session  Migration or package id.
	 * @return array|WP_Error
	 */
	public static function issue( $absolute, $session ) {
		$absolute = wp_normalize_path( (string) $absolute );
		if ( ! is_file( $absolute ) || ! self::path_is_allowed( $absolute ) ) {
			return Picot_Dev_Mcp_Errors::make( 'protected_file', __( 'このファイルは転送できません。', 'picot-developer-mcp' ) );
		}
		try {
			$plaintext = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $e ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '転送トークンを生成できません。', 'picot-developer-mcp' ) );
		}
		$relative = md5( $absolute );
		$tokens   = self::prune( self::all_tokens() );
		$tokens[] = array(
			'hash'     => hash_hmac( 'sha256', $plaintext, wp_salt( 'auth' ) ),
			'expires'  => time() + 10 * MINUTE_IN_SECONDS,
			'session'  => sanitize_text_field( $session ),
			'path_key' => $relative,
			'file'     => $absolute,
			'used'     => false,
		);
		update_option( self::OPTION, $tokens, false );
		return array(
			'token'      => $plaintext,
			'path'       => $relative,
			'expires_in' => 600,
			'endpoint'   => rest_url( 'picot-developer-mcp/v1/transfer' ),
			'sha256'     => hash_file( 'sha256', $absolute ),
			'size'       => (int) filesize( $absolute ),
		);
	}

	/**
	 * Pull a file from a registered peer. Redirects are not followed.
	 *
	 * @param string $peer_id_or_url Peer id or URL.
	 * @param string $token          Transfer token.
	 * @param string $path_key       Path key from issue().
	 * @param string $expected_hash  Expected sha256.
	 * @return string|WP_Error Absolute temp file.
	 */
	public static function pull( $peer_id_or_url, $token, $path_key, $expected_hash = '' ) {
		$peer = Picot_Dev_Mcp_Settings::instance()->find_peer( $peer_id_or_url );
		if ( ! is_array( $peer ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '登録済みPeer以外への取得は拒否しました。', 'picot-developer-mcp' ) );
		}
		$verified = self::verify_peer( $peer );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$url = add_query_arg( 'path', rawurlencode( $path_key ), self::peer_rest_url( $peer['url'], '/picot-developer-mcp/v1/transfer' ) );
		$tmp = wp_tempnam( 'picot-dev-transfer' );
		if ( ! $tmp ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '一時ファイルを作成できません。', 'picot-developer-mcp' ) );
		}
		// Stream to disk so large media do not have to fit in PHP memory.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::max_transfer_bytes(),
				'headers'             => array(
					'X-Picot-Transfer-Token' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'Peerからの転送に失敗しました。', 'picot-developer-mcp' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'Peerが転送を拒否しました。', 'picot-developer-mcp' ), array( 'status' => $code ) );
		}
		$length = (int) wp_remote_retrieve_header( $response, 'content-length' );
		if ( $length > 0 && (int) filesize( $tmp ) !== $length ) {
			wp_delete_file( $tmp );
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '転送ファイルが上限サイズを超えたか、途中で切れました。', 'picot-developer-mcp' ) );
		}
		$hash = (string) hash_file( 'sha256', $tmp );
		if ( '' !== $expected_hash && ! hash_equals( strtolower( $expected_hash ), $hash ) ) {
			wp_delete_file( $tmp );
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( '転送ファイルのハッシュが一致しません。', 'picot-developer-mcp' ) );
		}
		return $tmp;
	}

	/**
	 * REST URL on a peer. The rest_route query works with and without pretty permalinks.
	 *
	 * @param string $site_url Peer site URL.
	 * @param string $route    REST route starting with a slash.
	 * @return string
	 */
	private static function peer_rest_url( $site_url, $route ) {
		return add_query_arg( 'rest_route', $route, trailingslashit( (string) $site_url ) );
	}

	/**
	 * Largest file pulled from a peer.
	 *
	 * @return int Bytes.
	 */
	private static function max_transfer_bytes() {
		/**
		 * Filter the maximum size of one file pulled from a peer.
		 *
		 * @param int $bytes Bytes. Default 512 MB.
		 */
		return (int) apply_filters( 'picot_dev_mcp_max_transfer_bytes', 512 * MB_IN_BYTES );
	}

	/**
	 * Confirm the peer fingerprint before any file request.
	 *
	 * @param array $peer Peer row.
	 * @return true|WP_Error
	 */
	public static function verify_peer( array $peer ) {
		$expected = strtolower( (string) ( $peer['fingerprint'] ?? '' ) );
		if ( strlen( $expected ) < 16 ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'PeerのFingerprintが未設定です。', 'picot-developer-mcp' ) );
		}
		$url = self::peer_rest_url( (string) $peer['url'], '/picot-developer-mcp/v1/identity' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'Peerの識別情報を確認できません。', 'picot-developer-mcp' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$got  = is_array( $data ) ? strtolower( (string) ( $data['fingerprint'] ?? '' ) ) : '';
		if ( '' === $got || ! hash_equals( $expected, $got ) ) {
			return Picot_Dev_Mcp_Errors::make( 'migration_failed', __( 'PeerのFingerprintが一致しません。', 'picot-developer-mcp' ) );
		}
		return true;
	}

	/**
	 * Mark a token used and return the file path.
	 *
	 * @param string $plaintext Token.
	 * @param string $path_key  Path key.
	 * @return string|WP_Error
	 */
	private static function consume( $plaintext, $path_key ) {
		if ( '' === $plaintext || '' === $path_key ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( '転送トークンが不正です。', 'picot-developer-mcp' ) );
		}
		$hash   = hash_hmac( 'sha256', $plaintext, wp_salt( 'auth' ) );
		$tokens = self::prune( self::all_tokens() );
		$found  = null;
		foreach ( $tokens as $i => $token ) {
			if ( empty( $token['hash'] ) || ! hash_equals( (string) $token['hash'], $hash ) ) {
				continue;
			}
			if ( ! empty( $token['used'] ) || (int) ( $token['expires'] ?? 0 ) < time() ) {
				return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( '転送トークンは期限切れか使用済みです。', 'picot-developer-mcp' ) );
			}
			if ( (string) ( $token['path_key'] ?? '' ) !== $path_key ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '転送トークンの対象リソースが一致しません。', 'picot-developer-mcp' ) );
			}
			$file = (string) ( $token['file'] ?? '' );
			if ( ! is_file( $file ) || ! self::path_is_allowed( $file ) ) {
				return Picot_Dev_Mcp_Errors::make( 'protected_file', __( '転送ファイルは利用できません。', 'picot-developer-mcp' ) );
			}
			$tokens[ $i ]['used'] = true;
			$found = $file;
			break;
		}
		update_option( self::OPTION, $tokens, false );
		if ( ! is_string( $found ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( '転送トークンが無効です。', 'picot-developer-mcp' ) );
		}
		return $found;
	}

	/**
	 * Stored tokens.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function all_tokens() {
		$tokens = get_option( self::OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Drop expired tokens.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private static function prune( array $tokens ) {
		$now = time();
		return array_values(
			array_filter(
				$tokens,
				static function ( $token ) use ( $now ) {
					return is_array( $token ) && (int) ( $token['expires'] ?? 0 ) >= $now;
				}
			)
		);
	}

	/**
	 * Transferable locations: active theme, selected plugin, and our storage.
	 *
	 * @param string $absolute Absolute path.
	 * @return bool
	 */
	private static function path_is_allowed( $absolute ) {
		$absolute = wp_normalize_path( $absolute );
		if ( Picot_Dev_Mcp_Paths::is_protected( $absolute ) ) {
			return false;
		}
		$roots = array();
		$theme = Picot_Dev_Mcp_Paths::active_theme();
		$roots[] = $theme['root'];
		$plugin = Picot_Dev_Mcp_Paths::development_plugin();
		if ( ! is_wp_error( $plugin ) ) {
			$roots[] = $plugin['root'];
		}
		$storage = Picot_Dev_Mcp_Storage::base_dir();
		if ( ! is_wp_error( $storage ) ) {
			$roots[] = $storage;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			$roots[] = $uploads['basedir'];
		}
		foreach ( $roots as $root ) {
			$real_root = realpath( $root );
			$real_file = realpath( $absolute );
			if ( ! is_string( $real_root ) || ! is_string( $real_file ) ) {
				continue;
			}
			$real_root = wp_normalize_path( $real_root );
			$real_file = wp_normalize_path( $real_file );
			if ( $real_file === $real_root || str_starts_with( $real_file, $real_root . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
