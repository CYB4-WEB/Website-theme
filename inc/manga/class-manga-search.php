<?php
/**
 * Manga Search Functionality.
 *
 * Provides AJAX search with debounce support, advanced filtering,
 * autocomplete suggestions, and WP_Query integration.
 *
 * @package starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Search
 *
 * Handles manga search, filtering, and autocomplete.
 *
 * @since 1.0.0
 */
class Starter_Manga_Search {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Search|null
	 */
	private static $instance = null;

	/**
	 * Number of results per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Search
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// AJAX search endpoints.
		add_action( 'wp_ajax_starter_manga_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_starter_manga_search', array( $this, 'ajax_search' ) );

		// AJAX autocomplete endpoint.
		add_action( 'wp_ajax_starter_manga_autocomplete', array( $this, 'ajax_autocomplete' ) );
		add_action( 'wp_ajax_nopriv_starter_manga_autocomplete', array( $this, 'ajax_autocomplete' ) );

		// Advanced search endpoint.
		add_action( 'wp_ajax_starter_manga_advanced_search', array( $this, 'ajax_advanced_search' ) );
		add_action( 'wp_ajax_nopriv_starter_manga_advanced_search', array( $this, 'ajax_advanced_search' ) );

		// Enqueue search scripts on relevant pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_search_assets' ) );
	}

	/**
	 * Enqueue search-related scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_search_assets() {
		wp_enqueue_script(
			'starter-manga-search',
			get_template_directory_uri() . '/assets/js/manga-search.js',
			array( 'jquery' ),
			wp_get_theme()->get( 'Version' ),
			true
		);

		wp_localize_script( 'starter-manga-search', 'starterSearch', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'starter_manga_nonce' ),
			'debounceMs'   => 300,
			'minChars'     => 2,
			'i18n'         => array(
				'searching'  => esc_html__( 'Searching...', 'starter' ),
				'noResults'  => esc_html__( 'No results found.', 'starter' ),
				'error'      => esc_html__( 'Search failed. Please try again.', 'starter' ),
				'loadMore'   => esc_html__( 'Load More', 'starter' ),
			),
		) );
	}

	/**
	 * AJAX handler: Quick search (with debounce support on client side).
	 *
	 * @return void
	 */
	public function ajax_search() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

		if ( strlen( $keyword ) < 2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Search term too short.', 'starter' ) ) );
		}

		$args = array(
			'post_type'      => 'wp-manga',
			'post_status'    => 'publish',
			's'              => $keyword,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $page,
		);

		// Also search in alternative names meta.
		$args['meta_query'] = array(
			'relation' => 'OR',
			array(
				'key'     => '_starter_manga_alt_names',
				'value'   => $keyword,
				'compare' => 'LIKE',
			),
		);

		$query   = new WP_Query( $args );
		$results = $this->format_search_results( $query );

		wp_send_json_success( array(
			'results'   => $results,
			'total'     => $query->found_posts,
			'pages'     => $query->max_num_pages,
			'page'      => $page,
			'has_more'  => $page < $query->max_num_pages,
		) );
	}

	/**
	 * AJAX handler: Autocomplete suggestions.
	 *
	 * @return void
	 */
	public function ajax_autocomplete() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$keyword = isset( $_GET['keyword'] ) ? sanitize_text_field( wp_unslash( $_GET['keyword'] ) ) : '';

		if ( strlen( $keyword ) < 2 ) {
			wp_send_json_success( array( 'suggestions' => array() ) );
		}

		// Check transient cache first.
		$cache_key = 'starter_search_' . md5( $keyword );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			wp_send_json_success( array( 'suggestions' => $cached ) );
		}

		$args = array(
			'post_type'      => 'wp-manga',
			'post_status'    => 'publish',
			's'              => $keyword,
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		);

		$query       = new WP_Query( $args );
		$suggestions = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$thumb   = get_the_post_thumbnail_url( $post_id, 'thumbnail' );

				$suggestions[] = array(
					'id'        => $post_id,
					'title'     => get_the_title(),
					'url'       => get_permalink(),
					'thumbnail' => $thumb ? $thumb : '',
					'type'      => $this->get_manga_type_label( $post_id ),
				);
			}
			wp_reset_postdata();
		}

		// Cache for 5 minutes.
		set_transient( $cache_key, $suggestions, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * AJAX handler: Advanced search with multiple filters.
	 *
	 * @return void
	 */
	public function ajax_advanced_search() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$orderby = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'latest';

		// Taxonomy filters.
		$genre   = isset( $_POST['genre'] ) ? array_map( 'absint', (array) $_POST['genre'] ) : array();
		$type    = isset( $_POST['type'] ) ? array_map( 'absint', (array) $_POST['type'] ) : array();
		$tag     = isset( $_POST['tag'] ) ? array_map( 'absint', (array) $_POST['tag'] ) : array();
		$release = isset( $_POST['release'] ) ? array_map( 'absint', (array) $_POST['release'] ) : array();

		// Meta filters.
		$status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$author  = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '';
		$artist  = isset( $_POST['artist'] ) ? sanitize_text_field( wp_unslash( $_POST['artist'] ) ) : '';
		$adult   = isset( $_POST['adult'] ) ? sanitize_text_field( wp_unslash( $_POST['adult'] ) ) : '';

		// Build WP_Query args.
		$args = array(
			'post_type'      => 'wp-manga',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $page,
		);

		// Keyword search.
		if ( ! empty( $keyword ) ) {
			$args['s'] = $keyword;
		}

		// Taxonomy queries.
		$tax_query = array();

		if ( ! empty( $genre ) ) {
			$tax_query[] = array(
				'taxonomy' => 'wp-manga-genre',
				'field'    => 'term_id',
				'terms'    => $genre,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $type ) ) {
			$tax_query[] = array(
				'taxonomy' => 'wp-manga-type',
				'field'    => 'term_id',
				'terms'    => $type,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $tag ) ) {
			$tax_query[] = array(
				'taxonomy' => 'wp-manga-tag',
				'field'    => 'term_id',
				'terms'    => $tag,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $release ) ) {
			$tax_query[] = array(
				'taxonomy' => 'wp-manga-release',
				'field'    => 'term_id',
				'terms'    => $release,
				'operator' => 'IN',
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Meta queries.
		$meta_query = array();

		if ( ! empty( $status ) ) {
			$allowed_statuses = array( 'ongoing', 'completed', 'hiatus', 'cancelled' );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$meta_query[] = array(
					'key'     => '_starter_manga_status',
					'value'   => $status,
					'compare' => '=',
				);
			}
		}

		if ( ! empty( $author ) ) {
			$meta_query[] = array(
				'key'     => '_starter_manga_author_name',
				'value'   => $author,
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $artist ) ) {
			$meta_query[] = array(
				'key'     => '_starter_manga_artist_name',
				'value'   => $artist,
				'compare' => 'LIKE',
			);
		}

		if ( '' !== $adult ) {
			$meta_query[] = array(
				'key'     => '_starter_manga_adult_content',
				'value'   => '1' === $adult ? '1' : '0',
				'compare' => '=',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Ordering.
		switch ( $orderby ) {
			case 'latest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;

			case 'popular':
				$args['meta_key'] = '_starter_manga_total_views'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;

			case 'a-z':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;

			case 'rating':
				$args['meta_key'] = '_starter_manga_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;

			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;

			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
		}

		$query   = new WP_Query( $args );
		$results = $this->format_search_results( $query );

		wp_send_json_success( array(
			'results'  => $results,
			'total'    => $query->found_posts,
			'pages'    => $query->max_num_pages,
			'page'     => $page,
			'has_more' => $page < $query->max_num_pages,
		) );
	}

	/**
	 * Format WP_Query results for JSON response.
	 *
	 * @param WP_Query $query The query object.
	 * @return array Formatted results.
	 */
	private function format_search_results( $query ) {
		$results = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$chapter_mgr    = Starter_Manga_Chapter::get_instance();
				$latest_chapter = $chapter_mgr->get_latest_chapter( $post_id );
				$chapter_count  = $chapter_mgr->count_chapters( $post_id );

				$thumb = get_the_post_thumbnail_url( $post_id, 'medium' );

				$results[] = array(
					'id'              => $post_id,
					'title'           => get_the_title(),
					'url'             => get_permalink(),
					'thumbnail'       => $thumb ? $thumb : '',
					'excerpt'         => wp_trim_words( get_the_excerpt(), 20 ),
					'status'          => get_post_meta( $post_id, '_starter_manga_status', true ),
					'type'            => $this->get_manga_type_label( $post_id ),
					'badge'           => get_post_meta( $post_id, '_starter_manga_badge', true ),
					'adult'           => get_post_meta( $post_id, '_starter_manga_adult_content', true ),
					'rating'          => (float) get_post_meta( $post_id, '_starter_manga_average_rating', true ),
					'chapter_count'   => $chapter_count,
					'latest_chapter'  => $latest_chapter ? array(
						'number' => $latest_chapter->chapter_number,
						'name'   => $latest_chapter->chapter_name,
						'date'   => $latest_chapter->date_created,
					) : null,
					'genres'          => $this->get_taxonomy_labels( $post_id, 'wp-manga-genre' ),
				);
			}
			wp_reset_postdata();
		}

		return $results;
	}

	/**
	 * Get the manga type label for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Type label or empty string.
	 */
	private function get_manga_type_label( $post_id ) {
		$terms = get_the_terms( $post_id, 'wp-manga-type' );

		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}

		return '';
	}

	/**
	 * Get taxonomy term labels for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Array of term names.
	 */
	private function get_taxonomy_labels( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( $terms && ! is_wp_error( $terms ) ) {
			return wp_list_pluck( $terms, 'name' );
		}

		return array();
	}

	/**
	 * Get available search filter options for front-end forms.
	 *
	 * @return array Filter options.
	 */
	public static function get_filter_options() {
		$options = array(
			'genres'   => array(),
			'types'    => array(),
			'tags'     => array(),
			'releases' => array(),
			'statuses' => array(
				'ongoing'   => esc_html__( 'Ongoing', 'starter' ),
				'completed' => esc_html__( 'Completed', 'starter' ),
				'hiatus'    => esc_html__( 'Hiatus', 'starter' ),
				'cancelled' => esc_html__( 'Cancelled', 'starter' ),
			),
			'orderby'  => array(
				'latest'  => esc_html__( 'Latest', 'starter' ),
				'popular' => esc_html__( 'Popular', 'starter' ),
				'a-z'     => esc_html__( 'A-Z', 'starter' ),
				'rating'  => esc_html__( 'Rating', 'starter' ),
				'newest'  => esc_html__( 'Newest', 'starter' ),
			),
		);

		$taxonomies = array(
			'genres'   => 'wp-manga-genre',
			'types'    => 'wp-manga-type',
			'tags'     => 'wp-manga-tag',
			'releases' => 'wp-manga-release',
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			) );

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$options[ $key ][] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
			}
		}

		return $options;
	}
}
