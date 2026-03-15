<?php
/**
 * Project Alpha - functions.php
 *
 * Master orchestrator for the starter-theme manga/novel/video WordPress theme.
 * Defines constants, loads environment config, requires all includes in the
 * correct dependency order, registers activation/deactivation hooks, sets up
 * rewrite rules, custom template routing, AJAX endpoints, and dark-mode
 * flash prevention.
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
define( 'STARTER_THEME_DIR',     get_template_directory() );
define( 'STARTER_THEME_URI',     get_template_directory_uri() );
define( 'STARTER_THEME_SLUG',    'starter-theme' );

/*--------------------------------------------------------------
 * 2. Environment loader (must come before everything else)
 *-------------------------------------------------------------*/

require_once STARTER_THEME_DIR . '/inc/core/class-env-loader.php';
Starter_Env_Loader::get_instance();

/*--------------------------------------------------------------
 * 3. Storage interface (must load before storage implementations)
 *-------------------------------------------------------------*/

require_once STARTER_THEME_DIR . '/inc/storage/interface-storage.php';

/*--------------------------------------------------------------
 * 4. Auto-load all PHP files from inc/ subdirectories
 *
 * Order matters: interfaces -> core -> storage -> content types
 * -> user -> monetization -> protection -> seo -> widgets
 * -> shortcodes -> ads -> scheduler
 *-------------------------------------------------------------*/

$starter_includes = array(

	// -- Core ------------------------------------------------------------------
	'/inc/core/class-theme-setup.php',
	'/inc/core/class-enqueue.php',
	'/inc/core/class-security.php',
	'/inc/core/class-encryption.php',
	'/inc/core/class-sample-data.php',

	// -- Storage (interface already loaded above) ------------------------------
	'/inc/storage/class-storage-manager.php',
	'/inc/storage/class-storage-local.php',
	'/inc/storage/class-storage-s3.php',
	'/inc/storage/class-storage-ftp.php',
	'/inc/storage/class-storage-external.php',

	// -- Manga -----------------------------------------------------------------
	'/inc/manga/class-manga-cpt.php',
	'/inc/manga/class-manga-chapter.php',
	'/inc/manga/class-manga-reader.php',
	'/inc/manga/class-manga-search.php',
	'/inc/manga/class-manga-rating.php',
	'/inc/manga/class-manga-bookmark.php',
	'/inc/manga/class-manga-history.php',
	'/inc/manga/class-manga-views.php',
	'/inc/manga/class-manga-import.php',

	// -- Novel -----------------------------------------------------------------
	'/inc/novel/class-novel-reader.php',
	'/inc/novel/class-novel-reading-tools.php',

	// -- Video -----------------------------------------------------------------
	'/inc/video/class-video-player.php',
	'/inc/video/class-video-embed.php',

	// -- User ------------------------------------------------------------------
	'/inc/user/class-user-auth.php',
	'/inc/user/class-user-roles.php',
	'/inc/user/class-user-settings.php',
	'/inc/user/class-user-upload.php',

	// -- Monetization ----------------------------------------------------------
	'/inc/monetization/class-coin-system.php',
	'/inc/monetization/class-revenue-share.php',
	'/inc/monetization/class-chapter-permissions.php',

	// -- Protection ------------------------------------------------------------
	'/inc/protection/class-chapter-protector.php',
	'/inc/protection/class-image-encryption.php',
	'/inc/protection/class-path-obfuscation.php',

	// -- SEO -------------------------------------------------------------------
	'/inc/seo/class-seo-manager.php',
	'/inc/seo/class-auto-keywords.php',
	'/inc/seo/class-schema-markup.php',
	'/inc/seo/class-manga-feed.php',
	'/inc/seo/class-manga-sitemap.php',

	// -- Widgets ---------------------------------------------------------------
	'/inc/widgets/class-hero-slider-widget.php',
	'/inc/widgets/class-manga-slider-widget.php',
	'/inc/widgets/class-popular-manga-widget.php',

	// -- Shortcodes ------------------------------------------------------------
	'/inc/shortcodes/class-shortcode-manager.php',

	// -- Ads -------------------------------------------------------------------
	'/inc/ads/class-ad-manager.php',

	// -- Scheduler -------------------------------------------------------------
	'/inc/scheduler/class-chapter-scheduler.php',
);

foreach ( $starter_includes as $file ) {
	$filepath = STARTER_THEME_DIR . $file;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}

/*--------------------------------------------------------------
 * 5. Theme activation hook
 *-------------------------------------------------------------*/

register_activation_hook( __FILE__, 'starter_theme_activate' );

/**
 * Runs on theme activation (switch_theme).
 *
 * Because themes do not support register_activation_hook the same way
 * plugins do, we hook into after_switch_theme instead.
 */
add_action( 'after_switch_theme', 'starter_theme_activate' );

function starter_theme_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	// -- Chapters table --------------------------------------------------------
	$table_chapters = $wpdb->prefix . 'starter_chapters';
	$sql_chapters   = "CREATE TABLE IF NOT EXISTS {$table_chapters} (
		id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		manga_id        BIGINT(20) UNSIGNED NOT NULL,
		chapter_number  FLOAT          NOT NULL DEFAULT 0,
		chapter_title   VARCHAR(255)   NOT NULL DEFAULT '',
		chapter_slug    VARCHAR(255)   NOT NULL DEFAULT '',
		chapter_type    VARCHAR(20)    NOT NULL DEFAULT 'image',
		chapter_content LONGTEXT       NULL,
		chapter_status  VARCHAR(20)    NOT NULL DEFAULT 'publish',
		is_premium      TINYINT(1)     NOT NULL DEFAULT 0,
		coin_price      INT(11)        NOT NULL DEFAULT 0,
		publish_date    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY manga_id (manga_id),
		KEY chapter_slug (chapter_slug),
		KEY chapter_status (chapter_status)
	) {$charset_collate};";

	// -- Ratings table ---------------------------------------------------------
	$table_ratings = $wpdb->prefix . 'starter_ratings';
	$sql_ratings   = "CREATE TABLE IF NOT EXISTS {$table_ratings} (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		manga_id    BIGINT(20) UNSIGNED NOT NULL,
		user_id     BIGINT(20) UNSIGNED NOT NULL,
		rating      TINYINT(1)     NOT NULL DEFAULT 0,
		created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY user_manga (user_id, manga_id),
		KEY manga_id (manga_id)
	) {$charset_collate};";

	// -- Views log table -------------------------------------------------------
	$table_views = $wpdb->prefix . 'starter_views_log';
	$sql_views   = "CREATE TABLE IF NOT EXISTS {$table_views} (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		manga_id    BIGINT(20) UNSIGNED NOT NULL,
		chapter_id  BIGINT(20) UNSIGNED NULL,
		user_id     BIGINT(20) UNSIGNED NULL,
		ip_address  VARCHAR(45)    NOT NULL DEFAULT '',
		viewed_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY manga_id (manga_id),
		KEY chapter_id (chapter_id),
		KEY viewed_at (viewed_at)
	) {$charset_collate};";

	// -- User coins table ------------------------------------------------------
	$table_coins = $wpdb->prefix . 'starter_user_coins';
	$sql_coins   = "CREATE TABLE IF NOT EXISTS {$table_coins} (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id     BIGINT(20) UNSIGNED NOT NULL,
		balance     INT(11)        NOT NULL DEFAULT 0,
		updated_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY user_id (user_id)
	) {$charset_collate};";

	// -- Coin transactions table -----------------------------------------------
	$table_transactions = $wpdb->prefix . 'starter_coin_transactions';
	$sql_transactions   = "CREATE TABLE IF NOT EXISTS {$table_transactions} (
		id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id          BIGINT(20) UNSIGNED NOT NULL,
		amount           INT(11)        NOT NULL DEFAULT 0,
		transaction_type VARCHAR(50)    NOT NULL DEFAULT '',
		reference_id     BIGINT(20) UNSIGNED NULL,
		description      VARCHAR(255)   NOT NULL DEFAULT '',
		created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY user_id (user_id),
		KEY transaction_type (transaction_type),
		KEY created_at (created_at)
	) {$charset_collate};";

	// -- Withdrawals table -----------------------------------------------------
	$table_withdrawals = $wpdb->prefix . 'starter_withdrawals';
	$sql_withdrawals   = "CREATE TABLE IF NOT EXISTS {$table_withdrawals} (
		id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id         BIGINT(20) UNSIGNED NOT NULL,
		amount          DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
		payment_method  VARCHAR(50)    NOT NULL DEFAULT '',
		payment_details TEXT           NULL,
		status          VARCHAR(20)    NOT NULL DEFAULT 'pending',
		processed_at    DATETIME       NULL,
		created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY user_id (user_id),
		KEY status (status)
	) {$charset_collate};";

	// -- Path map table (for obfuscation) --------------------------------------
	$table_path_map = $wpdb->prefix . 'starter_path_map';
	$sql_path_map   = "CREATE TABLE IF NOT EXISTS {$table_path_map} (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		hash        VARCHAR(64)    NOT NULL,
		real_path   TEXT           NOT NULL,
		expires_at  DATETIME       NULL,
		created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY hash (hash),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	// Execute all table creation queries.
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_chapters );
	dbDelta( $sql_ratings );
	dbDelta( $sql_views );
	dbDelta( $sql_coins );
	dbDelta( $sql_transactions );
	dbDelta( $sql_withdrawals );
	dbDelta( $sql_path_map );

	// -- Register custom roles -------------------------------------------------
	starter_theme_register_roles();

	// -- Flush rewrite rules ---------------------------------------------------
	flush_rewrite_rules();

	// -- Generate .htaccess protections ----------------------------------------
	starter_theme_generate_htaccess();

	// -- Set default theme options ---------------------------------------------
	$defaults = array(
		'starter_dark_mode_default'    => 'auto',
		'starter_chapters_per_page'    => 50,
		'starter_reader_style'         => 'vertical',
		'starter_coin_exchange_rate'   => 100,
		'starter_min_withdrawal'       => 10,
		'starter_revenue_share_pct'    => 70,
		'starter_image_encryption'     => 'on',
		'starter_path_obfuscation'     => 'on',
		'starter_chapter_scheduler'    => 'on',
		'starter_seo_auto_keywords'    => 'on',
		'starter_seo_schema_markup'    => 'on',
		'starter_storage_driver'       => 'local',
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
}

/**
 * Register custom user roles for the theme.
 */
function starter_theme_register_roles() {
	// Uploader role: can upload manga/novel chapters.
	add_role( 'manga_uploader', __( 'Manga Uploader', 'starter-theme' ), array(
		'read'                    => true,
		'upload_files'            => true,
		'edit_posts'              => true,
		'edit_published_posts'    => true,
		'publish_posts'           => true,
		'delete_posts'            => false,
		'manage_manga_chapters'   => true,
	) );

	// VIP reader: access to premium content.
	add_role( 'vip_reader', __( 'VIP Reader', 'starter-theme' ), array(
		'read'                    => true,
		'access_premium_chapters' => true,
	) );

	// Add custom caps to administrator.
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( 'manage_manga_chapters' );
		$admin->add_cap( 'manage_coins' );
		$admin->add_cap( 'manage_theme_storage' );
		$admin->add_cap( 'access_premium_chapters' );
		$admin->add_cap( 'manage_withdrawals' );
	}
}

/**
 * Generate .htaccess protection rules for upload directories.
 */
function starter_theme_generate_htaccess() {
	$upload_dir = wp_upload_dir();
	$manga_dir  = trailingslashit( $upload_dir['basedir'] ) . 'starter-manga';

	if ( ! is_dir( $manga_dir ) ) {
		wp_mkdir_p( $manga_dir );
	}

	$htaccess_content = "# Starter Theme - Protect manga assets\n";
	$htaccess_content .= "Options -Indexes\n";
	$htaccess_content .= "<IfModule mod_rewrite.c>\n";
	$htaccess_content .= "RewriteEngine On\n";
	$htaccess_content .= "RewriteCond %{HTTP_REFERER} !^" . esc_url( home_url() ) . " [NC]\n";
	$htaccess_content .= "RewriteRule \\.(jpg|jpeg|png|gif|webp)$ - [F,NC,L]\n";
	$htaccess_content .= "</IfModule>\n";
	$htaccess_content .= "<FilesMatch \"\\.(php|php5|phtml)$\">\n";
	$htaccess_content .= "Deny from all\n";
	$htaccess_content .= "</FilesMatch>\n";

	$htaccess_path = trailingslashit( $manga_dir ) . '.htaccess';

	if ( ! file_exists( $htaccess_path ) ) {
		file_put_contents( $htaccess_path, $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

/*--------------------------------------------------------------
 * 6. Theme deactivation hook
 *-------------------------------------------------------------*/

add_action( 'switch_theme', 'starter_theme_deactivate' );

function starter_theme_deactivate() {
	// Optionally remove custom roles (configurable via option).
	$remove_roles = get_option( 'starter_remove_roles_on_deactivate', false );

	if ( $remove_roles ) {
		remove_role( 'manga_uploader' );
		remove_role( 'vip_reader' );

		// Remove custom caps from administrator.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'manage_manga_chapters' );
			$admin->remove_cap( 'manage_coins' );
			$admin->remove_cap( 'manage_theme_storage' );
			$admin->remove_cap( 'access_premium_chapters' );
			$admin->remove_cap( 'manage_withdrawals' );
		}
	}

	// Clean up scheduled cron events.
	$cron_hooks = array(
		'starter_chapter_scheduler_cron',
		'starter_views_aggregate_cron',
		'starter_sitemap_generate_cron',
		'starter_path_cleanup_cron',
		'starter_coin_cleanup_cron',
	);

	foreach ( $cron_hooks as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

/*--------------------------------------------------------------
 * 7. Dark mode flash prevention (inline script in <head>)
 *-------------------------------------------------------------*/

add_action( 'wp_head', function() {
	echo "<script>
	(function(){
		var t=localStorage.getItem('starter-theme-mode');
		if(t)document.documentElement.setAttribute('data-theme',t);
		else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches)
			document.documentElement.setAttribute('data-theme','dark');
	})();
	</script>";
}, 1 );

/*--------------------------------------------------------------
 * 8. Custom template loading for manga
 *-------------------------------------------------------------*/

add_filter( 'template_include', 'starter_theme_template_loader' );

function starter_theme_template_loader( $template ) {

	// Chapter reading page (detected via custom query var).
	$manga_slug  = get_query_var( 'manga_slug' );
	$chapter_num = get_query_var( 'chapter_num' );

	if ( $manga_slug && $chapter_num ) {
		// Determine chapter type (image, text, or video).
		$chapter_type = starter_theme_get_chapter_type( $manga_slug, $chapter_num );

		// Try type-specific reader template first.
		$type_template = STARTER_THEME_DIR . '/templates/manga/reader-' . $chapter_type . '.php';
		if ( file_exists( $type_template ) ) {
			return $type_template;
		}

		// Fall back to generic reader template.
		$reader_template = STARTER_THEME_DIR . '/templates/manga/reader.php';
		if ( file_exists( $reader_template ) ) {
			return $reader_template;
		}
	}

	// Single manga post.
	if ( is_singular( 'wp-manga' ) ) {
		$manga_template = STARTER_THEME_DIR . '/templates/manga/single-manga.php';
		if ( file_exists( $manga_template ) ) {
			return $manga_template;
		}
	}

	// Manga archive.
	if ( is_post_type_archive( 'wp-manga' ) ) {
		$archive_template = STARTER_THEME_DIR . '/templates/manga/archive-manga.php';
		if ( file_exists( $archive_template ) ) {
			return $archive_template;
		}
	}

	return $template;
}

/**
 * Determine the chapter type for a given manga and chapter number.
 *
 * @param string $manga_slug  The manga slug.
 * @param string $chapter_num The chapter number.
 * @return string Chapter type: 'image', 'text', or 'video'.
 */
function starter_theme_get_chapter_type( $manga_slug, $chapter_num ) {
	global $wpdb;

	$table_chapters = $wpdb->prefix . 'starter_chapters';

	// Get the manga post ID from slug.
	$manga = get_page_by_path( $manga_slug, OBJECT, 'wp-manga' );

	if ( ! $manga ) {
		return 'image'; // Default fallback.
	}

	$chapter_type = $wpdb->get_var( $wpdb->prepare(
		"SELECT chapter_type FROM {$table_chapters} WHERE manga_id = %d AND chapter_number = %f LIMIT 1",
		$manga->ID,
		floatval( $chapter_num )
	) );

	return $chapter_type ? sanitize_key( $chapter_type ) : 'image';
}

/*--------------------------------------------------------------
 * 9. Rewrite rules & custom query vars
 *-------------------------------------------------------------*/

add_action( 'init', 'starter_theme_rewrite_rules' );

function starter_theme_rewrite_rules() {
	// Chapter reading: /manga/{slug}/chapter-{num}/
	add_rewrite_rule(
		'^manga/([^/]+)/chapter-([0-9]+(?:\.[0-9]+)?)\/?$',
		'index.php?manga_slug=$matches[1]&chapter_num=$matches[2]',
		'top'
	);

	// Image proxy (encrypted image delivery): /starter-img/{token}/{hash}
	add_rewrite_rule(
		'^starter-img/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)\/?$',
		'index.php?starter_img_token=$matches[1]&starter_img_hash=$matches[2]',
		'top'
	);

	// Content path obfuscation proxy: /content/{hash}
	add_rewrite_rule(
		'^content/([a-zA-Z0-9_-]+)\/?$',
		'index.php?starter_content_hash=$matches[1]',
		'top'
	);
}

add_filter( 'query_vars', 'starter_theme_query_vars' );

function starter_theme_query_vars( $vars ) {
	$vars[] = 'manga_slug';
	$vars[] = 'chapter_num';
	$vars[] = 'starter_img_token';
	$vars[] = 'starter_img_hash';
	$vars[] = 'starter_content_hash';
	return $vars;
}

/**
 * Handle proxy requests before template loading.
 */
add_action( 'template_redirect', 'starter_theme_handle_proxy_requests' );

function starter_theme_handle_proxy_requests() {
	// Image proxy handler.
	$img_token = get_query_var( 'starter_img_token' );
	$img_hash  = get_query_var( 'starter_img_hash' );

	if ( $img_token && $img_hash ) {
		if ( class_exists( 'Starter_Image_Encryption' ) ) {
			Starter_Image_Encryption::get_instance()->serve_image( $img_token, $img_hash );
		}
		exit;
	}

	// Content path obfuscation handler.
	$content_hash = get_query_var( 'starter_content_hash' );

	if ( $content_hash ) {
		if ( class_exists( 'Starter_Path_Obfuscation' ) ) {
			Starter_Path_Obfuscation::get_instance()->serve_content( $content_hash );
		}
		exit;
	}
}

/*--------------------------------------------------------------
 * 10. Register all AJAX actions
 *-------------------------------------------------------------*/

add_action( 'init', 'starter_theme_register_ajax_actions' );

function starter_theme_register_ajax_actions() {

	// -- Search (logged-in + logged-out) --------------------------------------
	$search_actions = array(
		'starter_live_search',
		'starter_advanced_search',
		'starter_search_suggestions',
	);

	foreach ( $search_actions as $action ) {
		add_action( 'wp_ajax_' . $action,        'starter_ajax_' . $action );
		add_action( 'wp_ajax_nopriv_' . $action,  'starter_ajax_' . $action );
	}

	// -- Manga / Chapter (logged-in + logged-out) -----------------------------
	$public_manga_actions = array(
		'starter_load_chapter',
		'starter_get_chapter_images',
		'starter_report_chapter',
		'starter_get_manga_info',
		'starter_filter_manga',
	);

	foreach ( $public_manga_actions as $action ) {
		add_action( 'wp_ajax_' . $action,        'starter_ajax_' . $action );
		add_action( 'wp_ajax_nopriv_' . $action,  'starter_ajax_' . $action );
	}

	// -- Rating (logged-in only) ----------------------------------------------
	add_action( 'wp_ajax_starter_submit_rating',    'starter_ajax_starter_submit_rating' );
	add_action( 'wp_ajax_starter_get_user_rating',   'starter_ajax_starter_get_user_rating' );

	// -- Bookmarks (logged-in only) -------------------------------------------
	add_action( 'wp_ajax_starter_toggle_bookmark',   'starter_ajax_starter_toggle_bookmark' );
	add_action( 'wp_ajax_starter_get_bookmarks',     'starter_ajax_starter_get_bookmarks' );
	add_action( 'wp_ajax_starter_remove_bookmark',   'starter_ajax_starter_remove_bookmark' );

	// -- Reading history (logged-in + logged-out) -----------------------------
	$history_actions = array(
		'starter_update_history',
		'starter_get_history',
	);

	foreach ( $history_actions as $action ) {
		add_action( 'wp_ajax_' . $action,        'starter_ajax_' . $action );
		add_action( 'wp_ajax_nopriv_' . $action,  'starter_ajax_' . $action );
	}

	// -- Views tracking (logged-in + logged-out) ------------------------------
	add_action( 'wp_ajax_starter_track_view',        'starter_ajax_starter_track_view' );
	add_action( 'wp_ajax_nopriv_starter_track_view', 'starter_ajax_starter_track_view' );

	// -- User auth (logged-out for login/register, logged-in for profile) -----
	add_action( 'wp_ajax_nopriv_starter_user_login',    'starter_ajax_starter_user_login' );
	add_action( 'wp_ajax_nopriv_starter_user_register', 'starter_ajax_starter_user_register' );
	add_action( 'wp_ajax_starter_update_profile',        'starter_ajax_starter_update_profile' );
	add_action( 'wp_ajax_starter_update_password',       'starter_ajax_starter_update_password' );
	add_action( 'wp_ajax_starter_update_avatar',         'starter_ajax_starter_update_avatar' );

	// -- User settings (logged-in only) ---------------------------------------
	add_action( 'wp_ajax_starter_save_settings',         'starter_ajax_starter_save_settings' );
	add_action( 'wp_ajax_starter_get_settings',          'starter_ajax_starter_get_settings' );

	// -- Coin system (logged-in only) -----------------------------------------
	add_action( 'wp_ajax_starter_purchase_coins',        'starter_ajax_starter_purchase_coins' );
	add_action( 'wp_ajax_starter_unlock_chapter',        'starter_ajax_starter_unlock_chapter' );
	add_action( 'wp_ajax_starter_get_coin_balance',      'starter_ajax_starter_get_coin_balance' );
	add_action( 'wp_ajax_starter_get_transactions',      'starter_ajax_starter_get_transactions' );

	// -- Revenue share / Withdrawals (logged-in only) -------------------------
	add_action( 'wp_ajax_starter_request_withdrawal',    'starter_ajax_starter_request_withdrawal' );
	add_action( 'wp_ajax_starter_get_earnings',          'starter_ajax_starter_get_earnings' );

	// -- Manga upload (logged-in, uploader role) ------------------------------
	add_action( 'wp_ajax_starter_upload_chapter',        'starter_ajax_starter_upload_chapter' );
	add_action( 'wp_ajax_starter_upload_chapter_images', 'starter_ajax_starter_upload_chapter_images' );
	add_action( 'wp_ajax_starter_delete_chapter',        'starter_ajax_starter_delete_chapter' );
	add_action( 'wp_ajax_starter_reorder_images',        'starter_ajax_starter_reorder_images' );

	// -- Novel reader (logged-in + logged-out) --------------------------------
	$novel_actions = array(
		'starter_load_novel_chapter',
		'starter_save_reading_position',
		'starter_get_reading_position',
	);

	foreach ( $novel_actions as $action ) {
		add_action( 'wp_ajax_' . $action,        'starter_ajax_' . $action );
		add_action( 'wp_ajax_nopriv_' . $action,  'starter_ajax_' . $action );
	}

	// -- Video (logged-in + logged-out) ---------------------------------------
	$video_actions = array(
		'starter_get_video_source',
		'starter_track_video_progress',
	);

	foreach ( $video_actions as $action ) {
		add_action( 'wp_ajax_' . $action,        'starter_ajax_' . $action );
		add_action( 'wp_ajax_nopriv_' . $action,  'starter_ajax_' . $action );
	}

	// -- Dark mode (logged-in + logged-out) -----------------------------------
	add_action( 'wp_ajax_starter_save_theme_mode',        'starter_ajax_starter_save_theme_mode' );
	add_action( 'wp_ajax_nopriv_starter_save_theme_mode', 'starter_ajax_starter_save_theme_mode' );

	// -- Admin-only actions ---------------------------------------------------
	if ( current_user_can( 'manage_options' ) ) {
		add_action( 'wp_ajax_starter_import_manga',          'starter_ajax_starter_import_manga' );
		add_action( 'wp_ajax_starter_process_withdrawal',    'starter_ajax_starter_process_withdrawal' );
		add_action( 'wp_ajax_starter_regenerate_sitemap',    'starter_ajax_starter_regenerate_sitemap' );
		add_action( 'wp_ajax_starter_flush_image_cache',     'starter_ajax_starter_flush_image_cache' );
		add_action( 'wp_ajax_starter_update_storage_config', 'starter_ajax_starter_update_storage_config' );
	}
}
