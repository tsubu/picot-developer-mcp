<?php
/**
 * Settings storage.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Settings class.
 */
class Picot_Dev_Mcp_Settings {

	const TOKENS_OPTION = 'picot_dev_mcp_tokens';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

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
	 * Feature keys shown on the development-target screen.
	 *
	 * @return string[]
	 */
	public static function feature_keys() {
		return array(
			'plugin_dev',
			'theme_dev',
			'theme_components',
			'site_compare',
			'site_migration',
			'validation',
		);
	}

	/**
	 * Operation keys shown on the permission screen.
	 *
	 * @return string[]
	 */
	public static function operation_keys() {
		return array(
			'read_files',
			'write_files',
			'create_components',
			'validate',
			'site_compare',
			'migration',
			'delete_files',
			'production_apply',
		);
	}

	/**
	 * Safety keys.
	 *
	 * @return string[]
	 */
	public static function safety_keys() {
		return array(
			'snapshot_before_changes',
			'automatic_validation',
			'dry_run_before_migration',
			'rollback_on_fatal',
		);
	}

	/**
	 * Default settings. New installs keep the server off until a key is issued.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'               => false,
			'route_namespace'       => 'picot-developer-mcp',
			'route'                 => 'mcp',
			'plugin_slug'           => '',
			'features'              => array(
				'plugin_dev'       => true,
				'theme_dev'        => true,
				'theme_components' => true,
				'site_compare'     => true,
				'site_migration'   => true,
				'validation'       => true,
			),
			'operations'            => array(
				'read_files'         => true,
				'write_files'        => true,
				'create_components'  => true,
				'validate'           => true,
				'site_compare'       => true,
				'migration'          => true,
				'delete_files'       => false,
				'production_apply'   => false,
			),
			'safety'                => array(
				'snapshot_before_changes' => true,
				'automatic_validation'    => true,
				'dry_run_before_migration'=> true,
				'rollback_on_fatal'       => true,
			),
			'peers'                 => array(),
			'site_secret'           => '',
			'log_retention'         => 200,
			'rate_limit_per_minute' => 60,
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored   = get_option( PICOT_DEV_MCP_OPTION, array() );
		$defaults = self::defaults();
		$stored   = is_array( $stored ) ? $stored : array();
		$this->cache = wp_parse_args( $stored, $defaults );
		$this->cache['features']   = $this->bool_map( self::feature_keys(), $this->cache['features'] ?? array(), $defaults['features'] );
		$this->cache['operations'] = $this->bool_map( self::operation_keys(), $this->cache['operations'] ?? array(), $defaults['operations'] );
		$this->cache['safety']     = $this->bool_map( self::safety_keys(), $this->cache['safety'] ?? array(), $defaults['safety'] );
		$this->cache['plugin_slug'] = sanitize_key( (string) ( $this->cache['plugin_slug'] ?? '' ) );
		if ( in_array( $this->cache['plugin_slug'], Picot_Dev_Mcp_Paths::locked_plugin_slugs(), true ) ) {
			$this->cache['plugin_slug'] = '';
		}
		$this->cache['route_namespace'] = self::sanitize_segment( $this->cache['route_namespace'] ?? '', 'picot-developer-mcp' );
		$this->cache['route'] = self::sanitize_segment( $this->cache['route'] ?? '', 'mcp' );
		$this->cache['log_retention'] = max( 20, min( 1000, (int) $this->cache['log_retention'] ) );
		$this->cache['rate_limit_per_minute'] = max( 0, min( 600, (int) $this->cache['rate_limit_per_minute'] ) );
		$this->cache['peers'] = $this->sanitize_peers( $this->cache['peers'] ?? array() );
		$this->cache['enabled'] = ! empty( $this->cache['enabled'] );

		if ( ! is_string( $this->cache['site_secret'] ) || strlen( $this->cache['site_secret'] ) < 16 ) {
			try {
				$this->cache['site_secret'] = bin2hex( random_bytes( 16 ) );
			} catch ( Exception $e ) {
				$this->cache['site_secret'] = wp_generate_password( 32, false, false );
			}
			$this->persist( $this->cache );
		}

		return $this->cache;
	}

	/**
	 * Replace settings and clear the cache.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public function update( array $settings ) {
		$current = $this->all();
		$next    = wp_parse_args( $settings, $current );
		$next['site_secret'] = $current['site_secret'];
		$slug = sanitize_key( (string) ( $next['plugin_slug'] ?? '' ) );
		if ( in_array( $slug, Picot_Dev_Mcp_Paths::locked_plugin_slugs(), true ) ) {
			$slug = '';
		}
		$next['plugin_slug'] = $slug;
		$ok = $this->persist( $next );
		$this->cache = null;
		return $ok;
	}

	/**
	 * Persist raw settings.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function persist( array $settings ) {
		if ( false === get_option( PICOT_DEV_MCP_OPTION, false ) ) {
			return add_option( PICOT_DEV_MCP_OPTION, $settings, '', false );
		}
		return update_option( PICOT_DEV_MCP_OPTION, $settings, false );
	}

	/**
	 * Whether the MCP server is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->all()['enabled'] );
	}

	/**
	 * Feature flag.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function is_feature_enabled( $feature ) {
		$all = $this->all();
		return ! empty( $all['features'][ $feature ] );
	}

	/**
	 * Operation flag.
	 *
	 * @param string $operation Operation key.
	 * @return bool
	 */
	public function is_operation_enabled( $operation ) {
		$all = $this->all();
		return ! empty( $all['operations'][ $operation ] );
	}

	/**
	 * Safety flag.
	 *
	 * @param string $key Safety key.
	 * @return bool
	 */
	public function is_safety_enabled( $key ) {
		$all = $this->all();
		return ! empty( $all['safety'][ $key ] );
	}

	/**
	 * Selected development plugin slug.
	 *
	 * @return string
	 */
	public function plugin_slug() {
		return (string) $this->all()['plugin_slug'];
	}

	/**
	 * REST namespace.
	 *
	 * @return string
	 */
	public function route_namespace() {
		return (string) $this->all()['route_namespace'];
	}

	/**
	 * REST route.
	 *
	 * @return string
	 */
	public function route() {
		return (string) $this->all()['route'];
	}

	/**
	 * Requests allowed per minute for one API key. Zero disables the limit.
	 *
	 * @return int
	 */
	public function rate_limit_per_minute() {
		return (int) $this->all()['rate_limit_per_minute'];
	}

	/**
	 * Public fingerprint for peer registration.
	 *
	 * @return string
	 */
	public function fingerprint() {
		$secret = (string) $this->all()['site_secret'];
		return substr( hash_hmac( 'sha256', home_url( '/' ), $secret ), 0, 32 );
	}

	/**
	 * Registered peer sites.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function peers() {
		return $this->all()['peers'];
	}

	/**
	 * Find a peer by id or by URL host.
	 *
	 * @param string $id_or_url Peer id or URL.
	 * @return array<string, string>|null
	 */
	public function find_peer( $id_or_url ) {
		$id_or_url = (string) $id_or_url;
		$host      = wp_parse_url( $id_or_url, PHP_URL_HOST );
		foreach ( $this->peers() as $peer ) {
			if ( $peer['id'] === $id_or_url ) {
				return $peer;
			}
			if ( is_string( $host ) && '' !== $host && strtolower( (string) wp_parse_url( $peer['url'], PHP_URL_HOST ) ) === strtolower( $host ) ) {
				return $peer;
			}
		}
		return null;
	}

	/**
	 * Stored API keys (hash only).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? array_values( $tokens ) : array();
	}

	/**
	 * Replace the token list.
	 *
	 * @param array $tokens Tokens.
	 * @return bool
	 */
	public function save_tokens( array $tokens ) {
		return update_option( self::TOKENS_OPTION, array_values( $tokens ), false );
	}

	/**
	 * The single active key, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public function active_token() {
		foreach ( $this->get_tokens() as $token ) {
			if ( ! empty( $token['enabled'] ) && ! empty( $token['hash'] ) ) {
				return $token;
			}
		}
		return null;
	}

	/**
	 * Build a boolean map limited to known keys.
	 *
	 * @param string[] $keys     Allowed keys.
	 * @param mixed    $incoming Incoming map.
	 * @param array    $defaults Defaults.
	 * @return array<string, bool>
	 */
	private function bool_map( array $keys, $incoming, array $defaults ) {
		$incoming = is_array( $incoming ) ? $incoming : array();
		$clean    = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				$clean[ $key ] = ! empty( $incoming[ $key ] );
			} else {
				$clean[ $key ] = ! empty( $defaults[ $key ] );
			}
		}
		return $clean;
	}

	/**
	 * Sanitize peer rows.
	 *
	 * @param mixed $peers Raw peers.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_peers( $peers ) {
		if ( ! is_array( $peers ) ) {
			return array();
		}
		$clean = array();
		foreach ( $peers as $peer ) {
			if ( ! is_array( $peer ) ) {
				continue;
			}
			$url = isset( $peer['url'] ) ? esc_url_raw( (string) $peer['url'] ) : '';
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( ! is_string( $host ) || '' === $host || ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
				continue;
			}
			$clean[] = array(
				'id'          => sanitize_key( (string) ( $peer['id'] ?? '' ) ) ?: sanitize_title( $host ),
				'label'       => sanitize_text_field( (string) ( $peer['label'] ?? $host ) ),
				'url'         => untrailingslashit( $url ),
				'fingerprint' => strtolower( preg_replace( '/[^a-f0-9]/', '', (string) ( $peer['fingerprint'] ?? '' ) ) ),
			);
		}
		return $clean;
	}

	/**
	 * Sanitize a REST path segment.
	 *
	 * @param string $value   Raw value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function sanitize_segment( $value, $fallback ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9\-\/]/', '', $value );
		$value = trim( (string) $value, '/' );
		if ( '' === $value || strlen( $value ) > 80 ) {
			return $fallback;
		}
		return $value;
	}
}
