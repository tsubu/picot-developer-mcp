<?php
/**
 * Change transaction: snapshot, write, validate, rollback on fatal.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Change class.
 */
class Picot_Dev_Mcp_Change {

	/**
	 * Apply file operations inside the sandbox.
	 *
	 * @param array $args {
	 *     @type string $area    theme|plugin.
	 *     @type string $ability Ability alias.
	 *     @type string $action  History label.
	 *     @type array  $input   Original input, for production confirmation.
	 *     @type array  $changes List of {op,path,content|search|replace|all}.
	 * }
	 * @return array|WP_Error
	 */
	public static function apply( array $args ) {
		$area = sanitize_key( (string) ( $args['area'] ?? '' ) );
		$input = is_array( $args['input'] ?? null ) ? $args['input'] : array();
		if ( 'core' === $area ) {
			return Picot_Dev_Mcp_Errors::make( 'core_write_forbidden', __( 'WordPressコアは変更できません。', 'picot-developer-mcp' ) );
		}
		if ( ! in_array( $area, array( 'theme', 'plugin' ), true ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '変更対象は theme または plugin のみです。', 'picot-developer-mcp' ) );
		}
		$gate = Picot_Dev_Mcp_Permissions::authorize_production_write( $input, false );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		if ( 'theme' === $area ) {
			$target_info = Picot_Dev_Mcp_Paths::active_theme();
			$target      = (string) $target_info['stylesheet'];
		} else {
			$target_info = Picot_Dev_Mcp_Paths::development_plugin();
			if ( is_wp_error( $target_info ) ) {
				return $target_info;
			}
			$target = (string) $target_info['slug'];
		}

		$changes = array();
		$existing = array();
		$created  = array();
		$deleted  = array();
		foreach ( (array) ( $args['changes'] ?? array() ) as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$op = sanitize_key( (string) ( $change['op'] ?? 'write' ) );
			$resolved = 'theme' === $area
				? Picot_Dev_Mcp_Paths::resolve_theme( (string) ( $change['path'] ?? '' ), 'delete' === $op ? 'read' : 'write', 'active' )
				: Picot_Dev_Mcp_Paths::resolve_plugin( (string) ( $change['path'] ?? '' ), 'delete' === $op ? 'read' : 'write' );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$relative = Picot_Dev_Mcp_Paths::normalize_relative( (string) $change['path'] );
			if ( is_wp_error( $relative ) ) {
				return $relative;
			}
			$change['absolute'] = $resolved;
			$change['path']     = $relative;
			$change['op']       = $op;
			$changes[]          = $change;
			if ( is_file( $resolved ) ) {
				if ( 'delete' === $op ) {
					$deleted[] = $relative;
				} else {
					$existing[] = $relative;
				}
			} elseif ( 'delete' !== $op ) {
				$created[] = $relative;
			}
		}

		if ( empty( $changes ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '変更内容が空です。', 'picot-developer-mcp' ) );
		}

		$snapshot = null;
		if ( Picot_Dev_Mcp_Settings::instance()->is_safety_enabled( 'snapshot_before_changes' ) ) {
			$snapshot = Picot_Dev_Mcp_Snapshot::create(
				array(
					'area'    => $area,
					'target'  => $target,
					'files'   => $existing,
					'created' => $created,
					'deleted' => $deleted,
				)
			);
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
		}

		$log_pos = Picot_Dev_Mcp_Validation::log_offset();
		$written = array();
		foreach ( $changes as $change ) {
			$result = self::apply_one( $change );
			if ( is_wp_error( $result ) ) {
				self::maybe_rollback( $snapshot );
				return $result;
			}
			$written[] = $change['path'];
		}

		$validation = array( 'ok' => true, 'checks' => array(), 'skipped' => true );
		if ( Picot_Dev_Mcp_Settings::instance()->is_safety_enabled( 'automatic_validation' ) ) {
			$absolutes = array();
			foreach ( $changes as $change ) {
				if ( 'delete' !== $change['op'] && is_file( $change['absolute'] ) ) {
					$absolutes[] = $change['absolute'];
				}
			}
			$validation = Picot_Dev_Mcp_Validation::run( $absolutes, $log_pos, $area );
			if ( empty( $validation['ok'] ) && Picot_Dev_Mcp_Settings::instance()->is_safety_enabled( 'rollback_on_fatal' ) && is_array( $snapshot ) ) {
				$rolled = Picot_Dev_Mcp_Snapshot::rollback( $snapshot['id'] );
				return Picot_Dev_Mcp_Errors::make(
					'validation_failed',
					__( '検証に失敗したためSnapshotへ戻しました。', 'picot-developer-mcp' ),
					array(
						'snapshot'   => $snapshot['id'],
						'rolled_back'=> ! is_wp_error( $rolled ),
						'validation' => $validation,
					)
				);
			}
			if ( empty( $validation['ok'] ) ) {
				return Picot_Dev_Mcp_Errors::make(
					'validation_failed',
					__( '検証に失敗しました。', 'picot-developer-mcp' ),
					array(
						'snapshot'   => is_array( $snapshot ) ? $snapshot['id'] : '',
						'validation' => $validation,
					)
				);
			}
		}

		Picot_Dev_Mcp_Audit::history(
			array(
				'type'     => $area,
				'target'   => $target,
				'action'   => (string) ( $args['action'] ?? 'Modify' ),
				'result'   => 'success',
				'snapshot' => is_array( $snapshot ) ? $snapshot['id'] : '',
				'files'    => $written,
			)
		);

		return array(
			'target'     => $target,
			'files'      => $written,
			'snapshot'   => is_array( $snapshot ) ? $snapshot : null,
			'validation' => $validation,
		);
	}

	/**
	 * Apply one change.
	 *
	 * @param array $change Change.
	 * @return true|WP_Error
	 */
	private static function apply_one( array $change ) {
		$path = (string) $change['absolute'];
		$op   = (string) $change['op'];
		if ( 'delete' === $op ) {
			if ( ! is_file( $path ) ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( '削除するファイルが見つかりません。', 'picot-developer-mcp' ) );
			}
			wp_delete_file( $path );
			return true;
		}

		if ( 'patch' === $op ) {
			if ( ! is_file( $path ) ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'Patch対象のファイルが見つかりません。', 'picot-developer-mcp' ) );
			}
			$search = (string) ( $change['search'] ?? '' );
			if ( '' === $search ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'search が空です。', 'picot-developer-mcp' ) );
			}
			$current = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_string( $current ) || ! str_contains( $current, $search ) ) {
				return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'search に一致する箇所がありません。', 'picot-developer-mcp' ) );
			}
			$count = substr_count( $current, $search );
			if ( $count > 1 && empty( $change['all'] ) ) {
				return Picot_Dev_Mcp_Errors::make(
					'invalid_target',
					__( 'search が複数一致します。all: true を指定するか、一意になるまで search を広げてください。', 'picot-developer-mcp' ),
					array( 'matches' => $count )
				);
			}
			$replace = (string) ( $change['replace'] ?? '' );
			$next    = empty( $change['all'] ) ? self::replace_first( $current, $search, $replace ) : str_replace( $search, $replace, $current );
			return self::write_file( $path, $next );
		}

		if ( is_file( $path ) && empty( $change['overwrite'] ) && 'write' === $op ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ファイルが既に存在します。上書きするには overwrite: true を指定してください。', 'picot-developer-mcp' ) );
		}
		return self::write_file( $path, (string) ( $change['content'] ?? '' ) );
	}

	/**
	 * Replace the first occurrence only.
	 *
	 * @param string $haystack Haystack.
	 * @param string $search   Search.
	 * @param string $replace  Replace.
	 * @return string
	 */
	private static function replace_first( $haystack, $search, $replace ) {
		$pos = strpos( $haystack, $search );
		if ( false === $pos ) {
			return $haystack;
		}
		return substr( $haystack, 0, $pos ) . $replace . substr( $haystack, $pos + strlen( $search ) );
	}

	/**
	 * Write a file under an already-resolved path.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content Contents.
	 * @return true|WP_Error
	 */
	private static function write_file( $path, $content ) {
		if ( strlen( $content ) > 2 * MB_IN_BYTES ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ファイルが大きすぎます。', 'picot-developer-mcp' ) );
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return Picot_Dev_Mcp_Errors::make( 'invalid_target', __( 'ディレクトリを作成できません。', 'picot-developer-mcp' ) );
		}
		$written = Picot_Dev_Mcp_Storage::put_file( $path, $content );
		return is_wp_error( $written ) ? $written : true;
	}

	/**
	 * Roll back when a later step fails.
	 *
	 * @param array|null $snapshot Snapshot summary.
	 * @return void
	 */
	private static function maybe_rollback( $snapshot ) {
		if ( is_array( $snapshot ) && ! empty( $snapshot['id'] ) && Picot_Dev_Mcp_Settings::instance()->is_safety_enabled( 'rollback_on_fatal' ) ) {
			Picot_Dev_Mcp_Snapshot::rollback( $snapshot['id'] );
		}
	}
}
