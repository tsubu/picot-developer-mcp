<?php
/**
 * Post, term, and media migration helpers.
 *
 * @package Picot_Developer_Mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picot_Dev_Mcp_Content class.
 */
class Picot_Dev_Mcp_Content {

	/**
	 * Meta keys that are never exported.
	 *
	 * @var string[]
	 */
	const SKIP_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_encloseme',
	);

	/**
	 * Export posts and pages with related terms and media descriptors.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function export_posts( array $input ) {
		$types = array();
		$requested = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array( 'post', 'page' );
		foreach ( $requested as $type ) {
			$type = sanitize_key( (string) $type );
			if ( in_array( $type, array( 'post', 'page' ), true ) ) {
				$types[] = $type;
			}
		}
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}
		$limit  = max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$args   = array(
			'post_type'      => $types,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( ! empty( $input['ids'] ) && is_array( $input['ids'] ) ) {
			$args['post__in'] = array_map( 'absint', $input['ids'] );
			$args['posts_per_page'] = count( $args['post__in'] );
			unset( $args['offset'] );
		}
		$query = new WP_Query( $args );
		$posts = array();
		$terms = array();
		$media = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$row = self::export_post( $post );
			$posts[] = $row;
			foreach ( $row['terms'] as $taxonomy => $slugs ) {
				foreach ( $slugs as $slug ) {
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( $term instanceof WP_Term ) {
						$terms[ $taxonomy . ':' . $slug ] = array(
							'taxonomy'    => $taxonomy,
							'slug'        => $term->slug,
							'name'        => $term->name,
							'description' => $term->description,
							'parent_slug' => $term->parent ? (string) get_term_field( 'slug', (int) $term->parent, $taxonomy ) : '',
							'source_id'   => (int) $term->term_id,
						);
					}
				}
			}
			if ( ! empty( $input['include_media'] ) ) {
				foreach ( $row['media_ids'] as $media_id ) {
					$item = self::export_media( (int) $media_id );
					if ( is_array( $item ) ) {
						$media[ (string) $item['source_id'] ] = $item;
					}
				}
			}
		}
		return array(
			'posts' => $posts,
			'terms' => array_values( $terms ),
			'media' => array_values( $media ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Export one post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function export_post( WP_Post $post ) {
		$terms = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$found = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			$terms[ $taxonomy ] = is_wp_error( $found ) ? array() : array_values( $found );
		}
		$meta = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( self::skip_meta_key( (string) $key ) ) {
				continue;
			}
			$meta[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		$media_ids = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb > 0 ) {
			$media_ids[] = $thumb;
		}
		$media_ids = array_merge( $media_ids, self::media_ids_in_content( $post->post_content ) );
		$author = get_userdata( (int) $post->post_author );
		$parent_slug = '';
		if ( $post->post_parent ) {
			$parent = get_post( (int) $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				$parent_slug = $parent->post_name;
			}
		}
		return array(
			'source_id'   => (int) $post->ID,
			'type'        => $post->post_type,
			'slug'        => $post->post_name,
			'status'      => $post->post_status,
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => $post->post_excerpt,
			'date_gmt'    => $post->post_date_gmt,
			'menu_order'  => (int) $post->menu_order,
			'parent_slug' => $parent_slug,
			'template'    => get_page_template_slug( $post ),
			'author_login'=> $author instanceof WP_User ? $author->user_login : '',
			'terms'       => $terms,
			'meta'        => $meta,
			'featured_id' => $thumb,
			'media_ids'   => array_values( array_unique( array_map( 'intval', $media_ids ) ) ),
			'blocks'      => self::block_names( $post->post_content ),
			'hash'        => hash( 'sha256', $post->post_title . $post->post_content . $post->post_excerpt ),
		);
	}

	/**
	 * Media descriptor. File bytes are not inlined.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	public static function export_media( $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$file = get_attached_file( $id );
		if ( ! is_string( $file ) || ! is_file( $file ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$relative = '';
		if ( ! empty( $uploads['basedir'] ) ) {
			$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
			$path = wp_normalize_path( $file );
			if ( str_starts_with( $path, $base ) ) {
				$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
			}
		}
		$hash = hash_file( 'sha256', $file );
		update_post_meta( $id, '_picot_dev_mcp_sha256', $hash );
		$transfer = Picot_Dev_Mcp_Transfer::issue( $file, 'media:' . $id );
		return array(
			'source_id'  => (int) $id,
			'filename'   => basename( $file ),
			'relative'   => $relative,
			'mime'       => (string) get_post_mime_type( $id ),
			'title'      => $post->post_title,
			'caption'    => $post->post_excerpt,
			'description'=> $post->post_content,
			'alt'        => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'sha256'     => $hash,
			'bytes'      => (int) filesize( $file ),
			'transfer'   => is_wp_error( $transfer ) ? null : $transfer,
		);
	}

	/**
	 * Whether a meta key is unsafe to export.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function skip_meta_key( $key ) {
		if ( in_array( $key, self::SKIP_META, true ) ) {
			return true;
		}
		if ( Picot_Dev_Mcp_Errors::is_secret_key( $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Attachment IDs referenced by blocks.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function media_ids_in_content( $content ) {
		$ids = array();
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $ids;
		}
		self::collect_block_ids( parse_blocks( $content ), $ids );
		return $ids;
	}

	/**
	 * Block names used by a post.
	 *
	 * @param string $content Content.
	 * @return string[]
	 */
	public static function block_names( $content ) {
		$names = array();
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $names;
		}
		$walk = static function ( $blocks ) use ( &$walk, &$names ) {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$names[] = (string) $block['blockName'];
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return array_values( array_unique( $names ) );
	}

	/**
	 * Collect id attributes.
	 *
	 * @param array $blocks Blocks.
	 * @param int[] $ids    Collected ids.
	 * @return void
	 */
	private static function collect_block_ids( array $blocks, array &$ids ) {
		foreach ( $blocks as $block ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			foreach ( array( 'id', 'mediaId' ) as $key ) {
				if ( ! empty( $attrs[ $key ] ) ) {
					$ids[] = (int) $attrs[ $key ];
				}
			}
			if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
				foreach ( $attrs['ids'] as $id ) {
					$ids[] = (int) $id;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_block_ids( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * Rewrite block IDs and upload URLs.
	 *
	 * @param string $content Content.
	 * @param array  $media_map Source attachment id => destination id.
	 * @param string $source_uploads Source uploads base URL.
	 * @param string $source_home Source home URL.
	 * @return string
	 */
	public static function rewrite_content( $content, array $media_map, $source_uploads, $source_home ) {
		$uploads = wp_upload_dir( null, false );
		$dest_uploads = (string) ( $uploads['baseurl'] ?? '' );
		$dest_home = home_url();
		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = self::rewrite_blocks( parse_blocks( $content ), $media_map );
			$content = serialize_blocks( $blocks );
		}
		$ids = array_keys( $media_map );
		usort(
			$ids,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);
		foreach ( $ids as $from ) {
			$to = (int) $media_map[ $from ];
			$content = str_replace(
				array( 'wp-image-' . $from . ' ', 'wp-image-' . $from . '"', 'wp-image-' . $from . "'" ),
				array( 'wp-image-' . $to . ' ', 'wp-image-' . $to . '"', 'wp-image-' . $to . "'" ),
				$content
			);
		}
		return self::rewrite_urls( $content, $source_home, $source_uploads, $dest_home, $dest_uploads );
	}

	/**
	 * Rewrite URL strings. Serialized payloads are left untouched.
	 *
	 * @param string $text Text.
	 * @param string $source_home Source home.
	 * @param string $source_uploads Source uploads.
	 * @param string $dest_home Destination home.
	 * @param string $dest_uploads Destination uploads.
	 * @return string
	 */
	public static function rewrite_urls( $text, $source_home, $source_uploads, $dest_home, $dest_uploads ) {
		if ( ! is_string( $text ) || '' === $text || is_serialized( $text ) ) {
			return $text;
		}
		if ( '' !== $source_uploads && '' !== $dest_uploads ) {
			$text = str_replace( untrailingslashit( $source_uploads ), untrailingslashit( $dest_uploads ), $text );
		}
		if ( '' !== $source_home && '' !== $dest_home ) {
			$text = str_replace( untrailingslashit( $source_home ), untrailingslashit( $dest_home ), $text );
		}
		return $text;
	}

	/**
	 * Walk a meta value and rewrite URLs and known IDs.
	 *
	 * @param mixed  $value Value.
	 * @param array  $media_map Media map.
	 * @param string $source_uploads Source uploads.
	 * @param string $source_home Source home.
	 * @return mixed
	 */
	public static function rewrite_meta_value( $value, array $media_map, $source_uploads, $source_home ) {
		$uploads = wp_upload_dir( null, false );
		$dest_uploads = (string) ( $uploads['baseurl'] ?? '' );
		if ( is_array( $value ) ) {
			$next = array();
			foreach ( $value as $key => $item ) {
				$next[ $key ] = self::rewrite_meta_value( $item, $media_map, $source_uploads, $source_home );
			}
			return $next;
		}
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$decoded = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				if ( false !== $decoded || 'b:0;' === $value ) {
					return self::rewrite_meta_value( $decoded, $media_map, $source_uploads, $source_home );
				}
				return $value;
			}
			return self::rewrite_urls( $value, $source_home, $source_uploads, home_url(), $dest_uploads );
		}
		if ( is_int( $value ) && isset( $media_map[ $value ] ) ) {
			return (int) $media_map[ $value ];
		}
		return $value;
	}

	/**
	 * Rewrite block attributes that store attachment IDs.
	 *
	 * @param array $blocks Blocks.
	 * @param array $media_map Map.
	 * @return array
	 */
	private static function rewrite_blocks( array $blocks, array $media_map ) {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( array( 'id', 'mediaId' ) as $key ) {
					if ( isset( $block['attrs'][ $key ] ) && isset( $media_map[ (int) $block['attrs'][ $key ] ] ) ) {
						$block['attrs'][ $key ] = (int) $media_map[ (int) $block['attrs'][ $key ] ];
					}
				}
				if ( ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
					$block['attrs']['ids'] = array_map(
						static function ( $id ) use ( $media_map ) {
							$id = (int) $id;
							return isset( $media_map[ $id ] ) ? (int) $media_map[ $id ] : $id;
						},
						$block['attrs']['ids']
					);
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::rewrite_blocks( $block['innerBlocks'], $media_map );
			}
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$block['innerContent'] = $block['innerContent'] ?? array( $block['innerHTML'] );
			}
		}
		return $blocks;
	}

	/**
	 * Find an existing attachment with the same hash or the same filename and hash.
	 *
	 * @param string $hash     SHA-256.
	 * @param string $filename Filename.
	 * @return int Attachment ID or 0.
	 */
	public static function find_media( $hash, $filename ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_picot_dev_mcp_sha256',
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! empty( $query->posts[0] ) ) {
			return (int) $query->posts[0];
		}
		$by_name = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_attached_file',
						'value'   => $filename,
						'compare' => 'LIKE',
					),
				),
			)
		);
		$conflict = 0;
		foreach ( $by_name->posts as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! is_string( $file ) || ! is_file( $file ) || basename( $file ) !== $filename ) {
				continue;
			}
			if ( hash_equals( $hash, hash_file( 'sha256', $file ) ) ) {
				update_post_meta( (int) $id, '_picot_dev_mcp_sha256', $hash );
				return (int) $id;
			}
			$conflict = -1;
		}
		return $conflict;
	}
}
