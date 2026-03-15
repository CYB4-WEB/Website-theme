<?php
/**
 * Theme setup and configuration.
 *
 * Registers theme supports, navigation menus, widget areas,
 * image sizes, and content width for the manga/novel/video theme.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Theme_Setup
 */
class Starter_Theme_Setup {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'after_setup_theme', array( $this, 'setup_theme' ) );
		add_action( 'after_setup_theme', array( $this, 'set_content_width' ), 0 );
		add_action( 'widgets_init', array( $this, 'register_sidebars' ) );
		add_action( 'after_setup_theme', array( $this, 'register_image_sizes' ) );
	}

	/**
	 * Set up theme defaults and register support for various WordPress features.
	 *
	 * @return void
	 */
	public function setup_theme() {
		/*
		 * Make theme available for translation.
		 */
		load_theme_textdomain( 'starter-theme', get_template_directory() . '/languages' );

		/*
		 * Core theme supports.
		 */
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'html5', array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
			'navigation-widgets',
		) );
		add_theme_support( 'customize-selective-refresh-widgets' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'editor-styles' );

		/*
		 * WooCommerce support for premium features / coin purchases.
		 */
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );

		/*
		 * Register navigation menus.
		 */
		register_nav_menus( array(
			'primary'   => esc_html__( 'Primary Menu', 'starter-theme' ),
			'secondary' => esc_html__( 'Secondary Menu', 'starter-theme' ),
			'mobile'    => esc_html__( 'Mobile Menu', 'starter-theme' ),
			'footer'    => esc_html__( 'Footer Menu', 'starter-theme' ),
		) );

		/*
		 * Add editor stylesheet.
		 */
		add_editor_style( 'assets/css/editor-style.css' );
	}

	/**
	 * Set the content width global.
	 *
	 * @global int $content_width
	 * @return void
	 */
	public function set_content_width() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$GLOBALS['content_width'] = apply_filters( 'starter_content_width', 1140 );
	}

	/**
	 * Register custom image sizes for manga, novel, and video thumbnails.
	 *
	 * @return void
	 */
	public function register_image_sizes() {
		// Manga thumbnails.
		add_image_size( 'manga-thumb', 200, 300, true );
		add_image_size( 'manga-thumb-small', 110, 150, true );

		// Manga banner (series page hero).
		add_image_size( 'manga-banner', 900, 300, true );

		// Chapter thumbnails.
		add_image_size( 'chapter-thumb', 120, 90, true );

		// Novel cover (taller ratio).
		add_image_size( 'novel-cover', 200, 320, true );

		// Video poster.
		add_image_size( 'video-poster', 640, 360, true );

		// Slider / featured.
		add_image_size( 'featured-large', 820, 460, true );
		add_image_size( 'featured-medium', 400, 230, true );

		// Add custom sizes to the media library dropdown.
		add_filter( 'image_size_names_choose', array( $this, 'custom_image_size_names' ) );
	}

	/**
	 * Add custom image size names to the media library selector.
	 *
	 * @param array $sizes Existing size names.
	 * @return array
	 */
	public function custom_image_size_names( $sizes ) {
		return array_merge( $sizes, array(
			'manga-thumb'       => esc_html__( 'Manga Thumbnail', 'starter-theme' ),
			'manga-thumb-small' => esc_html__( 'Manga Thumbnail (Small)', 'starter-theme' ),
			'manga-banner'      => esc_html__( 'Manga Banner', 'starter-theme' ),
			'chapter-thumb'     => esc_html__( 'Chapter Thumbnail', 'starter-theme' ),
			'novel-cover'       => esc_html__( 'Novel Cover', 'starter-theme' ),
			'video-poster'      => esc_html__( 'Video Poster', 'starter-theme' ),
			'featured-large'    => esc_html__( 'Featured Large', 'starter-theme' ),
			'featured-medium'   => esc_html__( 'Featured Medium', 'starter-theme' ),
		) );
	}

	/**
	 * Register widget areas / sidebars.
	 *
	 * @return void
	 */
	public function register_sidebars() {
		$sidebars = array(
			array(
				'name' => esc_html__( 'Primary Sidebar', 'starter-theme' ),
				'id'   => 'sidebar-primary',
			),
			array(
				'name' => esc_html__( 'Secondary Sidebar', 'starter-theme' ),
				'id'   => 'sidebar-secondary',
			),
			array(
				'name' => esc_html__( 'Manga Sidebar', 'starter-theme' ),
				'id'   => 'sidebar-manga',
			),
			array(
				'name' => esc_html__( 'Novel Sidebar', 'starter-theme' ),
				'id'   => 'sidebar-novel',
			),
			array(
				'name' => esc_html__( 'Video Sidebar', 'starter-theme' ),
				'id'   => 'sidebar-video',
			),
			array(
				'name' => esc_html__( 'Footer Widget Area 1', 'starter-theme' ),
				'id'   => 'footer-1',
			),
			array(
				'name' => esc_html__( 'Footer Widget Area 2', 'starter-theme' ),
				'id'   => 'footer-2',
			),
			array(
				'name' => esc_html__( 'Footer Widget Area 3', 'starter-theme' ),
				'id'   => 'footer-3',
			),
			array(
				'name' => esc_html__( 'Footer Widget Area 4', 'starter-theme' ),
				'id'   => 'footer-4',
			),
			array(
				'name' => esc_html__( 'Header Widget Area', 'starter-theme' ),
				'id'   => 'header-widget',
			),
		);

		foreach ( $sidebars as $sidebar ) {
			register_sidebar( array(
				'name'          => $sidebar['name'],
				'id'            => $sidebar['id'],
				'description'   => sprintf(
					/* translators: %s: sidebar name */
					esc_html__( 'Widgets in the %s area.', 'starter-theme' ),
					$sidebar['name']
				),
				'before_widget' => '<div id="%1$s" class="widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<h3 class="widget-title">',
				'after_title'   => '</h3>',
			) );
		}
	}
}
