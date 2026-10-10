<?php
/**
 * Single API key. Plaintext is shown once and only a hash is stored.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Api_Key class.
 */
class Picot_Dev_Mcp_Api_Key {

	const PREFIX = 'pmcp_dev_';

	/**
	 * Issue a new key for a user and disable previous keys.
	 *
	 * @param int    $user_id User ID.
	 * @param string $label   Label.
	 * @return array{plaintext:string,token:array}|WP_Error
	 */
	public static function generate( $user_id, $label = '' ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーを発行するユーザーが無効です。', 'picot-developer-mcp' ) );
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( 'APIキーの所有者は管理者である必要があります。', 'picot-developer-mcp' ) );
		}

		try {
			$random = bin2hex( random_bytes( 24 ) );
		} catch ( Exception $e ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーを生成できません。', 'picot-developer-mcp' ) );
		}

		$plaintext = self::PREFIX . $random;
		$token     = array(
			'token_id'     => wp_generate_uuid4(),
			'hash'         => self::hash( $plaintext ),
			'prefix'       => self::PREFIX . substr( $random, 0, 4 ),
			'user_id'      => $user_id,
			'label'        => sanitize_text_field( $label ),
			'enabled'      => true,
			'created_at'   => time(),
			'last_used_at' => null,
		);

		$tokens = array();
		foreach ( Picot_Dev_Mcp_Settings::instance()->get_tokens() as $existing ) {
			if ( ! is_array( $existing ) ) {
				continue;
			}
			$existing['enabled'] = false;
			unset( $existing['secret'], $existing['plaintext'] );
			$tokens[] = $existing;
		}
		$tokens[] = $token;

		if ( ! Picot_Dev_Mcp_Settings::instance()->save_tokens( $tokens ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーを保存できません。', 'picot-developer-mcp' ) );
		}

		return array(
			'plaintext' => $plaintext,
			'token'     => $token,
		);
	}

	/**
	 * Disable every key.
	 *
	 * @return bool
	 */
	public static function disable_all() {
		$tokens = Picot_Dev_Mcp_Settings::instance()->get_tokens();
		foreach ( $tokens as $i => $token ) {
			$tokens[ $i ]['enabled'] = false;
			unset( $tokens[ $i ]['secret'], $tokens[ $i ]['plaintext'] );
		}
		return Picot_Dev_Mcp_Settings::instance()->save_tokens( $tokens );
	}

	/**
	 * HMAC hash. Not reversible.
	 *
	 * @param string $plaintext Plaintext key.
	 * @return string
	 */
	public static function hash( $plaintext ) {
		return hash_hmac( 'sha256', (string) $plaintext, wp_salt( 'auth' ) );
	}

	/**
	 * Verify a bearer key.
	 *
	 * @param string $plaintext Plaintext.
	 * @return array|WP_Error
	 */
	public static function verify( $plaintext ) {
		if ( ! is_string( $plaintext ) || ! str_starts_with( $plaintext, self::PREFIX ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーが無効です。', 'picot-developer-mcp' ) );
		}

		$candidate = self::hash( $plaintext );
		$matched   = null;
		foreach ( Picot_Dev_Mcp_Settings::instance()->get_tokens() as $token ) {
			if ( empty( $token['enabled'] ) || empty( $token['hash'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $token['hash'], $candidate ) ) {
				$matched = $token;
				break;
			}
		}

		if ( ! is_array( $matched ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーが無効です。', 'picot-developer-mcp' ) );
		}

		$user = get_user_by( 'id', (int) $matched['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return Picot_Dev_Mcp_Errors::make( 'authentication_failed', __( 'APIキーの所有者が管理者ではありません。', 'picot-developer-mcp' ) );
		}

		$rate = self::check_rate_limit( $matched );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		self::touch( (string) $matched['token_id'] );
		return $matched;
	}

	/**
	 * Per-key rate limit.
	 *
	 * @param array $token Token.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( array $token ) {
		$limit = Picot_Dev_Mcp_Settings::instance()->rate_limit_per_minute();
		if ( $limit < 1 || empty( $token['token_id'] ) ) {
			return true;
		}
		$key   = 'picot_dev_mcp_rl_' . md5( (string) $token['token_id'] );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return Picot_Dev_Mcp_Errors::make( 'permission_denied', __( 'APIキーのレート制限を超えました。', 'picot-developer-mcp' ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Update last_used_at at most once a minute.
	 *
	 * @param string $token_id Token id.
	 * @return void
	 */
	private static function touch( $token_id ) {
		$tokens = Picot_Dev_Mcp_Settings::instance()->get_tokens();
		$now    = time();
		$dirty  = false;
		foreach ( $tokens as $i => $token ) {
			if ( ( $token['token_id'] ?? '' ) !== $token_id ) {
				continue;
			}
			$last = isset( $token['last_used_at'] ) ? (int) $token['last_used_at'] : 0;
			if ( $last > 0 && ( $now - $last ) < 60 ) {
				return;
			}
			$tokens[ $i ]['last_used_at'] = $now;
			$dirty = true;
			break;
		}
		if ( $dirty ) {
			Picot_Dev_Mcp_Settings::instance()->save_tokens( $tokens );
		}
	}

	/**
	 * Masked display.
	 *
	 * @param array|null $token Token.
	 * @return string
	 */
	public static function masked_display( $token ) {
		if ( empty( $token['prefix'] ) ) {
			return '';
		}
		return $token['prefix'] . str_repeat( '•', 16 );
	}
}
