<?php
/**
 * XML Sitemap for manga content.
 *
 * Provides a custom sitemap index with sub-sitemaps for series, chapters,
 * genres, and authors. Integrates with WordPress core sitemaps (5.5+)
 * and Yoast SEO when active. Includes search engine ping and caching.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Sitemap
 *
 * Manages XML sitemaps for manga series, chapters, genres, and authors
 * with proper caching, pagination, and search engine notification.
 *
 * @since 1.0.0
 */
class Starter_Manga_Sitemap {

	/**
	 * Maximum URLs per sitemap file.
	 *
	 * @var int
	 */
	const MAX_URLS_PER_SITEMAP = 50000;

	/**
	 * URLs per page for chapter sitemaps (kept lower for faster generation).
	 *
	 * @var int
	 */
	const CHAPTERS_PER_PAGE = 1000;

	/**
	 * Transient TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @var bool|null
	 */
	private $yoast_active = null;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_sitemap_request' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Robots.txt modification.
		add_filter( 'robots_txt', array( $this, 'add_sitemap_to_robots' ), 10, 2 );

		// WordPress core sitemap integration (WP 5.5+).
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			add_filter( 'wp_sitemaps_post_types', array( $this, 'add_manga_to_core_sitemap' ) );
			add_action( 'init', array( $this, 'register_core_sitemap_provider' ), 99 );
		}

		// Yoast SEO integration.
		add_action( 'wpseo_register_extra_replacements', array( $this, 'yoast_integration' ) );
		add_filter( 'wpseo_sitemap_index', array( $this, 'add_to_yoast_sitemap_index' ) );
		add_action( 'wpseo_do_sitemap_manga-chapters', array( $this, 'render_yoast_chapter_sitemap' ) );

		// Cache invalidation.
		add_action( 'starter_chapter_added', array( $this, 'on_content_change' ) );
		add_action( 'starter_chapter_updated', array( $this, 'on_content_change' ) );
		add_action( 'save_post_wp-manga', array( $this, 'on_content_change' ) );
		add_action( 'created_wp-manga-genre', array( $this, 'invalidate_cache' ) );
		add_action( 'edited_wp-manga-genre', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_wp-manga-genre', array( $this, 'invalidate_cache' ) );
	}

	/*--------------------------------------------------------------------------
	 * Plugin Detection
	 *------------------------------------------------------------------------*/

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	private function is_yoast_active() {
		if ( null === $this->yoast_active ) {
			$this->yoast_active = defined( 'WPSEO_VERSION' );
		}
		return $this->yoast_active;
	}

	/*--------------------------------------------------------------------------
	 * Rewrite Rules and Query Vars
	 *------------------------------------------------------------------------*/

	/**
	 * Register rewrite rules for custom sitemap URLs.
	 *
	 * @return void
	 */
	public function register_rewrite_rules() {
		// Skip custom sitemaps if Yoast handles them.
		if ( $this->is_yoast_active() ) {
			return;
		}

		add_rewrite_rule(
			'^manga-sitemap\.xml$',
			'index.php?starter_sitemap=index',
			'top'
		);

		add_rewrite_rule(
			'^manga-sitemap-series\.xml$',
			'index.php?starter_sitemap=series',
			'top'
		);

		add_rewrite_rule(
			'^manga-sitemap-chapters\.xml$',
			'index.php?starter_sitemap=chapters&starter_sitemap_page=1',
			'top'
		);

		add_rewrite_rule(
			'^manga-sitemap-chapters-([0-9]+)\.xml$',
			'index.php?starter_sitemap=chapters&starter_sitemap_page=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^manga-sitemap-genres\.xml$',
			'index.php?starter_sitemap=genres',
			'top'
		);

		add_rewrite_rule(
			'^manga-sitemap-authors\.xml$',
			'index.php?starter_sitemap=authors',
			'top'
		);
	}

	/**
	 * Register custom query variables.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'starter_sitemap';
		$vars[] = 'starter_sitemap_page';
		return $vars;
	}

	/*--------------------------------------------------------------------------
	 * Request Handling
	 *------------------------------------------------------------------------*/

	/**
	 * Handle sitemap requests on template_redirect.
	 *
	 * @return void
	 */
	public function handle_sitemap_request() {
		$sitemap_type = get_query_var( 'starter_sitemap', '' );

		if ( empty( $sitemap_type ) ) {
			return;
		}

		// Yoast handles its own sitemaps.
		if ( $this->is_yoast_active() ) {
			return;
		}

		$page = absint( get_query_var( 'starter_sitemap_page', 1 ) );
		if ( $page < 1 ) {
			$page = 1;
		}

		// Set XML content type.
		header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Robots-Tag: noindex, follow', true );
		header( 'Cache-Control: public, max-age=3600' );

		switch ( $sitemap_type ) {
			case 'index':
				$this->render_sitemap_index();
				break;

			case 'series':
				$this->render_series_sitemap();
				break;

			case 'chapters':
				$this->render_chapters_sitemap( $page );
				break;

			case 'genres':
				$this->render_genres_sitemap();
				break;

			case 'authors':
				$this->render_authors_sitemap();
				break;

			default:
				status_header( 404 );
				exit;
		}

		exit;
	}

	/*--------------------------------------------------------------------------
	 * Sitemap Index
	 *------------------------------------------------------------------------*/

	/**
	 * Render the sitemap index XML.
	 *
	 * @return void
	 */
	private function render_sitemap_index() {
		$cached = get_transient( 'starter_sitemap_index' );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached XML output.
			return;
		}

		$last_modified = $this->get_last_modified_date();

		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
	<sitemap>
		<loc><?php echo esc_url( home_url( '/manga-sitemap-series.xml' ) ); ?></loc>
		<lastmod><?php echo esc_html( $last_modified ); ?></lastmod>
	</sitemap>
<?php
		// Calculate chapter sitemap pages.
		$total_chapters = $this->get_total_published_chapters();
		$total_pages    = max( 1, (int) ceil( $total_chapters / self::CHAPTERS_PER_PAGE ) );

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url = 1 === $i
				? home_url( '/manga-sitemap-chapters.xml' )
				: home_url( '/manga-sitemap-chapters-' . $i . '.xml' );
			?>
	<sitemap>
		<loc><?php echo esc_url( $url ); ?></loc>
		<lastmod><?php echo esc_html( $last_modified ); ?></lastmod>
	</sitemap>
<?php
		}
?>
	<sitemap>
		<loc><?php echo esc_url( home_url( '/manga-sitemap-genres.xml' ) ); ?></loc>
		<lastmod><?php echo esc_html( $last_modified ); ?></lastmod>
	</sitemap>
	<sitemap>
		<loc><?php echo esc_url( home_url( '/manga-sitemap-authors.xml' ) ); ?></loc>
		<lastmod><?php echo esc_html( $last_modified ); ?></lastmod>
	</sitemap>
</sitemapindex>
<?php
		$output = ob_get_clean();
		set_transient( 'starter_sitemap_index', $output, self::CACHE_TTL );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/*--------------------------------------------------------------------------
	 * Series Sitemap
	 *------------------------------------------------------------------------*/

	/**
	 * Render the series sitemap XML.
	 *
	 * @return void
	 */
	private function render_series_sitemap() {
		$cached = get_transient( 'starter_sitemap_series' );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$manga_posts = get_posts( array(
			'post_type'      => 'wp-manga',
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_URLS_PER_SITEMAP,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_starter_noindex',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_starter_noindex',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		) );

		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<?php foreach ( $manga_posts as $post_id ) :
	$permalink = get_permalink( $post_id );
	$modified  = get_post_modified_time( 'c', true, $post_id );
	$thumbnail = get_the_post_thumbnail_url( $post_id, 'full' );
	$title     = get_the_title( $post_id );
?>
	<url>
		<loc><?php echo esc_url( $permalink ); ?></loc>
		<lastmod><?php echo esc_html( $modified ); ?></lastmod>
		<changefreq>weekly</changefreq>
		<priority>0.8</priority>
<?php if ( $thumbnail ) : ?>
		<image:image>
			<image:loc><?php echo esc_url( $thumbnail ); ?></image:loc>
			<image:title><![CDATA[<?php echo esc_html( $title ); ?>]]></image:title>
		</image:image>
<?php endif; ?>
	</url>
<?php endforeach; ?>
</urlset>
<?php
		$output = ob_get_clean();
		set_transient( 'starter_sitemap_series', $output, self::CACHE_TTL );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/*--------------------------------------------------------------------------
	 * Chapters Sitemap (Paginated)
	 *------------------------------------------------------------------------*/

	/**
	 * Render a paginated chapters sitemap XML.
	 *
	 * @param int $page Page number (1-indexed).
	 * @return void
	 */
	private function render_chapters_sitemap( $page = 1 ) {
		$cache_key = 'starter_sitemap_chapters_' . $page;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';
		$offset         = ( $page - 1 ) * self::CHAPTERS_PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chapters = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.chapter_number, c.date_created, c.date_modified,
						p.post_name AS manga_slug, p.ID AS manga_post_id
				FROM {$chapters_table} AS c
				INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
				WHERE c.chapter_status = 'publish'
				AND p.post_status = 'publish'
				ORDER BY c.date_created DESC
				LIMIT %d OFFSET %d",
				self::CHAPTERS_PER_PAGE,
				$offset
			)
		);

		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ( $chapters as $chapter ) :
	$chapter_slug = 'chapter-' . $chapter->chapter_number;
	$url          = home_url( '/manga/' . $chapter->manga_slug . '/' . $chapter_slug . '/' );
	$lastmod      = $chapter->date_modified ? $chapter->date_modified : $chapter->date_created;
	$lastmod_iso  = '';
	$date_obj     = DateTime::createFromFormat( 'Y-m-d H:i:s', $lastmod );
	if ( $date_obj ) {
		$lastmod_iso = $date_obj->format( 'c' );
	}
?>
	<url>
		<loc><?php echo esc_url( $url ); ?></loc>
<?php if ( $lastmod_iso ) : ?>
		<lastmod><?php echo esc_html( $lastmod_iso ); ?></lastmod>
<?php endif; ?>
		<changefreq>monthly</changefreq>
		<priority>0.6</priority>
	</url>
<?php endforeach; ?>
</urlset>
<?php
		$output = ob_get_clean();
		set_transient( $cache_key, $output, self::CACHE_TTL );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/*--------------------------------------------------------------------------
	 * Genres Sitemap
	 *------------------------------------------------------------------------*/

	/**
	 * Render the genres sitemap XML.
	 *
	 * @return void
	 */
	private function render_genres_sitemap() {
		$cached = get_transient( 'starter_sitemap_genres' );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$genres = get_terms( array(
			'taxonomy'   => 'wp-manga-genre',
			'hide_empty' => true,
			'number'     => self::MAX_URLS_PER_SITEMAP,
		) );

		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			foreach ( $genres as $genre ) {
				$term_link = get_term_link( $genre );
				if ( is_wp_error( $term_link ) ) {
					continue;
				}
				?>
	<url>
		<loc><?php echo esc_url( $term_link ); ?></loc>
		<changefreq>weekly</changefreq>
		<priority>0.5</priority>
	</url>
<?php
			}
		}
?>
</urlset>
<?php
		$output = ob_get_clean();
		set_transient( 'starter_sitemap_genres', $output, self::CACHE_TTL );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/*--------------------------------------------------------------------------
	 * Authors Sitemap
	 *------------------------------------------------------------------------*/

	/**
	 * Render the authors sitemap XML.
	 *
	 * @return void
	 */
	private function render_authors_sitemap() {
		$cached = get_transient( 'starter_sitemap_authors' );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		global $wpdb;

		// Get distinct author names from manga meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$authors = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} AS pm
				INNER JOIN {$wpdb->posts} AS p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value != ''
				AND p.post_status = 'publish'
				AND p.post_type = 'wp-manga'
				ORDER BY pm.meta_value ASC
				LIMIT %d",
				'_starter_manga_author_name',
				self::MAX_URLS_PER_SITEMAP
			)
		);

		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author_name ) {
				// Build author archive URL using the manga archive with author filter.
				$author_slug = sanitize_title( $author_name );
				$author_url  = home_url( '/manga-author/' . $author_slug . '/' );

				/**
				 * Filter the author page URL for the sitemap.
				 *
				 * @param string $author_url  Author page URL.
				 * @param string $author_name Author name.
				 */
				$author_url = apply_filters( 'starter_sitemap_author_url', $author_url, $author_name );
				?>
	<url>
		<loc><?php echo esc_url( $author_url ); ?></loc>
		<changefreq>weekly</changefreq>
		<priority>0.5</priority>
	</url>
<?php
			}
		}
?>
</urlset>
<?php
		$output = ob_get_clean();
		set_transient( 'starter_sitemap_authors', $output, self::CACHE_TTL );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/*--------------------------------------------------------------------------
	 * WordPress Core Sitemap Integration (WP 5.5+)
	 *------------------------------------------------------------------------*/

	/**
	 * Add manga post type to core sitemaps.
	 *
	 * @param array $post_types Post types array.
	 * @return array
	 */
	public function add_manga_to_core_sitemap( $post_types ) {
		if ( post_type_exists( 'wp-manga' ) && ! isset( $post_types['wp-manga'] ) ) {
			$post_types['wp-manga'] = get_post_type_object( 'wp-manga' );
		}
		return $post_types;
	}

	/**
	 * Register a custom sitemap provider for chapters in core sitemaps.
	 *
	 * @return void
	 */
	public function register_core_sitemap_provider() {
		if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}

		$provider = new Starter_Manga_Chapter_Sitemap_Provider();

		/** @var WP_Sitemaps $sitemaps */
		$sitemaps = wp_sitemaps_get_server();
		if ( $sitemaps && method_exists( $sitemaps->registry, 'add_provider' ) ) {
			$sitemaps->registry->add_provider( 'manga-chapters', $provider );
		}
	}

	/*--------------------------------------------------------------------------
	 * Yoast SEO Integration
	 *------------------------------------------------------------------------*/

	/**
	 * Yoast integration callback.
	 *
	 * @return void
	 */
	public function yoast_integration() {
		// Placeholder for Yoast-specific replacements if needed.
	}

	/**
	 * Add chapter sitemap to Yoast sitemap index.
	 *
	 * @param string $sitemap_index Yoast sitemap index XML.
	 * @return string
	 */
	public function add_to_yoast_sitemap_index( $sitemap_index ) {
		$last_modified = $this->get_last_modified_date();

		$sitemap_index .= '<sitemap>' . "\n";
		$sitemap_index .= "\t" . '<loc>' . esc_url( home_url( '/manga-chapters-sitemap.xml' ) ) . '</loc>' . "\n";
		$sitemap_index .= "\t" . '<lastmod>' . esc_html( $last_modified ) . '</lastmod>' . "\n";
		$sitemap_index .= '</sitemap>' . "\n";

		return $sitemap_index;
	}

	/**
	 * Render chapter sitemap for Yoast.
	 *
	 * @return void
	 */
	public function render_yoast_chapter_sitemap() {
		if ( ! class_exists( 'WPSEO_Sitemaps' ) ) {
			return;
		}

		global $wpdb, $wpseo_sitemaps;

		$chapters_table = $wpdb->prefix . 'manga_chapters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chapters = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.chapter_number, c.date_created, c.date_modified,
						p.post_name AS manga_slug
				FROM {$chapters_table} AS c
				INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
				WHERE c.chapter_status = 'publish'
				AND p.post_status = 'publish'
				ORDER BY c.date_created DESC
				LIMIT %d",
				self::MAX_URLS_PER_SITEMAP
			)
		);

		$output = '';

		foreach ( $chapters as $chapter ) {
			$chapter_slug = 'chapter-' . $chapter->chapter_number;
			$url          = home_url( '/manga/' . $chapter->manga_slug . '/' . $chapter_slug . '/' );
			$lastmod      = $chapter->date_modified ? $chapter->date_modified : $chapter->date_created;
			$date_obj     = DateTime::createFromFormat( 'Y-m-d H:i:s', $lastmod );
			$lastmod_iso  = $date_obj ? $date_obj->format( 'c' ) : '';

			$output .= '<url>' . "\n";
			$output .= "\t" . '<loc>' . esc_url( $url ) . '</loc>' . "\n";
			if ( $lastmod_iso ) {
				$output .= "\t" . '<lastmod>' . esc_html( $lastmod_iso ) . '</lastmod>' . "\n";
			}
			$output .= '</url>' . "\n";
		}

		if ( isset( $wpseo_sitemaps ) && method_exists( $wpseo_sitemaps, 'set_sitemap' ) ) {
			$wpseo_sitemaps->set_sitemap( $output );
		}
	}

	/*--------------------------------------------------------------------------
	 * Robots.txt
	 *------------------------------------------------------------------------*/

	/**
	 * Add sitemap URL to robots.txt output.
	 *
	 * @param string $output Robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function add_sitemap_to_robots( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$sitemap_url = $this->is_yoast_active()
			? home_url( '/sitemap_index.xml' )
			: home_url( '/manga-sitemap.xml' );

		// Only add if not already present.
		if ( false === strpos( $output, 'manga-sitemap.xml' ) && ! $this->is_yoast_active() ) {
			$output .= "\n" . 'Sitemap: ' . esc_url( $sitemap_url ) . "\n";
		}

		return $output;
	}

	/*--------------------------------------------------------------------------
	 * Search Engine Ping
	 *------------------------------------------------------------------------*/

	/**
	 * Ping search engines about sitemap update.
	 *
	 * Called when new chapters are published.
	 *
	 * @return void
	 */
	private function ping_search_engines() {
		if ( '0' === get_option( 'blog_public' ) ) {
			return;
		}

		$sitemap_url = $this->is_yoast_active()
			? home_url( '/sitemap_index.xml' )
			: home_url( '/manga-sitemap.xml' );

		$ping_urls = array(
			'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
			'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
		);

		/**
		 * Filter the list of search engine ping URLs.
		 *
		 * @param string[] $ping_urls  Ping URLs.
		 * @param string   $sitemap_url Sitemap URL.
		 */
		$ping_urls = apply_filters( 'starter_sitemap_ping_urls', $ping_urls, $sitemap_url );

		foreach ( $ping_urls as $ping_url ) {
			wp_remote_get(
				$ping_url,
				array(
					'timeout'   => 3,
					'blocking'  => false,
					'sslverify' => false,
				)
			);
		}
	}

	/*--------------------------------------------------------------------------
	 * Cache Management
	 *------------------------------------------------------------------------*/

	/**
	 * Handle content changes: invalidate cache and ping search engines.
	 *
	 * @param int|null $post_id Optional post ID.
	 * @return void
	 */
	public function on_content_change( $post_id = null ) {
		// Verify the post is published if a post ID is provided.
		if ( $post_id ) {
			$status = get_post_status( $post_id );
			if ( 'publish' !== $status ) {
				$this->invalidate_cache();
				return;
			}
		}

		$this->invalidate_cache();

		// Throttle pings to at most once per 10 minutes.
		$last_ping = get_transient( 'starter_sitemap_last_ping' );
		if ( false === $last_ping ) {
			$this->ping_search_engines();
			set_transient( 'starter_sitemap_last_ping', time(), 600 );
		}
	}

	/**
	 * Invalidate all sitemap caches.
	 *
	 * @return void
	 */
	public function invalidate_cache() {
		delete_transient( 'starter_sitemap_index' );
		delete_transient( 'starter_sitemap_series' );
		delete_transient( 'starter_sitemap_genres' );
		delete_transient( 'starter_sitemap_authors' );

		// Invalidate all chapter sitemap page caches.
		$total_chapters = $this->get_total_published_chapters();
		$total_pages    = max( 1, (int) ceil( $total_chapters / self::CHAPTERS_PER_PAGE ) );

		for ( $i = 1; $i <= $total_pages + 1; $i++ ) {
			delete_transient( 'starter_sitemap_chapters_' . $i );
		}

		/**
		 * Fires after sitemap caches are invalidated.
		 */
		do_action( 'starter_sitemap_cache_invalidated' );
	}

	/*--------------------------------------------------------------------------
	 * Helper Methods
	 *------------------------------------------------------------------------*/

	/**
	 * Get the total count of published chapters.
	 *
	 * @return int
	 */
	private function get_total_published_chapters() {
		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(c.id)
			FROM {$chapters_table} AS c
			INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
			WHERE c.chapter_status = 'publish'
			AND p.post_status = 'publish'"
		);

		return $count ? (int) $count : 0;
	}

	/**
	 * Get the last modified date for sitemap content.
	 *
	 * @return string ISO 8601 date string.
	 */
	private function get_last_modified_date() {
		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$latest_chapter = $wpdb->get_var(
			"SELECT MAX(date_modified) FROM {$chapters_table} WHERE chapter_status = 'publish'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$latest_manga = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'wp-manga'
			)
		);

		$dates = array_filter( array( $latest_chapter, $latest_manga ) );

		if ( empty( $dates ) ) {
			return gmdate( 'c' );
		}

		$latest    = max( $dates );
		$date_obj  = DateTime::createFromFormat( 'Y-m-d H:i:s', $latest );

		return $date_obj ? $date_obj->format( 'c' ) : gmdate( 'c' );
	}
}

/*--------------------------------------------------------------------------
 * WordPress Core Sitemap Provider for Chapters (WP 5.5+)
 *------------------------------------------------------------------------*/

if ( class_exists( 'WP_Sitemaps_Provider' ) ) {

	/**
	 * Custom sitemap provider for manga chapters.
	 *
	 * Integrates with the WordPress core sitemap system (WP 5.5+)
	 * to include chapters stored in the custom database table.
	 *
	 * @since 1.0.0
	 */
	class Starter_Manga_Chapter_Sitemap_Provider extends WP_Sitemaps_Provider {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->name        = 'manga-chapters';
			$this->object_type = 'manga-chapter';
		}

		/**
		 * Get the URL list for a sitemap page.
		 *
		 * @param int    $page_num       Page number.
		 * @param string $object_subtype Optional subtype.
		 * @return array[] Array of sitemap URL entry arrays.
		 */
		public function get_url_list( $page_num, $object_subtype = '' ) {
			global $wpdb;

			$chapters_table = $wpdb->prefix . 'manga_chapters';
			$per_page       = $this->get_max_num_pages() > 0 ? 1000 : 1000;
			$offset         = ( $page_num - 1 ) * $per_page;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$chapters = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.chapter_number, c.date_created, c.date_modified,
							p.post_name AS manga_slug
					FROM {$chapters_table} AS c
					INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
					WHERE c.chapter_status = 'publish'
					AND p.post_status = 'publish'
					ORDER BY c.date_created DESC
					LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);

			$url_list = array();

			foreach ( $chapters as $chapter ) {
				$chapter_slug = 'chapter-' . $chapter->chapter_number;
				$url          = home_url( '/manga/' . $chapter->manga_slug . '/' . $chapter_slug . '/' );
				$lastmod      = $chapter->date_modified ? $chapter->date_modified : $chapter->date_created;
				$date_obj     = DateTime::createFromFormat( 'Y-m-d H:i:s', $lastmod );

				$entry = array(
					'loc' => $url,
				);

				if ( $date_obj ) {
					$entry['lastmod'] = $date_obj->format( 'c' );
				}

				$url_list[] = $entry;
			}

			return $url_list;
		}

		/**
		 * Get the maximum number of sitemap pages.
		 *
		 * @param string $object_subtype Optional subtype.
		 * @return int
		 */
		public function get_max_num_pages( $object_subtype = '' ) {
			global $wpdb;

			$chapters_table = $wpdb->prefix . 'manga_chapters';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				"SELECT COUNT(c.id)
				FROM {$chapters_table} AS c
				INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
				WHERE c.chapter_status = 'publish'
				AND p.post_status = 'publish'"
			);

			$total = $count ? (int) $count : 0;

			return (int) ceil( $total / 1000 );
		}
	}
}
