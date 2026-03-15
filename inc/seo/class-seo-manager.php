<?php
/**
 * SEO Manager for manga, novel, and video pages.
 *
 * Handles meta tags, Open Graph, Twitter Cards, canonical URLs,
 * robots directives, breadcrumbs, sitemap integration, hreflang,
 * AMP support, and custom RSS feeds for chapter releases.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_SEO_Manager
 *
 * Central SEO orchestrator for the theme. Detects Yoast / RankMath and
 * defers meta-tag output when either plugin is active, while still
 * injecting manga-specific Schema (handled by Starter_Schema_Markup).
 */
class Starter_SEO_Manager {

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @var bool
	 */
	private $yoast_active = false;

	/**
	 * Whether RankMath is active.
	 *
	 * @var bool
	 */
	private $rankmath_active = false;

	/**
	 * Supported post types for SEO features.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'manga', 'novel', 'video' );

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp', array( $this, 'detect_seo_plugins' ) );
		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 1 );
		add_action( 'wp_head', array( $this, 'output_open_graph' ), 2 );
		add_action( 'wp_head', array( $this, 'output_twitter_cards' ), 3 );
		add_action( 'wp_head', array( $this, 'output_canonical' ), 4 );
		add_action( 'wp_head', array( $this, 'output_robots' ), 5 );
		add_action( 'wp_head', array( $this, 'output_hreflang' ), 6 );
		add_action( 'wp_head', array( $this, 'output_amp_meta' ), 7 );
		add_action( 'wp_head', array( $this, 'output_breadcrumb_schema' ), 8 );

		// Sitemap integration.
		add_filter( 'wp_sitemaps_post_types', array( $this, 'add_to_sitemap' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_query_args' ), 10, 2 );

		// Custom RSS feed for chapter releases.
		add_action( 'init', array( $this, 'register_chapter_feed' ) );
		add_action( 'pre_get_posts', array( $this, 'chapter_feed_query' ) );

		// Admin: per-manga robots meta box.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_robots_meta' ) );
	}

	/*--------------------------------------------------------------------------
	 * SEO Plugin Detection
	 *------------------------------------------------------------------------*/

	/**
	 * Detect whether Yoast SEO or RankMath is active.
	 *
	 * @return void
	 */
	public function detect_seo_plugins() {
		$this->yoast_active    = defined( 'WPSEO_VERSION' );
		$this->rankmath_active = class_exists( 'RankMath' );
	}

	/**
	 * Check if an external SEO plugin handles meta output.
	 *
	 * @return bool
	 */
	private function external_seo_handles_meta() {
		return $this->yoast_active || $this->rankmath_active;
	}

	/**
	 * Whether the current post is a supported type.
	 *
	 * @return bool
	 */
	private function is_supported_singular() {
		return is_singular( $this->post_types );
	}

	/*--------------------------------------------------------------------------
	 * Meta Title & Description
	 *------------------------------------------------------------------------*/

	/**
	 * Output meta title and description tags.
	 *
	 * If Yoast or RankMath is active, skip meta output (they handle it).
	 *
	 * @return void
	 */
	public function output_meta_tags() {
		if ( $this->external_seo_handles_meta() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$title       = $this->generate_meta_title( $post );
		$description = $this->generate_meta_description( $post );

		if ( $title ) {
			printf(
				'<meta name="title" content="%s" />' . "\n",
				esc_attr( $title )
			);
		}

		if ( $description ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}
	}

	/**
	 * Auto-generate a meta title from manga/novel/video metadata.
	 *
	 * @param WP_Post $post The current post.
	 * @return string
	 */
	public function generate_meta_title( $post ) {
		$type   = get_post_type( $post );
		$title  = get_the_title( $post );
		$status = get_post_meta( $post->ID, '_starter_status', true );

		$parts = array( $title );

		if ( $status ) {
			/* translators: %s: manga/novel status */
			$parts[] = sprintf( esc_html__( 'Status: %s', 'starter-theme' ), $status );
		}

		$parts[] = get_bloginfo( 'name' );

		/**
		 * Filter the auto-generated meta title.
		 *
		 * @param string  $meta_title Generated title.
		 * @param WP_Post $post       Current post.
		 */
		return apply_filters( 'starter_seo_meta_title', implode( ' - ', $parts ), $post );
	}

	/**
	 * Auto-generate a meta description from manga/novel/video metadata.
	 *
	 * @param WP_Post $post The current post.
	 * @return string
	 */
	public function generate_meta_description( $post ) {
		$description = get_post_meta( $post->ID, '_starter_description', true );

		if ( empty( $description ) ) {
			$description = wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		if ( empty( $description ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
		}

		$type    = get_post_type( $post );
		$genres  = wp_get_object_terms( $post->ID, 'genre', array( 'fields' => 'names' ) );
		$authors = get_post_meta( $post->ID, '_starter_author', true );

		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$genre_str = implode( ', ', array_slice( $genres, 0, 3 ) );
			/* translators: 1: content type, 2: title, 3: genres */
			$description = sprintf(
				esc_html__( 'Read %1$s %2$s online. Genres: %3$s. %4$s', 'starter-theme' ),
				ucfirst( $type ),
				get_the_title( $post ),
				$genre_str,
				$description
			);
		}

		$description = mb_substr( $description, 0, 160 );

		/**
		 * Filter the auto-generated meta description.
		 *
		 * @param string  $description Generated description.
		 * @param WP_Post $post        Current post.
		 */
		return apply_filters( 'starter_seo_meta_description', $description, $post );
	}

	/*--------------------------------------------------------------------------
	 * Open Graph Tags
	 *------------------------------------------------------------------------*/

	/**
	 * Output Open Graph meta tags.
	 *
	 * @return void
	 */
	public function output_open_graph() {
		if ( $this->external_seo_handles_meta() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$og = $this->get_open_graph_data( $post );

		foreach ( $og as $property => $content ) {
			if ( empty( $content ) ) {
				continue;
			}
			printf(
				'<meta property="%s" content="%s" />' . "\n",
				esc_attr( $property ),
				esc_attr( $content )
			);
		}
	}

	/**
	 * Build Open Graph data for a post.
	 *
	 * @param WP_Post $post The current post.
	 * @return array<string,string>
	 */
	private function get_open_graph_data( $post ) {
		$type = get_post_type( $post );

		$og_type = 'article';
		if ( 'video' === $type ) {
			$og_type = 'video.other';
		} elseif ( in_array( $type, array( 'manga', 'novel' ), true ) ) {
			$og_type = 'book';
		}

		$image = '';
		if ( has_post_thumbnail( $post ) ) {
			$image = get_the_post_thumbnail_url( $post, 'large' );
		}

		$data = array(
			'og:title'       => $this->generate_meta_title( $post ),
			'og:description' => $this->generate_meta_description( $post ),
			'og:image'       => $image,
			'og:type'        => $og_type,
			'og:url'         => get_permalink( $post ),
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		/**
		 * Filter Open Graph data.
		 *
		 * @param array   $data Open Graph key-value pairs.
		 * @param WP_Post $post Current post.
		 */
		return apply_filters( 'starter_seo_open_graph', $data, $post );
	}

	/*--------------------------------------------------------------------------
	 * Twitter Card Tags
	 *------------------------------------------------------------------------*/

	/**
	 * Output Twitter Card meta tags.
	 *
	 * @return void
	 */
	public function output_twitter_cards() {
		if ( $this->external_seo_handles_meta() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$twitter = $this->get_twitter_card_data( $post );

		foreach ( $twitter as $name => $content ) {
			if ( empty( $content ) ) {
				continue;
			}
			printf(
				'<meta name="%s" content="%s" />' . "\n",
				esc_attr( $name ),
				esc_attr( $content )
			);
		}
	}

	/**
	 * Build Twitter Card data for a post.
	 *
	 * @param WP_Post $post The current post.
	 * @return array<string,string>
	 */
	private function get_twitter_card_data( $post ) {
		$image = '';
		if ( has_post_thumbnail( $post ) ) {
			$image = get_the_post_thumbnail_url( $post, 'large' );
		}

		$data = array(
			'twitter:card'        => $image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $this->generate_meta_title( $post ),
			'twitter:description' => $this->generate_meta_description( $post ),
			'twitter:image'       => $image,
		);

		/**
		 * Filter Twitter Card data.
		 *
		 * @param array   $data Twitter Card key-value pairs.
		 * @param WP_Post $post Current post.
		 */
		return apply_filters( 'starter_seo_twitter_card', $data, $post );
	}

	/*--------------------------------------------------------------------------
	 * Canonical URL
	 *------------------------------------------------------------------------*/

	/**
	 * Output canonical URL tag.
	 *
	 * @return void
	 */
	public function output_canonical() {
		if ( $this->external_seo_handles_meta() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$canonical = $this->get_canonical_url( $post );

		if ( $canonical ) {
			printf(
				'<link rel="canonical" href="%s" />' . "\n",
				esc_url( $canonical )
			);
		}
	}

	/**
	 * Determine the canonical URL for a post.
	 *
	 * For paginated chapter lists, point canonical to page 1.
	 *
	 * @param WP_Post $post The current post.
	 * @return string
	 */
	public function get_canonical_url( $post ) {
		$canonical = get_permalink( $post );

		/**
		 * Filter the canonical URL.
		 *
		 * @param string  $canonical Canonical URL.
		 * @param WP_Post $post      Current post.
		 */
		return apply_filters( 'starter_seo_canonical_url', $canonical, $post );
	}

	/*--------------------------------------------------------------------------
	 * Meta Robots
	 *------------------------------------------------------------------------*/

	/**
	 * Output meta robots tag.
	 *
	 * @return void
	 */
	public function output_robots() {
		if ( $this->external_seo_handles_meta() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$robots = $this->get_robots_directives( $post );

		if ( ! empty( $robots ) ) {
			printf(
				'<meta name="robots" content="%s" />' . "\n",
				esc_attr( implode( ', ', $robots ) )
			);
		}
	}

	/**
	 * Get robots directives for a post.
	 *
	 * @param WP_Post $post The current post.
	 * @return string[]
	 */
	public function get_robots_directives( $post ) {
		$noindex = get_post_meta( $post->ID, '_starter_noindex', true );

		$directives = array();

		if ( $noindex ) {
			$directives[] = 'noindex';
			$directives[] = 'nofollow';
		} else {
			$directives[] = 'index';
			$directives[] = 'follow';
		}

		$directives[] = 'max-image-preview:large';
		$directives[] = 'max-snippet:-1';

		/**
		 * Filter robots directives.
		 *
		 * @param string[] $directives Robots directives.
		 * @param WP_Post  $post       Current post.
		 */
		return apply_filters( 'starter_seo_robots', $directives, $post );
	}

	/*--------------------------------------------------------------------------
	 * Hreflang Tags (Multilingual)
	 *------------------------------------------------------------------------*/

	/**
	 * Output hreflang tags for multilingual support.
	 *
	 * Works with Polylang, WPML, or custom language meta.
	 *
	 * @return void
	 */
	public function output_hreflang() {
		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$translations = $this->get_translations( $post );

		if ( empty( $translations ) ) {
			return;
		}

		foreach ( $translations as $lang => $url ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang ),
				esc_url( $url )
			);
		}

		// x-default points to current post.
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( get_permalink( $post ) )
		);
	}

	/**
	 * Retrieve translation URLs for a post.
	 *
	 * Supports Polylang, WPML, and a custom meta fallback.
	 *
	 * @param WP_Post $post The current post.
	 * @return array<string,string> Language code => URL.
	 */
	private function get_translations( $post ) {
		$translations = array();

		// Polylang support.
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$pll_translations = pll_get_post_translations( $post->ID );
			foreach ( $pll_translations as $lang => $translated_id ) {
				if ( (int) $translated_id !== $post->ID ) {
					$url = get_permalink( $translated_id );
					if ( $url ) {
						$translations[ $lang ] = $url;
					}
				}
			}
			return $translations;
		}

		// WPML support.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$languages = apply_filters( 'wpml_active_languages', array(), array( 'skip_missing' => 1 ) );
			if ( is_array( $languages ) ) {
				foreach ( $languages as $lang_code => $lang_data ) {
					$translated_id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $lang_code );
					if ( $translated_id && (int) $translated_id !== $post->ID ) {
						$url = get_permalink( $translated_id );
						if ( $url ) {
							$translations[ $lang_code ] = $url;
						}
					}
				}
			}
			return $translations;
		}

		// Custom meta fallback: _starter_translations = array( 'en' => url, 'ja' => url ).
		$custom = get_post_meta( $post->ID, '_starter_translations', true );
		if ( is_array( $custom ) ) {
			foreach ( $custom as $lang => $url ) {
				$translations[ sanitize_key( $lang ) ] = esc_url_raw( $url );
			}
		}

		/**
		 * Filter translation URLs used for hreflang output.
		 *
		 * @param array   $translations Language => URL pairs.
		 * @param WP_Post $post         Current post.
		 */
		return apply_filters( 'starter_seo_translations', $translations, $post );
	}

	/*--------------------------------------------------------------------------
	 * AMP Support
	 *------------------------------------------------------------------------*/

	/**
	 * Output AMP-compatible meta tags when serving an AMP page.
	 *
	 * @return void
	 */
	public function output_amp_meta() {
		if ( ! $this->is_amp_request() ) {
			return;
		}

		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		// AMP requires a canonical link to the non-AMP version.
		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( get_permalink( $post ) )
		);

		// Viewport for AMP.
		echo '<meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1" />' . "\n";

		// AMP boilerplate style hint.
		echo '<style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style>' . "\n";
		echo '<noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>' . "\n";

		/**
		 * Fires after AMP meta tags are output.
		 *
		 * @param WP_Post $post Current post.
		 */
		do_action( 'starter_seo_after_amp_meta', $post );
	}

	/**
	 * Check whether the current request is an AMP page.
	 *
	 * Supports the official AMP plugin's amp_is_request() and a
	 * query-parameter fallback (?amp=1).
	 *
	 * @return bool
	 */
	private function is_amp_request() {
		if ( function_exists( 'amp_is_request' ) ) {
			return amp_is_request();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['amp'] ) && '1' === $_GET['amp'];
	}

	/**
	 * Render a simplified AMP-compatible reader layout for a chapter.
	 *
	 * Intended to be called from a template when is_amp_request() is true.
	 *
	 * @param WP_Post $post The chapter/page post.
	 * @return string HTML output safe for AMP.
	 */
	public function get_amp_reader_layout( $post ) {
		$title   = esc_html( get_the_title( $post ) );
		$content = wp_kses( $post->post_content, array(
			'p'      => array(),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'a'      => array(
				'href'  => array(),
				'title' => array(),
			),
			'img'    => array(
				'src'    => array(),
				'alt'    => array(),
				'width'  => array(),
				'height' => array(),
			),
		) );

		// Replace <img> with <amp-img> for AMP validity.
		$content = preg_replace(
			'/<img\s([^>]*)\/?>/i',
			'<amp-img layout="responsive" $1></amp-img>',
			$content
		);

		$html  = '<article class="starter-amp-reader">' . "\n";
		$html .= '<h1 class="starter-amp-reader__title">' . $title . '</h1>' . "\n";
		$html .= '<div class="starter-amp-reader__content">' . $content . '</div>' . "\n";
		$html .= '</article>' . "\n";

		/**
		 * Filter the AMP reader layout HTML.
		 *
		 * @param string  $html AMP reader HTML.
		 * @param WP_Post $post Current post.
		 */
		return apply_filters( 'starter_seo_amp_reader_layout', $html, $post );
	}

	/*--------------------------------------------------------------------------
	 * Breadcrumbs (Structured Data + HTML)
	 *------------------------------------------------------------------------*/

	/**
	 * Output BreadcrumbList JSON-LD in wp_head.
	 *
	 * @return void
	 */
	public function output_breadcrumb_schema() {
		if ( ! $this->is_supported_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$items = $this->build_breadcrumb_items( $post );

		if ( empty( $items ) ) {
			return;
		}

		$breadcrumb_list = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(),
		);

		$position = 1;
		foreach ( $items as $item ) {
			$breadcrumb_list['itemListElement'][] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
			$position++;
		}

		$json = wp_json_encode( $breadcrumb_list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( $json ) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Build breadcrumb trail items.
	 *
	 * @param WP_Post $post The current post.
	 * @return array<int,array{name:string,url:string}>
	 */
	private function build_breadcrumb_items( $post ) {
		$items = array();

		// Home.
		$items[] = array(
			'name' => esc_html__( 'Home', 'starter-theme' ),
			'url'  => home_url( '/' ),
		);

		$type = get_post_type( $post );

		// Post type archive.
		$archive_url = get_post_type_archive_link( $type );
		if ( $archive_url ) {
			$post_type_obj = get_post_type_object( $type );
			$items[]       = array(
				'name' => $post_type_obj ? $post_type_obj->labels->name : ucfirst( $type ),
				'url'  => $archive_url,
			);
		}

		// Genre term (first one).
		$genres = wp_get_object_terms( $post->ID, 'genre', array( 'fields' => 'all' ) );
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

		// Parent manga/novel (for chapter posts).
		$parent_id = get_post_meta( $post->ID, '_starter_parent_series', true );
		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( $parent ) {
				$items[] = array(
					'name' => get_the_title( $parent ),
					'url'  => get_permalink( $parent ),
				);
			}
		}

		// Current page.
		$items[] = array(
			'name' => get_the_title( $post ),
			'url'  => get_permalink( $post ),
		);

		/**
		 * Filter breadcrumb items.
		 *
		 * @param array   $items Breadcrumb items.
		 * @param WP_Post $post  Current post.
		 */
		return apply_filters( 'starter_seo_breadcrumb_items', $items, $post );
	}

	/**
	 * Render breadcrumb HTML for templates.
	 *
	 * @param WP_Post|null $post Optional post object; defaults to global post.
	 * @return string Breadcrumb HTML with microdata.
	 */
	public function render_breadcrumbs( $post = null ) {
		if ( ! $post ) {
			$post = get_post();
		}

		if ( ! $post ) {
			return '';
		}

		$items = $this->build_breadcrumb_items( $post );

		if ( empty( $items ) ) {
			return '';
		}

		$html  = '<nav class="starter-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'starter-theme' ) . '">';
		$html .= '<ol class="starter-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">';

		$total = count( $items );
		foreach ( $items as $index => $item ) {
			$position = $index + 1;
			$is_last  = ( $position === $total );

			$html .= '<li class="starter-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

			if ( $is_last ) {
				$html .= '<span itemprop="name" aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			} else {
				$html .= '<a itemprop="item" href="' . esc_url( $item['url'] ) . '">';
				$html .= '<span itemprop="name">' . esc_html( $item['name'] ) . '</span>';
				$html .= '</a>';
			}

			$html .= '<meta itemprop="position" content="' . esc_attr( $position ) . '" />';
			$html .= '</li>';

			if ( ! $is_last ) {
				$html .= '<li class="starter-breadcrumbs__separator" aria-hidden="true">&raquo;</li>';
			}
		}

		$html .= '</ol></nav>';

		/**
		 * Filter the breadcrumb HTML.
		 *
		 * @param string  $html Breadcrumb HTML.
		 * @param WP_Post $post Current post.
		 */
		return apply_filters( 'starter_seo_breadcrumb_html', $html, $post );
	}

	/*--------------------------------------------------------------------------
	 * Sitemap Integration
	 *------------------------------------------------------------------------*/

	/**
	 * Ensure our custom post types appear in the WordPress core sitemap.
	 *
	 * @param array $post_types Registered post types for sitemaps.
	 * @return array
	 */
	public function add_to_sitemap( $post_types ) {
		foreach ( $this->post_types as $type ) {
			if ( post_type_exists( $type ) && ! isset( $post_types[ $type ] ) ) {
				$post_types[ $type ] = get_post_type_object( $type );
			}
		}

		return $post_types;
	}

	/**
	 * Modify sitemap query to exclude noindex posts.
	 *
	 * @param array  $args      WP_Query arguments.
	 * @param string $post_type Current post type being queried.
	 * @return array
	 */
	public function sitemap_query_args( $args, $post_type ) {
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return $args;
		}

		$args['meta_query'] = isset( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$args['meta_query'][] = array(
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
		);

		return $args;
	}

	/*--------------------------------------------------------------------------
	 * RSS Feed for Chapter Releases
	 *------------------------------------------------------------------------*/

	/**
	 * Register the custom chapter feed endpoint.
	 *
	 * Accessible at /feed/manga-chapters/
	 *
	 * @return void
	 */
	public function register_chapter_feed() {
		add_feed( 'manga-chapters', array( $this, 'render_chapter_feed' ) );
	}

	/**
	 * Modify the query for chapter feed requests.
	 *
	 * @param WP_Query $query The current query.
	 * @return void
	 */
	public function chapter_feed_query( $query ) {
		if ( ! $query->is_main_query() || ! $query->is_feed() ) {
			return;
		}

		if ( 'manga-chapters' !== get_query_var( 'feed' ) ) {
			return;
		}

		$query->set( 'post_type', 'chapter' );
		$query->set( 'posts_per_page', 50 );
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}

	/**
	 * Render the manga chapters RSS feed.
	 *
	 * @return void
	 */
	public function render_chapter_feed() {
		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );

		$chapters = get_posts( array(
			'post_type'      => 'chapter',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => 'publish',
		) );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<rss version="2.0"
	xmlns:atom="http://www.w3.org/2005/Atom"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:starter="<?php echo esc_url( home_url( '/ns/manga-chapters' ) ); ?>"
>
<channel>
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php esc_html_e( 'Latest Chapters', 'starter-theme' ); ?></title>
	<link><?php echo esc_url( home_url( '/' ) ); ?></link>
	<description><?php echo esc_html( get_bloginfo( 'description' ) ); ?></description>
	<language><?php echo esc_attr( get_bloginfo( 'language' ) ); ?></language>
	<lastBuildDate><?php echo esc_html( gmdate( DATE_RSS ) ); ?></lastBuildDate>
	<atom:link href="<?php echo esc_url( home_url( '/feed/manga-chapters/' ) ); ?>" rel="self" type="application/rss+xml" />
<?php foreach ( $chapters as $chapter ) :
	$parent_id     = get_post_meta( $chapter->ID, '_starter_parent_series', true );
	$chapter_num   = get_post_meta( $chapter->ID, '_starter_chapter_number', true );
	$volume_num    = get_post_meta( $chapter->ID, '_starter_volume_number', true );
	$parent_title  = $parent_id ? get_the_title( $parent_id ) : '';
	$thumbnail_url = get_the_post_thumbnail_url( $chapter, 'medium' );
?>
	<item>
		<title><?php echo esc_html( get_the_title( $chapter ) ); ?></title>
		<link><?php echo esc_url( get_permalink( $chapter ) ); ?></link>
		<guid isPermaLink="true"><?php echo esc_url( get_permalink( $chapter ) ); ?></guid>
		<pubDate><?php echo esc_html( get_post_time( DATE_RSS, true, $chapter ) ); ?></pubDate>
		<description><![CDATA[<?php echo wp_kses_post( get_the_excerpt( $chapter ) ); ?>]]></description>
		<starter:seriesTitle><?php echo esc_html( $parent_title ); ?></starter:seriesTitle>
		<starter:chapterNumber><?php echo esc_html( $chapter_num ); ?></starter:chapterNumber>
<?php if ( $volume_num ) : ?>
		<starter:volumeNumber><?php echo esc_html( $volume_num ); ?></starter:volumeNumber>
<?php endif; ?>
<?php if ( $thumbnail_url ) : ?>
		<enclosure url="<?php echo esc_url( $thumbnail_url ); ?>" type="image/jpeg" />
<?php endif; ?>
	</item>
<?php endforeach; ?>
</channel>
</rss>
		<?php
	}

	/*--------------------------------------------------------------------------
	 * Admin Meta Boxes (Robots per Manga)
	 *------------------------------------------------------------------------*/

	/**
	 * Register the robots meta box on supported post types.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		foreach ( $this->post_types as $type ) {
			add_meta_box(
				'starter_seo_robots',
				esc_html__( 'SEO Robots Settings', 'starter-theme' ),
				array( $this, 'render_robots_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the robots meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_robots_meta_box( $post ) {
		wp_nonce_field( 'starter_seo_robots_nonce', 'starter_seo_robots_nonce_field' );

		$noindex = get_post_meta( $post->ID, '_starter_noindex', true );
		?>
		<label>
			<input type="checkbox" name="starter_noindex" value="1" <?php checked( $noindex, '1' ); ?> />
			<?php esc_html_e( 'Set this page to noindex (hide from search engines)', 'starter-theme' ); ?>
		</label>
		<?php
	}

	/**
	 * Save the robots meta value.
	 *
	 * @param int $post_id The post ID being saved.
	 * @return void
	 */
	public function save_robots_meta( $post_id ) {
		if ( ! isset( $_POST['starter_seo_robots_nonce_field'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['starter_seo_robots_nonce_field'] ) ), 'starter_seo_robots_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$noindex = isset( $_POST['starter_noindex'] ) ? '1' : '';
		update_post_meta( $post_id, '_starter_noindex', sanitize_text_field( $noindex ) );
	}
}
