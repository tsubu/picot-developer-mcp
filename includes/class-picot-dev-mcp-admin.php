<?php
/**
 * Settings → Picot Developer MCP.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Admin class.
 */
class Picot_Dev_Mcp_Admin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	 * Hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_picot_dev_mcp_save', array( $this, 'handle_post' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PICOT_DEV_MCP_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings submenu.
	 *
	 * @return void
	 */
	public function menu() {
		add_options_page(
			'Picot Developer MCP',
			'Picot Developer MCP',
			'manage_options',
			'picot-developer-mcp',
			array( $this, 'render' )
		);
	}

	/**
	 * Plugin list settings link.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::page_url() ),
				esc_html__( '設定', 'picot-developer-mcp' )
			)
		);
		return $links;
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $tab Optional tab.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$args = array( 'page' => 'picot-developer-mcp' );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Admin CSS and JS.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'settings_page_picot-developer-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'picot-dev-mcp-admin', PICOT_DEV_MCP_URL . 'assets/admin.css', array(), PICOT_DEV_MCP_VERSION );
		wp_enqueue_script( 'picot-dev-mcp-admin', PICOT_DEV_MCP_URL . 'assets/admin.js', array(), PICOT_DEV_MCP_VERSION, true );
	}

	/**
	 * Save settings and key actions.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'picot-developer-mcp' ) );
		}
		check_admin_referer( 'picot_dev_mcp_save' );
		$action = sanitize_key( (string) wp_unslash( $_POST['picot_dev_mcp_action'] ?? 'save' ) );
		$tab    = sanitize_key( (string) wp_unslash( $_POST['picot_dev_mcp_tab'] ?? 'connection' ) );
		$settings = Picot_Dev_Mcp_Settings::instance();

		if ( 'issue_key' === $action || 'reissue_key' === $action ) {
			$issued = Picot_Dev_Mcp_Api_Key::generate( get_current_user_id(), 'developer' );
			if ( is_wp_error( $issued ) ) {
				$this->redirect( 'keys', 'error', $issued->get_error_message() );
			}
			set_transient( 'picot_dev_mcp_key_once_' . get_current_user_id(), $issued['plaintext'], MINUTE_IN_SECONDS );
			$current = $settings->all();
			$current['enabled'] = true;
			$settings->update( $current );
			$this->redirect( 'keys', 'issued' );
		}

		if ( 'disable_key' === $action ) {
			Picot_Dev_Mcp_Api_Key::disable_all();
			$this->redirect( 'keys', 'disabled' );
		}

		if ( 'rollback' === $action ) {
			$id = sanitize_text_field( (string) wp_unslash( $_POST['snapshot_id'] ?? '' ) );
			$result = Picot_Dev_Mcp_Snapshot::rollback( $id );
			$this->redirect( 'history', is_wp_error( $result ) ? 'error' : 'rolled_back', is_wp_error( $result ) ? $result->get_error_message() : '' );
		}

		if ( 'create_plugin' === $action ) {
			$result = Picot_Dev_Mcp_Plugin_Dev::create(
				array(
					'name'        => sanitize_text_field( (string) wp_unslash( $_POST['new_plugin_name'] ?? '' ) ),
					'slug'        => sanitize_title( (string) wp_unslash( $_POST['new_plugin_slug'] ?? '' ) ),
					'description' => sanitize_text_field( (string) wp_unslash( $_POST['new_plugin_description'] ?? '' ) ),
					'namespace'   => sanitize_text_field( (string) wp_unslash( $_POST['new_plugin_namespace'] ?? '' ) ),
					'text_domain' => sanitize_title( (string) wp_unslash( $_POST['new_plugin_text_domain'] ?? '' ) ),
					'confirm_production' => ! empty( $_POST['confirm_production'] ),
				)
			);
			$this->redirect( 'target', is_wp_error( $result ) ? 'error' : 'plugin_created', is_wp_error( $result ) ? $result->get_error_message() : '' );
		}

		$current = $settings->all();
		if ( 'connection' === $tab ) {
			$current['enabled'] = ! empty( $_POST['enabled'] );
			if ( isset( $_POST['peer_url'] ) ) {
				$current['peers'] = $this->read_peers();
			}
		} elseif ( 'target' === $tab ) {
			$current['features'] = $this->checked_map( Picot_Dev_Mcp_Settings::feature_keys() );
			$slug = sanitize_key( (string) wp_unslash( $_POST['plugin_slug'] ?? '' ) );
			if ( in_array( $slug, Picot_Dev_Mcp_Paths::locked_plugin_slugs(), true ) ) {
				$slug = '';
			}
			$current['plugin_slug'] = $slug;
		} elseif ( 'safety' === $tab ) {
			$current['operations'] = $this->checked_map( Picot_Dev_Mcp_Settings::operation_keys() );
			$current['safety'] = $this->checked_map( Picot_Dev_Mcp_Settings::safety_keys() );
			if ( isset( $_POST['route_namespace'] ) || isset( $_POST['route'] ) ) {
				$current['route_namespace'] = Picot_Dev_Mcp_Settings::sanitize_segment(
					(string) wp_unslash( $_POST['route_namespace'] ?? '' ),
					'picot-developer-mcp'
				);
				$current['route'] = Picot_Dev_Mcp_Settings::sanitize_segment(
					(string) wp_unslash( $_POST['route'] ?? '' ),
					'mcp'
				);
			}
		}
		$settings->update( $current );
		$this->redirect( $tab, 'saved' );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'connection' ) );
		if ( ! in_array( $tab, array( 'connection', 'keys', 'target', 'safety', 'history' ), true ) ) {
			$tab = 'connection';
		}
		$tabs = array(
			'connection' => __( '接続', 'picot-developer-mcp' ),
			'keys'       => __( 'APIキー', 'picot-developer-mcp' ),
			'target'     => __( '開発対象', 'picot-developer-mcp' ),
			'safety'     => __( '権限・安全設定', 'picot-developer-mcp' ),
			'history'    => __( '履歴・Snapshot', 'picot-developer-mcp' ),
		);
		echo '<div class="wrap picot-dev-mcp">';
		echo '<h1>Picot Developer MCP</h1>';
		if ( Picot_Dev_Mcp_Permissions::is_production() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Production Environment', 'picot-developer-mcp' ) . '</strong> ';
			echo esc_html__( '本番環境です。直接反映は初期状態で無効です。', 'picot-developer-mcp' ) . '</p></div>';
		}
		$this->notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			$url = self::page_url( $id );
			echo '<a class="nav-tab' . ( $tab === $id ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'history' === $tab ) {
			$this->render_history();
		} elseif ( 'target' === $tab ) {
			$this->render_target();
		} elseif ( 'safety' === $tab ) {
			$this->render_safety();
		} elseif ( 'keys' === $tab ) {
			$this->render_keys();
		} else {
			$this->render_connection();
		}
		echo '</div>';
	}

	/**
	 * Connection tab.
	 *
	 * @return void
	 */
	private function render_connection() {
		$settings = Picot_Dev_Mcp_Settings::instance()->all();
		$preview  = Picot_Dev_Mcp_Server::endpoint_url();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="connection" />';
		echo '<input type="hidden" name="picot_dev_mcp_action" value="save" />';
		echo '<div class="picot-dev-card">';
		echo '<h2>MCP Server</h2>';
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> ' . esc_html__( '有効', 'picot-developer-mcp' ) . '</label></p>';
		echo '<p><strong>MCP URL</strong><br /><code id="picot-dev-mcp-url">' . esc_html( $preview ) . '</code> <button type="button" class="button" data-copy="picot-dev-mcp-url">' . esc_html__( 'コピー', 'picot-developer-mcp' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'エンドポイントパスの変更は「権限・安全設定」タブで行います。', 'picot-developer-mcp' ) . ' ';
		echo '<a href="' . esc_url( self::page_url( 'safety' ) ) . '">' . esc_html__( '安全設定を開く', 'picot-developer-mcp' ) . '</a></p>';
		echo '<p class="description">Fingerprint: <code id="picot-dev-mcp-fingerprint">' . esc_html( Picot_Dev_Mcp_Settings::instance()->fingerprint() ) . '</code> <button type="button" class="button" data-copy="picot-dev-mcp-fingerprint">' . esc_html__( 'コピー', 'picot-developer-mcp' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'APIキーの発行・無効化は「APIキー」タブで行います。', 'picot-developer-mcp' ) . ' ';
		echo '<a href="' . esc_url( self::page_url( 'keys' ) ) . '">' . esc_html__( 'APIキータブを開く', 'picot-developer-mcp' ) . '</a></p>';
		submit_button( __( '接続設定を保存', 'picot-developer-mcp' ) );
		echo '</div></form>';

		$this->render_peers( $settings['peers'] );
	}

	/**
	 * API key management tab.
	 *
	 * @return void
	 */
	private function render_keys() {
		$token = Picot_Dev_Mcp_Settings::instance()->active_token();
		$once  = get_transient( 'picot_dev_mcp_key_once_' . get_current_user_id() );
		if ( is_string( $once ) && '' !== $once ) {
			delete_transient( 'picot_dev_mcp_key_once_' . get_current_user_id() );
		} else {
			$once = '';
		}

		echo '<div class="picot-dev-card"><h2>' . esc_html__( '現在のAPIキー', 'picot-developer-mcp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '平文は発行時だけ表示します。保存されるのはハッシュのみです。再発行すると以前のキーは無効になります。', 'picot-developer-mcp' ) . '</p>';
		if ( '' !== $once ) {
			echo '<p><strong>' . esc_html__( '新しいAPIキー', 'picot-developer-mcp' ) . '</strong><br />';
			echo '<code id="picot-dev-mcp-key">' . esc_html( $once ) . '</code> ';
			echo '<button type="button" class="button" data-copy="picot-dev-mcp-key">' . esc_html__( 'コピー', 'picot-developer-mcp' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'この値は今だけ表示されます。クライアントへ控えてください。', 'picot-developer-mcp' ) . '</p>';
		} else {
			echo '<p><code>' . esc_html( $token ? Picot_Dev_Mcp_Api_Key::masked_display( $token ) : __( '未発行', 'picot-developer-mcp' ) ) . '</code></p>';
		}
		if ( $token ) {
			$user = get_user_by( 'id', (int) ( $token['user_id'] ?? 0 ) );
			echo '<table class="widefat striped" style="max-width:640px"><tbody>';
			echo '<tr><th>' . esc_html__( '状態', 'picot-developer-mcp' ) . '</th><td>' . esc_html__( '有効', 'picot-developer-mcp' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( '所有者', 'picot-developer-mcp' ) . '</th><td>' . esc_html( $user ? $user->user_login : '-' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( '発行日時', 'picot-developer-mcp' ) . '</th><td>' . esc_html( ! empty( $token['created_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $token['created_at'] ) : '-' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( '最終利用', 'picot-developer-mcp' ) . '</th><td>' . esc_html( ! empty( $token['last_used_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $token['last_used_at'] ) : __( '未使用', 'picot-developer-mcp' ) ) . '</td></tr>';
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="picot-dev-inline">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="keys" />';
		echo '<button class="button button-primary" name="picot_dev_mcp_action" value="issue_key">' . esc_html__( 'APIキー発行', 'picot-developer-mcp' ) . '</button> ';
		echo '<button class="button" name="picot_dev_mcp_action" value="reissue_key">' . esc_html__( '再発行', 'picot-developer-mcp' ) . '</button> ';
		echo '<button class="button" name="picot_dev_mcp_action" value="disable_key">' . esc_html__( '無効化', 'picot-developer-mcp' ) . '</button>';
		echo '</form>';

		$tokens = Picot_Dev_Mcp_Settings::instance()->get_tokens();
		echo '<div class="picot-dev-card"><h2>' . esc_html__( 'キー履歴', 'picot-developer-mcp' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'プレフィックス', 'picot-developer-mcp' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'picot-developer-mcp' ) . '</th>';
		echo '<th>' . esc_html__( '所有者', 'picot-developer-mcp' ) . '</th>';
		echo '<th>' . esc_html__( '発行日時', 'picot-developer-mcp' ) . '</th>';
		echo '<th>' . esc_html__( '最終利用', 'picot-developer-mcp' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $tokens ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'キーはまだありません。', 'picot-developer-mcp' ) . '</td></tr>';
		} else {
			$rows = array_reverse( array_values( $tokens ) );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$user = get_user_by( 'id', (int) ( $row['user_id'] ?? 0 ) );
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['prefix'] ?? '' ) ) . '••••</code></td>';
				echo '<td>' . esc_html( ! empty( $row['enabled'] ) ? __( '有効', 'picot-developer-mcp' ) : __( '無効', 'picot-developer-mcp' ) ) . '</td>';
				echo '<td>' . esc_html( $user ? $user->user_login : '-' ) . '</td>';
				echo '<td>' . esc_html( ! empty( $row['created_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $row['created_at'] ) : '-' ) . '</td>';
				echo '<td>' . esc_html( ! empty( $row['last_used_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $row['last_used_at'] ) : '—' ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Peer list on the connection tab.
	 *
	 * @param array $peers Peers.
	 * @return void
	 */
	private function render_peers( array $peers ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="connection" />';
		echo '<input type="hidden" name="picot_dev_mcp_action" value="save" />';
		echo '<input type="hidden" name="enabled" value="' . ( Picot_Dev_Mcp_Settings::instance()->is_enabled() ? '1' : '0' ) . '" />';
		echo '<div class="picot-dev-card"><h2>' . esc_html__( '接続済み Site', 'picot-developer-mcp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'ファイルの直接転送は、ここで登録した Peer だけが対象です。任意URLの取得はしません。', 'picot-developer-mcp' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Label</th><th>URL</th><th>Fingerprint</th></tr></thead><tbody>';
		$rows = array_values( $peers );
		$rows[] = array( 'label' => '', 'url' => '', 'fingerprint' => '' );
		foreach ( $rows as $i => $peer ) {
			echo '<tr>';
			echo '<td><input name="peer_label[]" value="' . esc_attr( (string) ( $peer['label'] ?? '' ) ) . '" /></td>';
			echo '<td><input class="regular-text" name="peer_url[]" value="' . esc_attr( (string) ( $peer['url'] ?? '' ) ) . '" placeholder="https://example.com" /></td>';
			echo '<td><input name="peer_fingerprint[]" value="' . esc_attr( (string) ( $peer['fingerprint'] ?? '' ) ) . '" /></td>';
			echo '</tr>';
			unset( $i );
		}
		echo '</tbody></table>';
		submit_button( __( 'Peerを保存', 'picot-developer-mcp' ) );
		echo '</div></form>';
	}

	/**
	 * Development target tab.
	 *
	 * @return void
	 */
	private function render_target() {
		$settings = Picot_Dev_Mcp_Settings::instance()->all();
		$theme = Picot_Dev_Mcp_Paths::active_theme();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="target" />';
		echo '<input type="hidden" name="picot_dev_mcp_action" value="save" />';
		echo '<div class="picot-dev-card"><h2>' . esc_html__( '現在の開発対象テーマ', 'picot-developer-mcp' ) . '</h2>';
		echo '<p><strong>' . esc_html( (string) $theme['name'] ) . '</strong><br />' . esc_html( (string) $theme['stylesheet'] ) . '</p>';
		echo '<p>' . esc_html( (string) $theme['root'] ) . '</p>';
		echo '<p>' . ( ! empty( $theme['writable'] ) ? '● ' . esc_html__( '書き込み可能', 'picot-developer-mcp' ) : esc_html__( '書き込み不可', 'picot-developer-mcp' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'テーマの切り替えはこのプラグインでは行いません。有効なテーマだけが対象です。', 'picot-developer-mcp' ) . '</p>';
		if ( ! empty( $theme['is_child'] ) ) {
			echo '<p>' . esc_html__( '親テーマは読み取り専用です。', 'picot-developer-mcp' ) . ' ' . esc_html( (string) $theme['parent_name'] ) . '</p>';
		}
		echo '</div>';

		echo '<div class="picot-dev-card"><h2>' . esc_html__( 'Plugin開発対象', 'picot-developer-mcp' ) . '</h2>';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		echo '<select name="plugin_slug">';
		echo '<option value="">' . esc_html__( '選択してください', 'picot-developer-mcp' ) . '</option>';
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}
			if ( in_array( $slug, Picot_Dev_Mcp_Paths::locked_plugin_slugs(), true ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $settings['plugin_slug'], $slug, false ) . '>' . esc_html( (string) ( $data['Name'] ?? $slug ) ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $settings['plugin_slug'] ) {
			echo '<p><code>' . esc_html( WP_PLUGIN_DIR . '/' . $settings['plugin_slug'] . '/' ) . '</code></p>';
		}
		echo '</div>';

		echo '<div class="picot-dev-card"><h2>' . esc_html__( '利用するDeveloper機能', 'picot-developer-mcp' ) . '</h2>';
		$labels = array(
			'plugin_dev'       => __( 'プラグイン開発', 'picot-developer-mcp' ),
			'theme_dev'        => __( 'テーマ開発', 'picot-developer-mcp' ),
			'theme_components' => __( 'Theme Components', 'picot-developer-mcp' ),
			'site_compare'     => __( 'サイト比較', 'picot-developer-mcp' ),
			'site_migration'   => __( 'サイト間移植', 'picot-developer-mcp' ),
			'validation'       => __( '開発検証', 'picot-developer-mcp' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<label class="picot-dev-check"><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $settings['features'][ $key ] ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		submit_button( __( '開発対象を保存', 'picot-developer-mcp' ) );
		echo '</div></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="target" />';
		echo '<input type="hidden" name="picot_dev_mcp_action" value="create_plugin" />';
		echo '<div class="picot-dev-card"><h2>' . esc_html__( '新規Pluginを作成', 'picot-developer-mcp' ) . '</h2>';
		echo '<p><label>Plugin Name<br /><input class="regular-text" name="new_plugin_name" required /></label></p>';
		echo '<p><label>Plugin Slug<br /><input class="regular-text" name="new_plugin_slug" required /></label></p>';
		echo '<p><label>Description<br /><input class="regular-text" name="new_plugin_description" /></label></p>';
		echo '<p><label>Namespace<br /><input class="regular-text" name="new_plugin_namespace" placeholder="My_Plugin" /></label></p>';
		echo '<p><label>Text Domain<br /><input class="regular-text" name="new_plugin_text_domain" /></label></p>';
		if ( Picot_Dev_Mcp_Permissions::is_production() ) {
			echo '<label><input type="checkbox" name="confirm_production" value="1" /> ' . esc_html__( 'Productionへの作成を確認する', 'picot-developer-mcp' ) . '</label>';
		}
		submit_button( __( 'スキャフォールドを作成', 'picot-developer-mcp' ) );
		echo '</div></form>';
	}

	/**
	 * Permissions and safety.
	 *
	 * @return void
	 */
	private function render_safety() {
		$settings = Picot_Dev_Mcp_Settings::instance()->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'picot_dev_mcp_save' );
		echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
		echo '<input type="hidden" name="picot_dev_mcp_tab" value="safety" />';
		echo '<input type="hidden" name="picot_dev_mcp_action" value="save" />';

		echo '<div class="picot-dev-card"><h2>' . esc_html__( 'エンドポイントパス', 'picot-developer-mcp' ) . '</h2>';
		echo '<p>';
		echo '<code>' . esc_html( untrailingslashit( rest_url() ) ) . '/</code> ';
		echo '<input type="text" name="route_namespace" value="' . esc_attr( (string) $settings['route_namespace'] ) . '" class="regular-text" style="width:14em" pattern="[a-z0-9\-_\/]+" required />';
		echo ' <code>/</code> ';
		echo '<input type="text" name="route" value="' . esc_attr( (string) $settings['route'] ) . '" class="regular-text" style="width:8em" pattern="[a-z0-9\-_\/]+" required />';
		echo '</p>';
		echo '<p class="description">' . esc_html__( '英小文字・数字・ハイフン・アンダースコアのみ。変更すると既存クライアントの接続先が変わります。外部ドメインは指定できません（このサイトの REST API パスのみ）。', 'picot-developer-mcp' ) . '</p>';
		echo '<p><strong>MCP URL</strong><br /><code id="picot-dev-mcp-url-safety">' . esc_html( Picot_Dev_Mcp_Server::endpoint_url() ) . '</code> <button type="button" class="button" data-copy="picot-dev-mcp-url-safety">' . esc_html__( 'コピー', 'picot-developer-mcp' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( '保存後に表示が更新されます。', 'picot-developer-mcp' ) . '</p>';
		echo '</div>';

		$ops = array(
			'read_files'        => array(
				'label' => __( 'ファイル読み取り', 'picot-developer-mcp' ),
				'desc'  => __( '有効テーマ・開発対象プラグインのファイル一覧・読取・検索、Snapshot／サイト情報の参照を許可します。オフにすると調査系の操作が拒否されます。', 'picot-developer-mcp' ),
			),
			'write_files'       => array(
				'label' => __( 'ファイル作成・編集', 'picot-developer-mcp' ),
				'desc'  => __( 'ファイルの新規作成・Patch・上書きを許可します。プラグイン作成やテーマ／プラグインの編集に必要です。', 'picot-developer-mcp' ),
			),
			'create_components' => array(
				'label' => __( 'Component追加', 'picot-developer-mcp' ),
				'desc'  => __( 'テーマの template / pattern など、プラグインの CPT・REST・ショートコードなどのコンポーネント雛形追加を許可します。', 'picot-developer-mcp' ),
			),
			'validate'          => array(
				'label' => __( 'Plugin / Theme検証', 'picot-developer-mcp' ),
				'desc'  => __( 'php -l や theme.json・ヘッダ確認などの検証実行を許可します。自動検証（Safety）とは別に、明示的な検証 Ability に使います。', 'picot-developer-mcp' ),
			),
			'site_compare'      => array(
				'label' => __( 'Site Compare', 'picot-developer-mcp' ),
				'desc'  => __( 'テーマ・プラグイン・設定・環境などを相手サイトの情報と比較する操作を許可します。書き込みはしません。', 'picot-developer-mcp' ),
			),
			'migration'         => array(
				'label' => __( 'Migration', 'picot-developer-mcp' ),
				'desc'  => __( 'サイト間のエクスポート／インポート（設定・ファイル・投稿など）と Migration の履歴・Rollback を許可します。', 'picot-developer-mcp' ),
			),
			'delete_files'      => array(
				'label' => __( 'ファイル削除', 'picot-developer-mcp' ),
				'desc'  => __( '開発対象内のファイル削除やテーマ Component 削除を許可します。初期状態ではオフです。誤削除に注意してください。', 'picot-developer-mcp' ),
			),
			'production_apply'  => array(
				'label' => __( 'Productionへの直接反映', 'picot-developer-mcp' ),
				'desc'  => __( '環境種別が production のとき、書き込み系操作を許可します。加えて各操作で confirm_production: true が必要です。初期状態ではオフです。', 'picot-developer-mcp' ),
			),
		);
		echo '<div class="picot-dev-card"><h2>Developer Permissions</h2>';
		echo '<p class="description">' . esc_html__( 'オンにした操作だけが MCP から実行できます。オフの操作は permission_denied / feature_disabled 相当で拒否されます。', 'picot-developer-mcp' ) . '</p>';
		foreach ( $ops as $key => $item ) {
			echo '<div class="picot-dev-check">';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $settings['operations'][ $key ] ), true, false ) . ' /> <strong>' . esc_html( $item['label'] ) . '</strong></label>';
			echo '<p class="description">' . esc_html( $item['desc'] ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		$safety = array(
			'snapshot_before_changes' => array(
				'label' => __( '変更前Snapshot', 'picot-developer-mcp' ),
				'desc'  => __( 'ファイル変更の直前に Snapshot を自動作成します。オフにすると変更は速くなりますが、Rollback 用の控えが残りません。', 'picot-developer-mcp' ),
			),
			'automatic_validation'    => array(
				'label' => __( '自動検証', 'picot-developer-mcp' ),
				'desc'  => __( '書き込み後に PHP 構文・JSON などを自動チェックします。オフにすると壊れたファイルもそのまま残る可能性があります。', 'picot-developer-mcp' ),
			),
			'dry_run_before_migration'=> array(
				'label' => __( '移植前にDry Run', 'picot-developer-mcp' ),
				'desc'  => __( 'Migration の本適用に confirm_apply: true を必須にします。オフにすると dry_run: false だけで本適用できます。', 'picot-developer-mcp' ),
			),
			'rollback_on_fatal'       => array(
				'label' => __( 'Fatal Error時にRollback', 'picot-developer-mcp' ),
				'desc'  => __( '自動検証に失敗したとき、直前の Snapshot へ自動で戻します。「変更前Snapshot」がオンである必要があります。', 'picot-developer-mcp' ),
			),
		);
		echo '<div class="picot-dev-card"><h2>Safety</h2>';
		echo '<p class="description">' . esc_html__( '開発時の安全装置です。本番や共同作業ではオンのままを推奨します。', 'picot-developer-mcp' ) . '</p>';
		foreach ( $safety as $key => $item ) {
			echo '<div class="picot-dev-check">';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $settings['safety'][ $key ] ), true, false ) . ' /> <strong>' . esc_html( $item['label'] ) . '</strong></label>';
			echo '<p class="description">' . esc_html( $item['desc'] ) . '</p>';
			echo '</div>';
		}
		submit_button( __( '安全設定を保存', 'picot-developer-mcp' ) );
		echo '</div></form>';
	}

	/**
	 * History and snapshots.
	 *
	 * @return void
	 */
	private function render_history() {
		$history = Picot_Dev_Mcp_Audit::list_rows( 'history', 30 );
		$snaps = Picot_Dev_Mcp_Snapshot::list_snapshots( 30 );
		echo '<div class="picot-dev-card"><h2>' . esc_html__( '開発履歴', 'picot-developer-mcp' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Type</th><th>Target</th><th>Action</th><th>Result</th><th>Snapshot</th></tr></thead><tbody>';
		if ( empty( $history['items'] ) ) {
			echo '<tr><td colspan="6">' . esc_html__( '履歴はまだありません。', 'picot-developer-mcp' ) . '</td></tr>';
		}
		foreach ( $history['items'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['target'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['action'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['result'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['snapshot'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="picot-dev-card"><h2>Snapshot</h2>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Target</th><th>Files</th><th></th></tr></thead><tbody>';
		if ( empty( $snaps['items'] ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Snapshotはまだありません。', 'picot-developer-mcp' ) . '</td></tr>';
		}
		foreach ( $snaps['items'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['area'] ?? '' ) . ' / ' . (string) ( $row['target'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_slice( (array) ( $row['files'] ?? array() ), 0, 4 ) ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'picot_dev_mcp_save' );
			echo '<input type="hidden" name="action" value="picot_dev_mcp_save" />';
			echo '<input type="hidden" name="picot_dev_mcp_tab" value="history" />';
			echo '<input type="hidden" name="picot_dev_mcp_action" value="rollback" />';
			echo '<input type="hidden" name="snapshot_id" value="' . esc_attr( (string) ( $row['id'] ?? '' ) ) . '" />';
			echo '<button class="button">' . esc_html__( 'Rollback', 'picot-developer-mcp' ) . '</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Admin notice from the query string.
	 *
	 * @return void
	 */
	private function notice() {
		$status = sanitize_key( (string) ( $_GET['picot_dev_notice'] ?? '' ) );
		if ( '' === $status ) {
			return;
		}
		$messages = array(
			'saved'          => __( '保存しました。', 'picot-developer-mcp' ),
			'issued'         => __( 'APIキーを発行しました。この画面だけで完全な値を表示します。', 'picot-developer-mcp' ),
			'disabled'       => __( 'APIキーを無効化しました。', 'picot-developer-mcp' ),
			'rolled_back'    => __( 'Snapshotを復元しました。', 'picot-developer-mcp' ),
			'plugin_created' => __( 'プラグインを作成し、開発対象に設定しました。', 'picot-developer-mcp' ),
			'error'          => sanitize_text_field( (string) ( $_GET['picot_dev_detail'] ?? '' ) ),
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}
		$class = 'error' === $status ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $status ] ) . '</p></div>';
	}

	/**
	 * Redirect back to a tab.
	 *
	 * @param string $tab    Tab.
	 * @param string $notice Notice code.
	 * @param string $detail Optional detail.
	 * @return void
	 */
	private function redirect( $tab, $notice, $detail = '' ) {
		$url = add_query_arg(
			array(
				'tab'              => $tab,
				'picot_dev_notice' => $notice,
				'picot_dev_detail' => $detail,
			),
			self::page_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Checkbox map for known keys.
	 *
	 * @param string[] $keys Keys.
	 * @return array<string, bool>
	 */
	private function checked_map( array $keys ) {
		$map = array();
		foreach ( $keys as $key ) {
			$map[ $key ] = ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $map;
	}

	/**
	 * Peer rows from the connection form.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function read_peers() {
		$labels = isset( $_POST['peer_label'] ) ? (array) wp_unslash( $_POST['peer_label'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$urls = isset( $_POST['peer_url'] ) ? (array) wp_unslash( $_POST['peer_url'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prints = isset( $_POST['peer_fingerprint'] ) ? (array) wp_unslash( $_POST['peer_fingerprint'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$peers = array();
		foreach ( $urls as $i => $url ) {
			if ( '' === trim( (string) $url ) ) {
				continue;
			}
			$peers[] = array(
				'id'          => sanitize_title( (string) ( $labels[ $i ] ?? '' ) ) ?: 'peer-' . ( $i + 1 ),
				'label'       => (string) ( $labels[ $i ] ?? '' ),
				'url'         => (string) $url,
				'fingerprint' => (string) ( $prints[ $i ] ?? '' ),
			);
		}
		return $peers;
	}
}
