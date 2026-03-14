<?php
/**
 * Asset enqueue manager.
 *
 * Handles enqueueing all CSS and JavaScript files for both front-end
 * and admin, including conditional loading for reader pages.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Enqueue
 */
class Starter_Enqueue {

	/**
	 * Theme version for cache busting.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Theme directory URI.
	 *
	 * @var string
	 */
	private $theme_uri;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$theme           = wp_get_theme();
		$this->version   = $theme->get( 'Version' ) ? $theme->get( 'Version' ) : '1.0.0';
		$this->theme_uri = get_template_directory_uri();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
	}

	/**
	 * Enqueue front-end stylesheets.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		// Main stylesheet.
		wp_enqueue_style(
			'starter-main',
			$this->theme_uri . '/assets/css/main.css',
			array(),
			$this->version
		);

		// Dark mode.
		wp_enqueue_style(
			'starter-dark-mode',
			$this->theme_uri . '/assets/css/dark-mode.css',
			array( 'starter-main' ),
			$this->version
		);

		// Glassmorphism UI.
		wp_enqueue_style(
			'starter-glassmorphism',
			$this->theme_uri . '/assets/css/glassmorphism.css',
			array( 'starter-main' ),
			$this->version
		);

		// Responsive.
		wp_enqueue_style(
			'starter-responsive',
			$this->theme_uri . '/assets/css/responsive.css',
			array( 'starter-main' ),
			$this->version
		);

		// RTL support (conditional).
		if ( is_rtl() ) {
			wp_enqueue_style(
				'starter-rtl',
				$this->theme_uri . '/assets/css/rtl.css',
				array( 'starter-main' ),
				$this->version
			);
		}

		// Reader CSS — only on manga / novel reading pages.
		if ( $this->is_reading_page() ) {
			wp_enqueue_style(
				'starter-reader',
				$this->theme_uri . '/assets/css/reader.css',
				array( 'starter-main' ),
				$this->version
			);
		}

		// WooCommerce compatibility CSS.
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_style(
				'starter-woocommerce',
				$this->theme_uri . '/assets/css/woocommerce.css',
				array( 'starter-main' ),
				$this->version
			);
		}
	}

	/**
	 * Enqueue front-end scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		// Main theme script.
		wp_enqueue_script(
			'starter-main',
			$this->theme_uri . '/assets/js/main.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Navigation / mobile menu.
		wp_enqueue_script(
			'starter-navigation',
			$this->theme_uri . '/assets/js/navigation.js',
			array( 'jquery', 'starter-main' ),
			$this->version,
			true
		);

		// Dark mode toggle.
		wp_enqueue_script(
			'starter-dark-mode',
			$this->theme_uri . '/assets/js/dark-mode.js',
			array( 'jquery', 'starter-main' ),
			$this->version,
			true
		);

		// Lazy loading / infinite scroll.
		wp_enqueue_script(
			'starter-lazy-load',
			$this->theme_uri . '/assets/js/lazy-load.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// AJAX handler.
		wp_enqueue_script(
			'starter-ajax',
			$this->theme_uri . '/assets/js/ajax.js',
			array( 'jquery', 'starter-main' ),
			$this->version,
			true
		);

		// Reader JS — only on reading pages.
		if ( $this->is_reading_page() ) {
			wp_enqueue_script(
				'starter-reader',
				$this->theme_uri . '/assets/js/reader.js',
				array( 'jquery', 'starter-main' ),
				$this->version,
				true
			);

			wp_enqueue_script(
				'starter-keyboard-nav',
				$this->theme_uri . '/assets/js/keyboard-nav.js',
				array( 'jquery', 'starter-reader' ),
				$this->version,
				true
			);
		}

		// Video player — only on video pages.
		if ( $this->is_video_page() ) {
			wp_enqueue_script(
				'starter-video-player',
				$this->theme_uri . '/assets/js/video-player.js',
				array( 'jquery', 'starter-main' ),
				$this->version,
				true
			);
		}

		// Comment script (WordPress built-in).
		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}

		// Localize script data for AJAX, nonce, and theme options.
		wp_localize_script( 'starter-main', 'starterTheme', $this->get_localized_data() );
	}

	/**
	 * Enqueue admin-specific styles and scripts.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function admin_enqueue( $hook_suffix ) {
		// Global admin styles.
		wp_enqueue_style(
			'starter-admin',
			$this->theme_uri . '/assets/css/admin.css',
			array(),
			$this->version
		);

		// Admin scripts on theme-specific pages only.
		$theme_pages = array(
			'toplevel_page_starter-theme',
			'starter-theme_page_starter-manga',
			'starter-theme_page_starter-novel',
			'starter-theme_page_starter-video',
			'starter-theme_page_starter-settings',
			'post.php',
			'post-new.php',
		);

		if ( in_array( $hook_suffix, $theme_pages, true ) ) {
			wp_enqueue_script(
				'starter-admin',
				$this->theme_uri . '/assets/js/admin.js',
				array( 'jquery', 'wp-color-picker' ),
				$this->version,
				true
			);

			wp_enqueue_style( 'wp-color-picker' );

			wp_localize_script( 'starter-admin', 'starterAdmin', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'starter_admin_nonce' ),
			) );
		}

		// Media uploader on post edit screens.
		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Build the localized data array for front-end scripts.
	 *
	 * @return array
	 */
	private function get_localized_data() {
		$data = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'starter_nonce' ),
			'themeUrl'  => $this->theme_uri,
			'homeUrl'   => home_url( '/' ),
			'isLoggedIn' => is_user_logged_in(),
			'i18n'      => array(
				'loading'  => esc_html__( 'Loading...', 'starter-theme' ),
				'error'    => esc_html__( 'An error occurred. Please try again.', 'starter-theme' ),
				'confirm'  => esc_html__( 'Are you sure?', 'starter-theme' ),
				'noMore'   => esc_html__( 'No more items to load.', 'starter-theme' ),
				'added'    => esc_html__( 'Added to bookmarks.', 'starter-theme' ),
				'removed'  => esc_html__( 'Removed from bookmarks.', 'starter-theme' ),
			),
		);

		// User-specific data.
		if ( is_user_logged_in() ) {
			$current_user   = wp_get_current_user();
			$data['userId'] = $current_user->ID;
			$data['role']   = (array) $current_user->roles;
		}

		// Theme options.
		$data['options'] = array(
			'darkMode'      => get_theme_mod( 'starter_default_dark_mode', false ),
			'readerMode'    => get_theme_mod( 'starter_default_reader_mode', 'vertical' ),
			'lazyLoad'      => get_theme_mod( 'starter_enable_lazy_load', true ),
			'infiniteScroll' => get_theme_mod( 'starter_infinite_scroll', false ),
			'rtl'           => is_rtl(),
		);

		// Reading page specific data.
		if ( $this->is_reading_page() ) {
			$data['reader'] = array(
				'postId'     => get_the_ID(),
				'prevChapter' => $this->get_adjacent_chapter_url( 'prev' ),
				'nextChapter' => $this->get_adjacent_chapter_url( 'next' ),
			);
		}

		return apply_filters( 'starter_localized_data', $data );
	}

	/**
	 * Check if the current page is a manga or novel reading page.
	 *
	 * @return bool
	 */
	private function is_reading_page() {
		if ( is_singular( 'starter_chapter' ) || is_singular( 'starter_novel_chapter' ) ) {
			return true;
		}

		// Support for custom page template.
		if ( is_page_template( 'templates/reader.php' ) ) {
			return true;
		}

		return (bool) apply_filters( 'starter_is_reading_page', false );
	}

	/**
	 * Check if the current page is a video page.
	 *
	 * @return bool
	 */
	private function is_video_page() {
		if ( is_singular( 'starter_video' ) || is_singular( 'starter_episode' ) ) {
			return true;
		}

		return (bool) apply_filters( 'starter_is_video_page', false );
	}

	/**
	 * Get the URL for an adjacent chapter (previous or next).
	 *
	 * @param string $direction Either 'prev' or 'next'.
	 * @return string URL or empty string.
	 */
	private function get_adjacent_chapter_url( $direction = 'next' ) {
		$is_prev = 'prev' === $direction;
		$post    = get_adjacent_post( true, '', $is_prev );

		if ( $post instanceof WP_Post ) {
			return esc_url( get_permalink( $post ) );
		}

		return '';
	}
}
