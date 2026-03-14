<?php
/**
 * Project Alpha - functions.php
 *
 * Main theme bootstrap file. Defines constants, loads the environment
 * configuration, requires all includes, and boots the theme via the
 * Alpha_Theme class.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*--------------------------------------------------------------
 * 1. Constants
 *-------------------------------------------------------------*/

define( 'STARTER_THEME_VERSION', '1.0.0' );
define( 'STARTER_THEME_DIR', get_template_directory() );
define( 'STARTER_THEME_URI', get_template_directory_uri() );

/*--------------------------------------------------------------
 * 2. Environment loader
 *-------------------------------------------------------------*/

require_once STARTER_THEME_DIR . '/inc/core/class-env-loader.php';

$starter_env = Starter_Env_Loader::get_instance();
$starter_env->init();

/*--------------------------------------------------------------
 * 3. Include files (order matters)
 *-------------------------------------------------------------*/

// Core.
$starter_core_includes = array(
	'/inc/core/theme-setup.php',
	'/inc/core/enqueue.php',
	'/inc/core/nav-menus.php',
	'/inc/core/sidebars.php',
	'/inc/core/ajax-search.php',
	'/inc/core/template-tags.php',
	'/inc/core/template-functions.php',
	'/inc/core/customizer.php',
);

// Feature modules.
$starter_module_includes = array(
	'/inc/manga/manga-functions.php',
	'/inc/novel/novel-functions.php',
	'/inc/video/video-functions.php',
	'/inc/user/user-functions.php',
	'/inc/widgets/widgets.php',
	'/inc/shortcodes/shortcodes.php',
	'/inc/seo/seo-functions.php',
	'/inc/ads/ads-functions.php',
	'/inc/monetization/monetization-functions.php',
	'/inc/protection/content-protection.php',
	'/inc/storage/storage-functions.php',
	'/inc/scheduler/scheduler-functions.php',
);

foreach ( $starter_core_includes as $file ) {
	$filepath = STARTER_THEME_DIR . $file;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}

foreach ( $starter_module_includes as $file ) {
	$filepath = STARTER_THEME_DIR . $file;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}

/*--------------------------------------------------------------
 * 4. Alpha_Theme — main bootstrap class
 *-------------------------------------------------------------*/

if ( ! class_exists( 'Alpha_Theme' ) ) {

	/**
	 * Class Alpha_Theme
	 *
	 * Central orchestrator that registers all WordPress hooks
	 * needed to set up the theme.
	 */
	class Alpha_Theme {

		/**
		 * Singleton instance.
		 *
		 * @var Alpha_Theme|null
		 */
		private static $instance = null;

		/**
		 * Get singleton instance.
		 *
		 * @return Alpha_Theme
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor — wire up all hooks.
		 */
		private function __construct() {
			add_action( 'after_setup_theme', array( $this, 'setup' ) );
			add_action( 'widgets_init', array( $this, 'register_sidebars' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_dark_mode' ) );
			add_action( 'wp_ajax_starter_search', array( $this, 'ajax_search' ) );
			add_action( 'wp_ajax_nopriv_starter_search', array( $this, 'ajax_search' ) );
		}

		/**
		 * Theme setup — runs on after_setup_theme.
		 *
		 * @return void
		 */
		public function setup() {
			// Text domain.
			load_theme_textdomain( 'starter-theme', STARTER_THEME_DIR . '/languages' );

			// Theme supports.
			add_theme_support( 'automatic-feed-links' );
			add_theme_support( 'title-tag' );
			add_theme_support( 'post-thumbnails' );
			add_theme_support( 'html5', array(
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'style',
				'script',
			) );
			add_theme_support( 'customize-selective-refresh-widgets' );
			add_theme_support( 'wp-block-styles' );
			add_theme_support( 'responsive-embeds' );
			add_theme_support( 'custom-logo', array(
				'height'      => 60,
				'width'       => 200,
				'flex-height' => true,
				'flex-width'  => true,
			) );

			// Image sizes.
			add_image_size( 'starter-cover', 300, 450, true );
			add_image_size( 'starter-wide', 800, 450, true );
			add_image_size( 'starter-thumb', 150, 225, true );

			// Navigation menus.
			register_nav_menus( array(
				'primary'   => esc_html__( 'Primary Menu', 'starter-theme' ),
				'footer'    => esc_html__( 'Footer Menu', 'starter-theme' ),
				'mobile'    => esc_html__( 'Mobile Menu', 'starter-theme' ),
			) );

			// Content width.
			if ( ! isset( $GLOBALS['content_width'] ) ) {
				$GLOBALS['content_width'] = 1140;
			}
		}

		/**
		 * Register widget areas.
		 *
		 * @return void
		 */
		public function register_sidebars() {
			register_sidebar( array(
				'name'          => esc_html__( 'Primary Sidebar', 'starter-theme' ),
				'id'            => 'sidebar-1',
				'description'   => esc_html__( 'Add widgets here to appear in the sidebar.', 'starter-theme' ),
				'before_widget' => '<section id="%1$s" class="widget %2$s">',
				'after_widget'  => '</section>',
				'before_title'  => '<h3 class="widget-title">',
				'after_title'   => '</h3>',
			) );

			// Footer widget columns.
			for ( $i = 1; $i <= 3; $i++ ) {
				register_sidebar( array(
					/* translators: %d: footer column number */
					'name'          => sprintf( esc_html__( 'Footer Column %d', 'starter-theme' ), $i ),
					'id'            => 'footer-' . $i,
					'description'   => sprintf(
						/* translators: %d: footer column number */
						esc_html__( 'Footer widget area column %d.', 'starter-theme' ),
						$i
					),
					'before_widget' => '<div id="%1$s" class="widget %2$s">',
					'after_widget'  => '</div>',
					'before_title'  => '<h4 class="widget-title">',
					'after_title'   => '</h4>',
				) );
			}
		}

		/**
		 * Enqueue front-end styles and scripts.
		 *
		 * @return void
		 */
		public function enqueue_assets() {
			// Main stylesheet.
			wp_enqueue_style(
				'starter-theme-style',
				STARTER_THEME_URI . '/assets/css/main.css',
				array(),
				STARTER_THEME_VERSION
			);

			// RTL stylesheet.
			wp_style_add_data( 'starter-theme-style', 'rtl', 'replace' );

			// Main script.
			wp_enqueue_script(
				'starter-theme-script',
				STARTER_THEME_URI . '/assets/js/main.js',
				array( 'jquery' ),
				STARTER_THEME_VERSION,
				true
			);

			// Localize for AJAX.
			wp_localize_script( 'starter-theme-script', 'starterTheme', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'starter_search_nonce' ),
				'i18n'    => array(
					'searchPlaceholder' => esc_html__( 'Search manga, novels, videos...', 'starter-theme' ),
					'noResults'         => esc_html__( 'No results found.', 'starter-theme' ),
					'loading'           => esc_html__( 'Loading...', 'starter-theme' ),
				),
			) );
		}

		/**
		 * Enqueue dark-mode script that persists user preference.
		 *
		 * @return void
		 */
		public function enqueue_dark_mode() {
			wp_enqueue_script(
				'starter-dark-mode',
				STARTER_THEME_URI . '/assets/js/dark-mode.js',
				array(),
				STARTER_THEME_VERSION,
				false // Load in head so body class is applied early.
			);
		}

		/**
		 * AJAX search handler.
		 *
		 * @return void
		 */
		public function ajax_search() {
			check_ajax_referer( 'starter_search_nonce', 'nonce' );

			$query_str = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

			if ( empty( $query_str ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Please enter a search term.', 'starter-theme' ) ) );
			}

			$results = new WP_Query( array(
				'post_type'      => array( 'post', 'manga', 'novel', 'video' ),
				'post_status'    => 'publish',
				's'              => $query_str,
				'posts_per_page' => 5,
			) );

			$items = array();

			if ( $results->have_posts() ) {
				while ( $results->have_posts() ) {
					$results->the_post();
					$items[] = array(
						'title'     => get_the_title(),
						'url'       => get_permalink(),
						'thumbnail' => get_the_post_thumbnail_url( get_the_ID(), 'starter-thumb' ),
						'type'      => get_post_type(),
					);
				}
				wp_reset_postdata();
			}

			wp_send_json_success( array( 'items' => $items ) );
		}
	}

	// Boot the theme.
	Alpha_Theme::get_instance();
}
