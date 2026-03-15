<?php
/**
 * JSON-LD structured data for manga, novel, and video content.
 *
 * Outputs Schema.org markup via wp_head for rich search results.
 * Defers to Yoast SEO or RankMath when either is handling schema,
 * while still providing manga-specific schemas they do not cover.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Schema_Markup
 *
 * Outputs JSON-LD structured data for manga series (ComicSeries),
 * novels (Book), video chapters (VideoObject), breadcrumbs, site
 * identity, and blog articles.
 *
 * @since 1.0.0
 */
class Starter_Schema_Markup {

	/**
	 * Whether an external SEO plugin handles general schema.
	 *
	 * @var bool|null
	 */
	private $external_schema = null;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'output_schema' ), 20 );
	}

	/*--------------------------------------------------------------------------
	 * Plugin Detection
	 *------------------------------------------------------------------------*/

	/**
	 * Check if an external plugin handles general schema output.
	 *
	 * Returns true if Yoast or RankMath are outputting their own schema graph.
	 *
	 * @return bool
	 */
	private function external_handles_general_schema() {
		if ( null !== $this->external_schema ) {
			return $this->external_schema;
		}

		$yoast_schema    = has_action( 'wpseo_json_ld_output' ) || has_action( 'wpseo_head' );
		$rankmath_schema = has_action( 'rank_math/json_ld' ) || class_exists( 'RankMath' );

		$this->external_schema = $yoast_schema || $rankmath_schema;

		return $this->external_schema;
	}

	/**
	 * Check if an external plugin handles manga-specific schema.
	 *
	 * Manga-specific schemas (ComicSeries, etc.) are rarely handled by
	 * generic SEO plugins, so we almost always output them.
	 *
	 * @return bool
	 */
	private function external_handles_manga_schema() {
		/**
		 * Filter whether external plugins handle manga schema.
		 *
		 * Return true to suppress manga-specific schema output.
		 *
		 * @param bool $handled Whether external plugins handle manga schema.
		 */
		return apply_filters( 'starter_external_handles_manga_schema', false );
	}

	/*--------------------------------------------------------------------------
	 * Main Output Router
	 *------------------------------------------------------------------------*/

	/**
	 * Output all applicable JSON-LD blocks.
	 *
	 * @return void
	 */
	public function output_schema() {
		// WebSite + Organization (only if no external SEO plugin).
		if ( ! $this->external_handles_general_schema() ) {
			if ( is_front_page() || is_home() ) {
				$this->output_json_ld( $this->build_website_schema() );
				$this->output_json_ld( $this->build_organization_schema() );
			}
		}

		// Manga-specific schemas are always output (external plugins rarely cover these).
		if ( ! $this->external_handles_manga_schema() ) {
			if ( is_singular( 'wp-manga' ) ) {
				$this->output_manga_schema();
			}
		}

		// Blog post Article schema (only if no external SEO plugin).
		if ( ! $this->external_handles_general_schema() ) {
			if ( is_singular( 'post' ) ) {
				$this->output_json_ld( $this->build_article_schema() );
			}
		}

		// BreadcrumbList for manga/chapter pages (always, unless external handles it).
		if ( ! $this->external_handles_general_schema() ) {
			if ( is_singular( array( 'wp-manga', 'post' ) ) ) {
				$breadcrumb = $this->build_breadcrumb_schema();
				if ( $breadcrumb ) {
					$this->output_json_ld( $breadcrumb );
				}
			}
		}
	}

	/*--------------------------------------------------------------------------
	 * Manga Schema (ComicSeries / Book / VideoObject)
	 *------------------------------------------------------------------------*/

	/**
	 * Output the appropriate schema for a manga/novel/video post.
	 *
	 * @return void
	 */
	private function output_manga_schema() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$type = $this->get_content_type( $post->ID );

		switch ( $type ) {
			case 'novel':
				$schema = $this->build_book_schema( $post );
				break;

			case 'video':
				$schema = $this->build_video_schema( $post );
				break;

			default:
				$schema = $this->build_comic_series_schema( $post );
				break;
		}

		if ( ! empty( $schema ) ) {
			$this->output_json_ld( $schema );
		}

		// Also output BreadcrumbList for manga pages.
		$breadcrumb = $this->build_manga_breadcrumb_schema( $post );
		if ( $breadcrumb ) {
			$this->output_json_ld( $breadcrumb );
		}
	}

	/**
	 * Build ComicSeries schema for manga content.
	 *
	 * @param WP_Post $post Manga post.
	 * @return array Schema data.
	 */
	private function build_comic_series_schema( $post ) {
		$meta = $this->get_manga_meta( $post->ID );

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'ComicSeries',
			'name'          => get_the_title( $post ),
			'url'           => get_permalink( $post ),
			'description'   => $this->get_description( $post ),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'inLanguage'    => get_bloginfo( 'language' ),
		);

		// Alternative name.
		if ( ! empty( $meta['alt_names'] ) ) {
			$schema['alternateName'] = $meta['alt_names'];
		}

		// Image.
		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumbnail ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $thumbnail,
			);

			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$meta_data = wp_get_attachment_metadata( $thumb_id );
				if ( $meta_data && isset( $meta_data['width'], $meta_data['height'] ) ) {
					$schema['image']['width']  = $meta_data['width'];
					$schema['image']['height'] = $meta_data['height'];
				}
			}
		}

		// Author.
		if ( ! empty( $meta['author_name'] ) ) {
			$schema['author'] = array(
				'@type' => 'Person',
				'name'  => $meta['author_name'],
			);
		}

		// Artist as contributor.
		if ( ! empty( $meta['artist_name'] ) && $meta['artist_name'] !== ( $meta['author_name'] ?? '' ) ) {
			$schema['contributor'] = array(
				'@type' => 'Person',
				'name'  => $meta['artist_name'],
			);
		}

		// Genre.
		$genres = wp_get_object_terms( $post->ID, 'wp-manga-genre', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$schema['genre'] = $genres;
		}

		// Number of episodes (chapters).
		$chapter_count = $this->get_chapter_count( $post->ID );
		if ( $chapter_count > 0 ) {
			$schema['numberOfEpisodes'] = $chapter_count;
		}

		// Aggregate rating.
		$rating = $this->get_aggregate_rating( $post->ID );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		// Publisher.
		$schema['publisher'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo = get_site_icon_url( 512 );
		if ( $logo ) {
			$schema['publisher']['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		/**
		 * Filter the ComicSeries schema data.
		 *
		 * @param array   $schema Schema data.
		 * @param WP_Post $post   Current post.
		 */
		return apply_filters( 'starter_schema_comic_series', $schema, $post );
	}

	/**
	 * Build Book schema for novel content.
	 *
	 * @param WP_Post $post Novel post.
	 * @return array Schema data.
	 */
	private function build_book_schema( $post ) {
		$meta = $this->get_manga_meta( $post->ID );

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Book',
			'name'          => get_the_title( $post ),
			'url'           => get_permalink( $post ),
			'description'   => $this->get_description( $post ),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'inLanguage'    => get_bloginfo( 'language' ),
			'bookFormat'    => 'EBook',
		);

		// Alternative name.
		if ( ! empty( $meta['alt_names'] ) ) {
			$schema['alternateName'] = $meta['alt_names'];
		}

		// Image.
		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumbnail ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $thumbnail,
			);
		}

		// Author.
		if ( ! empty( $meta['author_name'] ) ) {
			$schema['author'] = array(
				'@type' => 'Person',
				'name'  => $meta['author_name'],
			);
		}

		// Genre.
		$genres = wp_get_object_terms( $post->ID, 'wp-manga-genre', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$schema['genre'] = $genres;
		}

		// Chapter count as numberOfPages approximation.
		$chapter_count = $this->get_chapter_count( $post->ID );
		if ( $chapter_count > 0 ) {
			$schema['numberOfPages'] = $chapter_count;
		}

		// Aggregate rating.
		$rating = $this->get_aggregate_rating( $post->ID );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		// Publisher.
		$schema['publisher'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		/**
		 * Filter the Book schema data.
		 *
		 * @param array   $schema Schema data.
		 * @param WP_Post $post   Current post.
		 */
		return apply_filters( 'starter_schema_book', $schema, $post );
	}

	/**
	 * Build VideoObject schema for video chapter content.
	 *
	 * @param WP_Post $post Video post.
	 * @return array Schema data.
	 */
	private function build_video_schema( $post ) {
		$meta = $this->get_manga_meta( $post->ID );

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'VideoObject',
			'name'          => get_the_title( $post ),
			'url'           => get_permalink( $post ),
			'description'   => $this->get_description( $post ),
			'datePublished' => get_the_date( 'c', $post ),
			'uploadDate'    => get_the_date( 'c', $post ),
		);

		// Thumbnail.
		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumbnail ) {
			$schema['thumbnailUrl'] = $thumbnail;
		}

		// Author as creator.
		if ( ! empty( $meta['author_name'] ) ) {
			$schema['creator'] = array(
				'@type' => 'Person',
				'name'  => $meta['author_name'],
			);
		}

		// Genre.
		$genres = wp_get_object_terms( $post->ID, 'wp-manga-genre', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$schema['genre'] = $genres;
		}

		// Aggregate rating.
		$rating = $this->get_aggregate_rating( $post->ID );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		/**
		 * Filter the VideoObject schema data.
		 *
		 * @param array   $schema Schema data.
		 * @param WP_Post $post   Current post.
		 */
		return apply_filters( 'starter_schema_video', $schema, $post );
	}

	/*--------------------------------------------------------------------------
	 * WebSite Schema
	 *------------------------------------------------------------------------*/

	/**
	 * Build WebSite schema with SearchAction for sitelinks search box.
	 *
	 * @return array Schema data.
	 */
	private function build_website_schema() {
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => get_bloginfo( 'name' ),
			'url'             => home_url( '/' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		/**
		 * Filter the WebSite schema data.
		 *
		 * @param array $schema Schema data.
		 */
		return apply_filters( 'starter_schema_website', $schema );
	}

	/*--------------------------------------------------------------------------
	 * Organization Schema
	 *------------------------------------------------------------------------*/

	/**
	 * Build Organization schema for the site.
	 *
	 * @return array Schema data.
	 */
	private function build_organization_schema() {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);

		$logo = get_site_icon_url( 512 );
		if ( $logo ) {
			$schema['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		// Custom logo from theme customizer.
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$custom_logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $custom_logo_url ) {
				$schema['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $custom_logo_url,
				);
			}
		}

		/**
		 * Filter the Organization schema data.
		 *
		 * @param array $schema Schema data.
		 */
		return apply_filters( 'starter_schema_organization', $schema );
	}

	/*--------------------------------------------------------------------------
	 * Article Schema (Blog Posts)
	 *------------------------------------------------------------------------*/

	/**
	 * Build Article schema for blog posts.
	 *
	 * @return array|null Schema data or null.
	 */
	private function build_article_schema() {
		$post = get_post();
		if ( ! $post ) {
			return null;
		}

		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => get_the_title( $post ),
			'url'              => get_permalink( $post ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
		);

		// Author.
		$author = get_the_author_meta( 'display_name', $post->post_author );
		if ( $author ) {
			$schema['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
				'url'   => get_author_posts_url( $post->post_author ),
			);
		}

		// Publisher.
		$schema['publisher'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo = get_site_icon_url( 512 );
		if ( $logo ) {
			$schema['publisher']['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		// Featured image.
		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumbnail ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $thumbnail,
			);

			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$image_meta = wp_get_attachment_metadata( $thumb_id );
				if ( $image_meta && isset( $image_meta['width'], $image_meta['height'] ) ) {
					$schema['image']['width']  = $image_meta['width'];
					$schema['image']['height'] = $image_meta['height'];
				}
			}
		}

		// Description.
		$description = $this->get_description( $post );
		if ( $description ) {
			$schema['description'] = $description;
		}

		// Word count.
		$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
		if ( $word_count > 0 ) {
			$schema['wordCount'] = $word_count;
		}

		/**
		 * Filter the Article schema data.
		 *
		 * @param array   $schema Schema data.
		 * @param WP_Post $post   Current post.
		 */
		return apply_filters( 'starter_schema_article', $schema, $post );
	}

	/*--------------------------------------------------------------------------
	 * BreadcrumbList Schema
	 *------------------------------------------------------------------------*/

	/**
	 * Build BreadcrumbList schema for standard posts.
	 *
	 * @return array|null Schema data or null.
	 */
	private function build_breadcrumb_schema() {
		$post = get_post();
		if ( ! $post ) {
			return null;
		}

		$items = array();

		// Home.
		$items[] = array(
			'name' => __( 'Home', 'starter-theme' ),
			'url'  => home_url( '/' ),
		);

		// Post type archive (if applicable).
		$post_type = get_post_type( $post );
		if ( 'post' === $post_type ) {
			// Category.
			$categories = get_the_category( $post->ID );
			if ( ! empty( $categories ) ) {
				$cat = $categories[0];
				$items[] = array(
					'name' => $cat->name,
					'url'  => get_category_link( $cat->term_id ),
				);
			}
		}

		// Current page.
		$items[] = array(
			'name' => get_the_title( $post ),
			'url'  => get_permalink( $post ),
		);

		return $this->format_breadcrumb_schema( $items );
	}

	/**
	 * Build BreadcrumbList schema for manga pages.
	 *
	 * @param WP_Post $post Manga post.
	 * @return array|null Schema data or null.
	 */
	private function build_manga_breadcrumb_schema( $post ) {
		$items = array();

		// Home.
		$items[] = array(
			'name' => __( 'Home', 'starter-theme' ),
			'url'  => home_url( '/' ),
		);

		// Post type archive.
		$archive_url = get_post_type_archive_link( 'wp-manga' );
		if ( $archive_url ) {
			$items[] = array(
				'name' => __( 'Manga', 'starter-theme' ),
				'url'  => $archive_url,
			);
		}

		// Genre (first one).
		$genres = wp_get_object_terms( $post->ID, 'wp-manga-genre', array( 'fields' => 'all' ) );
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$genre    = $genres[0];
			$term_url = get_term_link( $genre );
			if ( ! is_wp_error( $term_url ) ) {
				$items[] = array(
					'name' => $genre->name,
					'url'  => $term_url,
				);
			}
		}

		// Current manga.
		$items[] = array(
			'name' => get_the_title( $post ),
			'url'  => get_permalink( $post ),
		);

		return $this->format_breadcrumb_schema( $items );
	}

	/**
	 * Format breadcrumb items into BreadcrumbList schema.
	 *
	 * @param array $items Array of name/url pairs.
	 * @return array|null Schema data or null if empty.
	 */
	private function format_breadcrumb_schema( $items ) {
		if ( empty( $items ) || count( $items ) < 2 ) {
			return null;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(),
		);

		$position = 1;
		foreach ( $items as $item ) {
			$schema['itemListElement'][] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
			$position++;
		}

		/**
		 * Filter the BreadcrumbList schema data.
		 *
		 * @param array $schema Schema data.
		 * @param array $items  Breadcrumb items.
		 */
		return apply_filters( 'starter_schema_breadcrumb', $schema, $items );
	}

	/*--------------------------------------------------------------------------
	 * Helper Methods
	 *------------------------------------------------------------------------*/

	/**
	 * Output a JSON-LD script block.
	 *
	 * @param array|null $schema Schema data.
	 * @return void
	 */
	private function output_json_ld( $schema ) {
		if ( empty( $schema ) ) {
			return;
		}

		// Remove null/empty values recursively.
		$schema = $this->clean_schema( $schema );

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		if ( ! $json ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD output, encoded with wp_json_encode.
		echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	}

	/**
	 * Recursively remove null and empty string values from schema.
	 *
	 * @param array $data Schema data.
	 * @return array Cleaned data.
	 */
	private function clean_schema( $data ) {
		$cleaned = array();

		foreach ( $data as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = $this->clean_schema( $value );
				if ( ! empty( $value ) ) {
					$cleaned[ $key ] = $value;
				}
			} else {
				$cleaned[ $key ] = $value;
			}
		}

		return $cleaned;
	}

	/**
	 * Get the content type for a manga post.
	 *
	 * @param int $post_id Post ID.
	 * @return string 'manga', 'novel', or 'video'.
	 */
	private function get_content_type( $post_id ) {
		$types = wp_get_object_terms( $post_id, 'wp-manga-type', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $types ) || empty( $types ) ) {
			return 'manga';
		}

		$type = strtolower( $types[0] );

		if ( 'novel' === $type ) {
			return 'novel';
		}

		if ( 'video' === $type || 'drama' === $type ) {
			return 'video';
		}

		return 'manga';
	}

	/**
	 * Get manga metadata.
	 *
	 * @param int $post_id Post ID.
	 * @return array Meta data.
	 */
	private function get_manga_meta( $post_id ) {
		$prefix = '_starter_manga_';
		$fields = array( 'alt_names', 'status', 'author_name', 'artist_name', 'serialization', 'adult_content' );
		$meta   = array();

		foreach ( $fields as $field ) {
			$meta[ $field ] = get_post_meta( $post_id, $prefix . $field, true );
		}

		return $meta;
	}

	/**
	 * Get the chapter count for a manga.
	 *
	 * @param int $post_id Manga post ID.
	 * @return int
	 */
	private function get_chapter_count( $post_id ) {
		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$chapters_table} WHERE manga_id = %d AND chapter_status = 'publish'",
				$post_id
			)
		);

		return $count ? (int) $count : 0;
	}

	/**
	 * Get aggregate rating data for a manga.
	 *
	 * @param int $post_id Manga post ID.
	 * @return array|null AggregateRating schema or null.
	 */
	private function get_aggregate_rating( $post_id ) {
		$avg_rating   = get_post_meta( $post_id, '_starter_manga_average_rating', true );
		$rating_count = get_post_meta( $post_id, '_starter_manga_rating_count', true );

		if ( empty( $avg_rating ) || empty( $rating_count ) || (float) $avg_rating <= 0 ) {
			return null;
		}

		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => round( (float) $avg_rating, 1 ),
			'bestRating'  => 5,
			'worstRating' => 1,
			'ratingCount' => (int) $rating_count,
		);
	}

	/**
	 * Get a truncated description for a post.
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	private function get_description( $post ) {
		$description = get_post_meta( $post->ID, '_starter_description', true );

		if ( empty( $description ) ) {
			$description = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		}

		if ( empty( $description ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 50, '...' );
		}

		return wp_strip_all_tags( $description );
	}
}
