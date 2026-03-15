<?php
/**
 * Admin Settings Panel — Project Alpha
 *
 * Provides a tabbed settings page under Appearance > Alpha Settings where
 * admins can configure:
 *   • General (site info, default content)
 *   • API Keys  (MangaUpdates, ReCAPTCHA, Telegram, Discord)
 *   • Storage   (S3, FTP, local paths)
 *   • Reader    (default mode, lazy-load, canvas protection)
 *   • Coins     (exchange rate, revenue share, withdrawal minimum)
 *   • SEO       (schema, keywords, sitemap)
 *   • Security  (encryption, path obfuscation, rate limiting)
 *   • Webhooks  (Telegram / Discord chapter notifications)
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Admin_Settings
 */
class Starter_Admin_Settings {

	/**
	 * Option group slug.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'starter_theme_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'starter-theme-settings';

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Admin_Settings|null
	 */
	private static $instance = null;

	/**
	 * All setting tabs.
	 *
	 * @var array
	 */
	private $tabs = array();

	/**
	 * Get singleton.
	 *
	 * @return Starter_Admin_Settings
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
		// Tabs contain translated strings — defer until after translations are loaded.
		add_action( 'init',          array( $this, 'init_tabs' ), 0 );
		add_action( 'admin_menu',    array( $this, 'register_menu' ) );
		add_action( 'admin_init',                         array( $this, 'register_settings' ) );
		add_action( 'admin_notices',                      array( $this, 'settings_saved_notice' ) );
		add_action( 'wp_ajax_starter_test_webhook',       array( $this, 'ajax_test_webhook' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tabs initialisation (deferred to `init` so translations are loaded)
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Populate the tabs array after translations are available.
	 * Hooked to `init` with priority 0.
	 */
	public function init_tabs() {
		$this->tabs = array(
			'general'  => __( 'General', 'starter-theme' ),
			'api'      => __( 'API Keys', 'starter-theme' ),
			'storage'  => __( 'Storage', 'starter-theme' ),
			'reader'   => __( 'Reader', 'starter-theme' ),
			'coins'    => __( 'Coins & Revenue', 'starter-theme' ),
			'seo'      => __( 'SEO', 'starter-theme' ),
			'security' => __( 'Security', 'starter-theme' ),
			'webhooks' => __( 'Webhooks', 'starter-theme' ),
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Menu registration
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Register the admin menu pages.
	 */
	public function register_menu() {
		/* Top-level menu: Alpha Manga */
		add_menu_page(
			__( 'Alpha Manga', 'starter-theme' ),
			__( 'Alpha Manga', 'starter-theme' ),
			'manage_options',
			'alpha-manga-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-book-alt',
			5
		);

		/* Sub: Dashboard */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Dashboard', 'starter-theme' ),
			__( 'Dashboard', 'starter-theme' ),
			'manage_options',
			'alpha-manga-dashboard',
			array( $this, 'render_dashboard' )
		);

		/* Sub: Settings */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Alpha Settings', 'starter-theme' ),
			__( 'Settings', 'starter-theme' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		/* Sub: Sample Data */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Sample Data', 'starter-theme' ),
			__( 'Sample Data', 'starter-theme' ),
			'manage_options',
			'alpha-sample-data',
			array( $this, 'render_sample_data_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Settings registration
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Register all settings using Settings API.
	 */
	public function register_settings() {
		$options = $this->get_all_options();

		foreach ( $options as $key => $args ) {
			$is_password      = ! empty( $args['password'] );
			$sanitize_cb      = $args['sanitize'] ?? 'sanitize_text_field';

			if ( $is_password ) {
				/* Wrap sanitize callback: if new value is blank, keep the existing stored value. */
				$option_key = $key;
				register_setting( self::OPTION_GROUP, $key, array(
					'type'              => $args['type'] ?? 'string',
					'sanitize_callback' => function( $new_value ) use ( $option_key, $sanitize_cb ) {
						$new_value = call_user_func( $sanitize_cb, $new_value ?? '' );
						if ( '' === $new_value ) {
							/* Blank submitted — preserve existing secret */
							return get_option( $option_key, '' );
						}
						return $new_value;
					},
					'default'           => $args['default'] ?? '',
				) );
			} else {
				register_setting( self::OPTION_GROUP, $key, array(
					'type'              => $args['type'] ?? 'string',
					'sanitize_callback' => function( $value ) use ( $sanitize_cb ) {
						return call_user_func( $sanitize_cb, $value ?? '' );
					},
					'default'           => $args['default'] ?? '',
				) );
			}
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Dashboard page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the Alpha dashboard page.
	 */
	public function render_dashboard() {
		global $wpdb;

		/* Quick stats */
		$total_manga    = wp_count_posts( 'wp-manga' )->publish ?? 0;
		$total_chapters = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}starter_chapters WHERE chapter_status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'publish'
		) );
		$total_views = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_views'
		) );
		$pending_manga = wp_count_posts( 'wp-manga' )->pending ?? 0;

		$recent_chapters = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*, p.post_title AS manga_title, p.ID AS manga_post_id
			 FROM {$wpdb->prefix}starter_chapters c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.manga_id
			 ORDER BY c.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			10
		) );
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-book-alt"></span>
				<?php esc_html_e( 'Alpha Manga Dashboard', 'starter-theme' ); ?>
			</h1>

			<!-- Stats Cards -->
			<div class="alpha-stats-row">
				<div class="alpha-stat-card">
					<div class="alpha-stat-card__icon alpha-stat-card__icon--purple">
						<span class="dashicons dashicons-book"></span>
					</div>
					<div class="alpha-stat-card__body">
						<span class="alpha-stat-card__value"><?php echo number_format( $total_manga ); ?></span>
						<span class="alpha-stat-card__label"><?php esc_html_e( 'Total Manga', 'starter-theme' ); ?></span>
					</div>
				</div>
				<div class="alpha-stat-card">
					<div class="alpha-stat-card__icon alpha-stat-card__icon--blue">
						<span class="dashicons dashicons-media-document"></span>
					</div>
					<div class="alpha-stat-card__body">
						<span class="alpha-stat-card__value"><?php echo number_format( $total_chapters ); ?></span>
						<span class="alpha-stat-card__label"><?php esc_html_e( 'Total Chapters', 'starter-theme' ); ?></span>
					</div>
				</div>
				<div class="alpha-stat-card">
					<div class="alpha-stat-card__icon alpha-stat-card__icon--green">
						<span class="dashicons dashicons-visibility"></span>
					</div>
					<div class="alpha-stat-card__body">
						<span class="alpha-stat-card__value"><?php echo number_format( $total_views ); ?></span>
						<span class="alpha-stat-card__label"><?php esc_html_e( 'Total Views', 'starter-theme' ); ?></span>
					</div>
				</div>
				<div class="alpha-stat-card">
					<div class="alpha-stat-card__icon alpha-stat-card__icon--orange">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="alpha-stat-card__body">
						<span class="alpha-stat-card__value"><?php echo number_format( $pending_manga ); ?></span>
						<span class="alpha-stat-card__label"><?php esc_html_e( 'Pending Review', 'starter-theme' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="alpha-quick-actions">
				<h2><?php esc_html_e( 'Quick Actions', 'starter-theme' ); ?></h2>
				<div class="alpha-quick-actions__grid">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wp-manga' ) ); ?>" class="alpha-action-btn">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Add New Manga', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-chapters' ) ); ?>" class="alpha-action-btn">
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Upload Chapter', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wp-manga&post_status=pending' ) ); ?>" class="alpha-action-btn">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Review Queue', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="alpha-action-btn">
						<span class="dashicons dashicons-admin-settings"></span>
						<?php esc_html_e( 'Settings', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-sample-data' ) ); ?>" class="alpha-action-btn">
						<span class="dashicons dashicons-database-add"></span>
						<?php esc_html_e( 'Sample Data', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="alpha-action-btn" target="_blank">
						<span class="dashicons dashicons-admin-home"></span>
						<?php esc_html_e( 'View Site', 'starter-theme' ); ?>
					</a>
				</div>
			</div>

			<!-- Recent Chapters -->
			<?php if ( ! empty( $recent_chapters ) ) : ?>
			<div class="alpha-recent-table-wrap">
				<h2><?php esc_html_e( 'Recent Chapters', 'starter-theme' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Manga', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Chapter', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Type', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Status', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Published', 'starter-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_chapters as $ch ) : ?>
						<tr>
							<td>
								<?php $edit_link = $ch->manga_post_id ? get_edit_post_link( $ch->manga_post_id ) : null; ?>
								<?php if ( $edit_link ) : ?>
									<a href="<?php echo esc_url( $edit_link ); ?>">
										<?php echo esc_html( $ch->manga_title ?: __( '(no title)', 'starter-theme' ) ); ?>
									</a>
								<?php elseif ( $ch->manga_title ) : ?>
									<?php echo esc_html( $ch->manga_title ); ?>
								<?php else : ?>
									<?php echo esc_html( __( 'Unknown', 'starter-theme' ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'Ch.' . number_format( $ch->chapter_number, 0 ) . ( $ch->chapter_title ? ' — ' . $ch->chapter_title : '' ) ); ?></td>
							<td><span class="alpha-badge alpha-badge--<?php echo esc_attr( $ch->chapter_type ); ?>"><?php echo esc_html( ucfirst( $ch->chapter_type ) ); ?></span></td>
							<td><span class="alpha-status alpha-status--<?php echo esc_attr( $ch->chapter_status ); ?>"><?php echo esc_html( ucfirst( $ch->chapter_status ) ); ?></span></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ch->publish_date ) ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Main settings page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the tabbed settings page.
	 */
	public function render_settings_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		if ( ! array_key_exists( $current_tab, $this->tabs ) ) {
			$current_tab = 'general';
		}
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-admin-settings"></span>
				<?php esc_html_e( 'Alpha Manga Settings', 'starter-theme' ); ?>
			</h1>

			<nav class="alpha-settings-tabs" aria-label="Settings sections">
				<?php foreach ( $this->tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug ) ); ?>"
					   class="alpha-settings-tab <?php echo $slug === $current_tab ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="alpha-settings-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<div class="alpha-settings-body">
					<?php
					switch ( $current_tab ) {
						case 'general':  $this->tab_general(); break;
						case 'api':      $this->tab_api_keys(); break;
						case 'storage':  $this->tab_storage(); break;
						case 'reader':   $this->tab_reader(); break;
						case 'coins':    $this->tab_coins(); break;
						case 'seo':      $this->tab_seo(); break;
						case 'security': $this->tab_security(); break;
						case 'webhooks': $this->tab_webhooks(); break;
					}
					?>
				</div>

				<?php submit_button( __( 'Save Settings', 'starter-theme' ), 'primary large', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Settings Tabs
	 * ─────────────────────────────────────────────────────────── */

	/** Tab: General */
	private function tab_general() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'General Settings', 'starter-theme' ); ?></h2>

			<?php $this->field_text( 'starter_site_name', __( 'Site Display Name', 'starter-theme' ), get_bloginfo( 'name' ) ); ?>
			<?php $this->field_select( 'starter_dark_mode_default', __( 'Default Theme Mode', 'starter-theme' ), array(
				'auto'  => __( 'Auto (follows OS)', 'starter-theme' ),
				'dark'  => __( 'Dark', 'starter-theme' ),
				'light' => __( 'Light', 'starter-theme' ),
			) ); ?>
			<?php $this->field_number( 'starter_chapters_per_page', __( 'Chapters Per Page', 'starter-theme' ), 50, 1, 200 ); ?>
			<?php $this->field_select( 'starter_reader_style', __( 'Default Reader Style', 'starter-theme' ), array(
				'vertical'  => __( 'Vertical Scroll (Webtoon)', 'starter-theme' ),
				'paged'     => __( 'Paged (Single Page)', 'starter-theme' ),
				'double'    => __( 'Double Page Spread', 'starter-theme' ),
			) ); ?>
			<?php $this->field_select( 'starter_manga_grid_columns', __( 'Manga Grid Columns', 'starter-theme' ), array(
				'2' => '2', '3' => '3', '4' => '4 (' . __( 'default', 'starter-theme' ) . ')', '5' => '5', '6' => '6',
			) ); ?>
			<?php $this->field_toggle( 'starter_adult_content_enabled', __( 'Enable Adult Content (18+)', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_registration_open', __( 'Open Registration', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_frontend_upload', __( 'Allow User Manga Uploads', 'starter-theme' ) ); ?>
		</div>
		<?php
	}

	/** Tab: API Keys */
	private function tab_api_keys() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'API Keys & Integrations', 'starter-theme' ); ?></h2>
			<p class="alpha-settings-desc"><?php esc_html_e( 'These keys are stored securely in the WordPress database. Never share them publicly.', 'starter-theme' ); ?></p>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'MangaUpdates', 'starter-theme' ); ?></h3>
			<?php $this->field_text(   'starter_mangaupdates_api_key', __( 'MangaUpdates API Key (optional — public API works without key)', 'starter-theme' ) ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Google reCAPTCHA v3', 'starter-theme' ); ?></h3>
			<?php $this->field_text(   'starter_recaptcha_site_key',   __( 'Site Key', 'starter-theme' ) ); ?>
			<?php $this->field_password( 'starter_recaptcha_secret_key', __( 'Secret Key', 'starter-theme' ) ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Telegram Bot', 'starter-theme' ); ?></h3>
			<?php $this->field_password( 'starter_telegram_bot_token', __( 'Bot Token', 'starter-theme' ), 'Format: 123456:ABC-xyz…' ); ?>
			<?php $this->field_text(     'starter_telegram_chat_id',   __( 'Chat / Channel ID', 'starter-theme' ), '-100xxxxxxxxxxxx' ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Discord Webhook', 'starter-theme' ); ?></h3>
			<?php $this->field_password( 'starter_discord_webhook_url', __( 'Webhook URL', 'starter-theme' ), 'https://discord.com/api/webhooks/…' ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Amazon S3', 'starter-theme' ); ?></h3>
			<?php $this->field_text(     'starter_s3_bucket',      __( 'Bucket Name', 'starter-theme' ) ); ?>
			<?php $this->field_text(     'starter_s3_region',      __( 'Region', 'starter-theme' ), 'us-east-1' ); ?>
			<?php $this->field_password( 'starter_s3_access_key',  __( 'Access Key ID', 'starter-theme' ) ); ?>
			<?php $this->field_password( 'starter_s3_secret_key',  __( 'Secret Access Key', 'starter-theme' ) ); ?>
			<?php $this->field_text(     'starter_s3_cdn_url',     __( 'CDN / Custom Domain (optional)', 'starter-theme' ), 'https://cdn.yoursite.com' ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'MyCred (Coin System)', 'starter-theme' ); ?></h3>
			<p class="alpha-settings-desc">
				<?php esc_html_e( 'If you have MyCred installed, Alpha will automatically use it. Otherwise the built-in coin system is used.', 'starter-theme' ); ?>
				<span class="alpha-badge <?php echo class_exists( 'myCRED' ) ? 'alpha-badge--success' : 'alpha-badge--muted'; ?>">
					<?php echo class_exists( 'myCRED' ) ? esc_html__( 'MyCred Active', 'starter-theme' ) : esc_html__( 'Using Built-in Coins', 'starter-theme' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/** Tab: Storage */
	private function tab_storage() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'Storage Configuration', 'starter-theme' ); ?></h2>

			<?php $this->field_select( 'starter_storage_driver', __( 'Storage Driver', 'starter-theme' ), array(
				'local' => __( 'Local (uploads folder)', 'starter-theme' ),
				's3'    => __( 'Amazon S3', 'starter-theme' ),
				'ftp'   => __( 'FTP / SFTP', 'starter-theme' ),
			) ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'FTP Settings', 'starter-theme' ); ?></h3>
			<?php $this->field_text(     'starter_ftp_host',     __( 'FTP Host', 'starter-theme' ) ); ?>
			<?php $this->field_text(     'starter_ftp_user',     __( 'FTP Username', 'starter-theme' ) ); ?>
			<?php $this->field_password( 'starter_ftp_password', __( 'FTP Password', 'starter-theme' ) ); ?>
			<?php $this->field_number(   'starter_ftp_port',     __( 'FTP Port', 'starter-theme' ), 21, 1, 65535 ); ?>
			<?php $this->field_text(     'starter_ftp_path',     __( 'Remote Upload Path', 'starter-theme' ), '/public_html/wp-content/uploads/manga/' ); ?>
			<?php $this->field_text(     'starter_ftp_url',      __( 'Public URL for FTP files', 'starter-theme' ), 'https://yoursite.com/wp-content/uploads/manga/' ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Image Optimization', 'starter-theme' ); ?></h3>
			<?php $this->field_toggle( 'starter_webp_convert',     __( 'Convert uploaded images to WebP', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_lazy_load_images', __( 'Lazy-load chapter images', 'starter-theme' ) ); ?>
			<?php $this->field_number( 'starter_image_quality',    __( 'JPEG/WebP Quality (1–100)', 'starter-theme' ), 85, 1, 100 ); ?>
			<?php $this->field_number( 'starter_max_image_width',  __( 'Max Image Width (px)', 'starter-theme' ), 1600, 400, 4000 ); ?>
		</div>
		<?php
	}

	/** Tab: Reader */
	private function tab_reader() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'Chapter Reader Settings', 'starter-theme' ); ?></h2>

			<?php $this->field_toggle( 'starter_canvas_protection',   __( 'Canvas Image Protection (prevents right-click save)', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_image_encryption',    __( 'Encrypt image URLs (obfuscated delivery)', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_path_obfuscation',    __( 'Obfuscate image paths', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_hotlink_protection',  __( 'Hotlink protection (.htaccess)', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_preload_next_ch',     __( 'Preload next chapter in background', 'starter-theme' ) ); ?>
			<?php $this->field_number( 'starter_images_per_ajax',     __( 'Images loaded per AJAX batch', 'starter-theme' ), 10, 1, 50 ); ?>
			<?php $this->field_select( 'starter_default_fit_mode', __( 'Default image fit mode', 'starter-theme' ), array(
				'fit-width'  => __( 'Fit Width', 'starter-theme' ),
				'fit-height' => __( 'Fit Height', 'starter-theme' ),
				'fit-screen' => __( 'Fit Screen', 'starter-theme' ),
				'original'   => __( 'Original Size', 'starter-theme' ),
			) ); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Novel / Text Reader', 'starter-theme' ); ?></h3>
			<?php $this->field_text(   'starter_novel_default_font',        __( 'Default Font', 'starter-theme' ), 'serif' ); ?>
			<?php $this->field_number( 'starter_novel_default_font_size',   __( 'Default Font Size (px)', 'starter-theme' ), 18, 12, 32 ); ?>
			<?php $this->field_number( 'starter_novel_default_line_height', __( 'Default Line Height', 'starter-theme' ), 180, 100, 300 ); ?>
		</div>
		<?php
	}

	/** Tab: Coins & Revenue */
	private function tab_coins() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'Coins & Revenue Share', 'starter-theme' ); ?></h2>

			<?php $this->field_toggle( 'starter_coins_enabled', __( 'Enable Coin System', 'starter-theme' ) ); ?>
			<?php $this->field_number( 'starter_coin_exchange_rate',  __( 'Coins per $1 USD', 'starter-theme' ), 100, 1, 10000 ); ?>
			<?php $this->field_number( 'starter_min_withdrawal',      __( 'Minimum Withdrawal ($)', 'starter-theme' ), 10, 1, 1000 ); ?>
			<?php $this->field_number( 'starter_revenue_share_pct',   __( 'Revenue Share % for Uploaders', 'starter-theme' ), 70, 0, 100 ); ?>
			<?php $this->field_number( 'starter_free_chapter_delay',  __( 'Days until premium chapter becomes free (0 = never)', 'starter-theme' ), 0, 0, 3650 ); ?>
			<?php $this->field_select( 'starter_payment_gateway', __( 'Payment Gateway', 'starter-theme' ), array(
				'none'       => __( 'None / Manual', 'starter-theme' ),
				'woocommerce'=> __( 'WooCommerce', 'starter-theme' ),
				'stripe'     => __( 'Stripe (manual integration)', 'starter-theme' ),
				'paypal'     => __( 'PayPal (manual integration)', 'starter-theme' ),
			) ); ?>
		</div>
		<?php
	}

	/** Tab: SEO */
	private function tab_seo() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'SEO Settings', 'starter-theme' ); ?></h2>

			<?php $this->field_toggle( 'starter_seo_schema_markup',  __( 'Add ComicSeries / Book schema markup', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_seo_auto_keywords',  __( 'Auto-generate meta keywords from genres/tags', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_seo_opengraph',      __( 'Add Open Graph tags for social sharing', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_seo_twitter_cards',  __( 'Add Twitter Card meta tags', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_chapter_scheduler',  __( 'Enable chapter scheduling cron', 'starter-theme' ) ); ?>
			<?php $this->field_text(   'starter_sitemap_path',       __( 'Custom sitemap path (leave blank for default)', 'starter-theme' ), '/manga-sitemap.xml' ); ?>
		</div>
		<?php
	}

	/** Tab: Security */
	private function tab_security() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'Security Settings', 'starter-theme' ); ?></h2>

			<?php $this->field_toggle( 'starter_disable_xmlrpc',    __( 'Disable XML-RPC', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_hide_wp_version',   __( 'Hide WordPress version from source', 'starter-theme' ) ); ?>
			<?php $this->field_number( 'starter_rate_limit_login',  __( 'Max login attempts before lockout', 'starter-theme' ), 5, 1, 20 ); ?>
			<?php $this->field_number( 'starter_lockout_duration',  __( 'Lockout duration (minutes)', 'starter-theme' ), 30, 1, 1440 ); ?>
			<?php $this->field_toggle( 'starter_encrypt_api_keys',  __( 'Encrypt stored API keys (AES-256)', 'starter-theme' ) ); ?>
			<?php $this->field_toggle( 'starter_require_login_read',__( 'Require login to read chapters', 'starter-theme' ) ); ?>
			<?php $this->field_text(   'starter_allowed_upload_ips', __( 'Restrict chapter uploads to IPs (comma-separated, blank = no restriction)', 'starter-theme' ) ); ?>
		</div>
		<?php
	}

	/** Tab: Webhooks */
	private function tab_webhooks() {
		?>
		<div class="alpha-settings-section">
			<h2><?php esc_html_e( 'Webhook & Notification Settings', 'starter-theme' ); ?></h2>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Telegram', 'starter-theme' ); ?></h3>
			<?php $this->field_toggle( 'starter_telegram_enabled',        __( 'Send new chapter notifications via Telegram', 'starter-theme' ) ); ?>
			<?php $this->field_textarea( 'starter_telegram_message_template', __( 'Message Template', 'starter-theme' ),
				"🆕 الفصل {chapter_number} من مانجا {manga_title}\n{chapter_url}",
				__( 'Available variables: {manga_title}, {chapter_number}, {chapter_title}, {chapter_url}, {manga_url}', 'starter-theme' )
			); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Discord', 'starter-theme' ); ?></h3>
			<?php $this->field_toggle( 'starter_discord_enabled',        __( 'Send new chapter notifications via Discord', 'starter-theme' ) ); ?>
			<?php $this->field_textarea( 'starter_discord_message_template', __( 'Discord Message Template', 'starter-theme' ),
				"**{manga_title}** — Chapter {chapter_number} is now available!\n{chapter_url}",
				__( 'Same variables as Telegram above.', 'starter-theme' )
			); ?>

			<h3 class="alpha-settings-group-title"><?php esc_html_e( 'Test Webhooks', 'starter-theme' ); ?></h3>
			<div class="alpha-test-webhook-row">
				<button type="button" id="test-telegram-webhook" class="button button-secondary">
					<?php esc_html_e( 'Test Telegram', 'starter-theme' ); ?>
				</button>
				<button type="button" id="test-discord-webhook" class="button button-secondary">
					<?php esc_html_e( 'Test Discord', 'starter-theme' ); ?>
				</button>
				<span id="webhook-test-result" style="margin-left:12px;"></span>
			</div>
		</div>
		<script>
		(function($){
			$('#test-telegram-webhook,#test-discord-webhook').on('click',function(){
				var type = $(this).is('#test-telegram-webhook') ? 'telegram' : 'discord';
				var $status = $('#webhook-test-result');
				$status.text('Sending…');
				$.post(ajaxurl,{
					action:'starter_test_webhook',
					type:type,
					nonce:'<?php echo esc_js( wp_create_nonce( 'starter_test_webhook' ) ); ?>'
				},function(res){
					$status.text(res.success ? '✅ Sent!' : '❌ ' + res.data.message);
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Sample Data page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render sample data management page.
	 */
	public function render_sample_data_page() {
		$installed = class_exists( 'Starter_Sample_Data' ) ? Starter_Sample_Data::is_installed() : false;
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-database-add"></span>
				<?php esc_html_e( 'Sample Data', 'starter-theme' ); ?>
			</h1>

			<div class="alpha-card">
				<h2><?php esc_html_e( 'Demo Content', 'starter-theme' ); ?></h2>
				<p><?php esc_html_e( 'Install sample manga, chapters, and genres to see how the theme looks with real content. This is ideal for testing the layout before you start adding your own content.', 'starter-theme' ); ?></p>

				<p><strong><?php esc_html_e( 'Status:', 'starter-theme' ); ?></strong>
					<span class="alpha-badge <?php echo $installed ? 'alpha-badge--success' : 'alpha-badge--muted'; ?>">
						<?php echo $installed ? esc_html__( 'Installed', 'starter-theme' ) : esc_html__( 'Not installed', 'starter-theme' ); ?>
					</span>
				</p>

				<p><?php esc_html_e( 'Sample content includes:', 'starter-theme' ); ?></p>
				<ul style="list-style:disc;margin-left:24px;">
					<li><?php esc_html_e( '6 sample manga/novel posts', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( '13 genre taxonomy terms', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( 'Placeholder chapter records', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( 'Proper meta fields (author, artist, status, year, type)', 'starter-theme' ); ?></li>
				</ul>

				<div style="margin-top:16px;display:flex;gap:12px;">
					<?php if ( ! $installed ) : ?>
						<button type="button" id="install-sample-btn" class="button button-primary button-large"
						        data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_sample_data_nonce' ) ); ?>">
							<?php esc_html_e( '▶ Install Sample Data', 'starter-theme' ); ?>
						</button>
					<?php else : ?>
						<button type="button" id="remove-sample-btn" class="button button-secondary button-large"
						        data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_sample_data_nonce' ) ); ?>">
							<?php esc_html_e( '✕ Remove Sample Data', 'starter-theme' ); ?>
						</button>
					<?php endif; ?>
					<span id="sample-data-status" style="line-height:30px;margin-left:8px;"></span>
				</div>
			</div>
		</div>

		<script>
		(function($){
			$('#install-sample-btn').on('click',function(){
				var $btn=$(this), $s=$('#sample-data-status');
				$btn.text('Installing…').prop('disabled',true);
				$.post(ajaxurl,{action:'starter_insert_sample_data',nonce:$btn.data('nonce')},function(r){
					if(r.success){ var n=parseInt(r.data.inserted,10)||0; $s.empty().append($('<span style="color:#46b450"/>').text('✅ Installed '+n+' items. Refreshing…')); setTimeout(()=>location.reload(),1500); }
					else { $s.empty().append($('<span style="color:#dc3232"/>').text('❌ '+(r.data.message||'Error'))); $btn.prop('disabled',false).text('Install Sample Data'); }
				});
			});
			$('#remove-sample-btn').on('click',function(){
				if(!confirm('Remove all sample data?')) return;
				var $btn=$(this),$s=$('#sample-data-status');
				$btn.text('Removing…').prop('disabled',true);
				$.post(ajaxurl,{action:'starter_remove_sample_data',nonce:$btn.data('nonce')},function(r){
					if(r.success){ var n=parseInt(r.data.deleted,10)||0; $s.empty().append($('<span style="color:#46b450"/>').text('✅ Removed '+n+' items. Refreshing…')); setTimeout(()=>location.reload(),1500); }
					else { $s.empty().append($('<span style="color:#dc3232"/>').text('❌ Error.')); $btn.prop('disabled',false).text('Remove Sample Data'); }
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Field helpers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render a text input field.
	 */
	private function field_text( $key, $label, $placeholder = '' ) {
		$value = get_option( $key, '' );
		?>
		<div class="alpha-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
			       value="<?php echo esc_attr( $value ); ?>"
			       placeholder="<?php echo esc_attr( $placeholder ); ?>"
			       class="alpha-field__input regular-text">
		</div>
		<?php
	}

	/**
	 * Render a password input field (masked).
	 */
	private function field_password( $key, $label, $placeholder = '' ) {
		$has_value = (bool) get_option( $key, '' );
		/* Never echo stored secrets back into the page (sensitive data exposure).
		 * Leave the field empty; only save if the admin types a new value.
		 * A "●●●●●● set" hint is shown when a value already exists. */
		?>
		<div class="alpha-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label"><?php echo esc_html( $label ); ?></label>
			<?php if ( $has_value ) : ?>
			<p class="description" style="margin-bottom:4px;color:#666;">
				<?php esc_html_e( 'A value is stored. Leave blank to keep it, or type a new value to replace it.', 'starter-theme' ); ?>
			</p>
			<?php endif; ?>
			<div class="alpha-field__password-wrap">
				<input type="password" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
				       value=""
				       placeholder="<?php echo $has_value ? esc_attr( '●●●●●●●● (unchanged)' ) : esc_attr( $placeholder ); ?>"
				       class="alpha-field__input regular-text" autocomplete="new-password">
				<button type="button" class="alpha-toggle-pw" aria-label="<?php esc_attr_e( 'Toggle visibility', 'starter-theme' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a number input field.
	 */
	private function field_number( $key, $label, $default = 0, $min = 0, $max = 9999 ) {
		$value = get_option( $key, $default );
		?>
		<div class="alpha-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label"><?php echo esc_html( $label ); ?></label>
			<input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
			       value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"
			       class="alpha-field__input small-text">
		</div>
		<?php
	}

	/**
	 * Render a select field.
	 */
	private function field_select( $key, $label, $options = array() ) {
		$value = get_option( $key, '' );
		?>
		<div class="alpha-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="alpha-field__select">
				<?php foreach ( $options as $opt_val => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Render an on/off toggle.
	 */
	private function field_toggle( $key, $label ) {
		$value = get_option( $key, 'off' );
		?>
		<div class="alpha-field alpha-field--inline">
			<label class="alpha-toggle">
				<input type="checkbox" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
				       value="on" <?php checked( $value, 'on' ); ?>>
				<span class="alpha-toggle__track"></span>
			</label>
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label alpha-field__label--inline"><?php echo esc_html( $label ); ?></label>
		</div>
		<?php
	}

	/**
	 * Render a textarea field.
	 */
	private function field_textarea( $key, $label, $default = '', $description = '' ) {
		$value = get_option( $key, $default );
		?>
		<div class="alpha-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="alpha-field__label"><?php echo esc_html( $label ); ?></label>
			<textarea id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
			          class="alpha-field__textarea large-text" rows="4"><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( $description ) : ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Get list of all registered options with their config.
	 *
	 * @return array
	 */
	private function get_all_options() {
		return array(
			'starter_site_name'                    => array( 'type' => 'string' ),
			'starter_dark_mode_default'            => array( 'type' => 'string', 'default' => 'auto' ),
			'starter_chapters_per_page'            => array( 'type' => 'integer', 'default' => 50, 'sanitize' => 'absint' ),
			'starter_reader_style'                 => array( 'type' => 'string', 'default' => 'vertical' ),
			'starter_manga_grid_columns'           => array( 'type' => 'integer', 'default' => 4, 'sanitize' => 'absint' ),
			'starter_adult_content_enabled'        => array( 'type' => 'string', 'default' => 'off' ),
			'starter_registration_open'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_frontend_upload'              => array( 'type' => 'string', 'default' => 'on' ),
			'starter_mangaupdates_api_key'         => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_recaptcha_site_key'           => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_recaptcha_secret_key'         => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'password' => true ),
			'starter_telegram_bot_token'           => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'password' => true ),
			'starter_telegram_chat_id'             => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_discord_webhook_url'          => array( 'type' => 'string', 'sanitize' => 'esc_url_raw', 'password' => true ),
			'starter_s3_bucket'                    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_s3_region'                    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_s3_access_key'                => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'password' => true ),
			'starter_s3_secret_key'                => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'password' => true ),
			'starter_s3_cdn_url'                   => array( 'type' => 'string', 'sanitize' => 'esc_url_raw' ),
			'starter_storage_driver'               => array( 'type' => 'string', 'default' => 'local' ),
			'starter_ftp_host'                     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_ftp_user'                     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_ftp_password'                 => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'password' => true ),
			'starter_ftp_port'                     => array( 'type' => 'integer', 'default' => 21, 'sanitize' => 'absint' ),
			'starter_ftp_path'                     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_ftp_url'                      => array( 'type' => 'string', 'sanitize' => 'esc_url_raw' ),
			'starter_webp_convert'                 => array( 'type' => 'string', 'default' => 'on' ),
			'starter_lazy_load_images'             => array( 'type' => 'string', 'default' => 'on' ),
			'starter_image_quality'                => array( 'type' => 'integer', 'default' => 85, 'sanitize' => 'absint' ),
			'starter_max_image_width'              => array( 'type' => 'integer', 'default' => 1600, 'sanitize' => 'absint' ),
			'starter_canvas_protection'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_image_encryption'             => array( 'type' => 'string', 'default' => 'on' ),
			'starter_path_obfuscation'             => array( 'type' => 'string', 'default' => 'on' ),
			'starter_hotlink_protection'           => array( 'type' => 'string', 'default' => 'on' ),
			'starter_preload_next_ch'              => array( 'type' => 'string', 'default' => 'on' ),
			'starter_images_per_ajax'              => array( 'type' => 'integer', 'default' => 10, 'sanitize' => 'absint' ),
			'starter_default_fit_mode'             => array( 'type' => 'string', 'default' => 'fit-width' ),
			'starter_novel_default_font'           => array( 'type' => 'string', 'default' => 'serif' ),
			'starter_novel_default_font_size'      => array( 'type' => 'integer', 'default' => 18, 'sanitize' => 'absint' ),
			'starter_novel_default_line_height'    => array( 'type' => 'integer', 'default' => 180, 'sanitize' => 'absint' ),
			'starter_coins_enabled'                => array( 'type' => 'string', 'default' => 'on' ),
			'starter_coin_exchange_rate'           => array( 'type' => 'integer', 'default' => 100, 'sanitize' => 'absint' ),
			'starter_min_withdrawal'               => array( 'type' => 'integer', 'default' => 10, 'sanitize' => 'absint' ),
			'starter_revenue_share_pct'            => array( 'type' => 'integer', 'default' => 70, 'sanitize' => 'absint' ),
			'starter_free_chapter_delay'           => array( 'type' => 'integer', 'default' => 0, 'sanitize' => 'absint' ),
			'starter_payment_gateway'              => array( 'type' => 'string', 'default' => 'none' ),
			'starter_seo_schema_markup'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_seo_auto_keywords'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_seo_opengraph'                => array( 'type' => 'string', 'default' => 'on' ),
			'starter_seo_twitter_cards'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_chapter_scheduler'            => array( 'type' => 'string', 'default' => 'on' ),
			'starter_sitemap_path'                 => array( 'type' => 'string', 'default' => '/manga-sitemap.xml' ),
			'starter_disable_xmlrpc'               => array( 'type' => 'string', 'default' => 'on' ),
			'starter_hide_wp_version'              => array( 'type' => 'string', 'default' => 'on' ),
			'starter_rate_limit_login'             => array( 'type' => 'integer', 'default' => 5, 'sanitize' => 'absint' ),
			'starter_lockout_duration'             => array( 'type' => 'integer', 'default' => 30, 'sanitize' => 'absint' ),
			'starter_encrypt_api_keys'             => array( 'type' => 'string', 'default' => 'off' ),
			'starter_require_login_read'           => array( 'type' => 'string', 'default' => 'off' ),
			'starter_allowed_upload_ips'           => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'starter_telegram_enabled'             => array( 'type' => 'string', 'default' => 'off' ),
			'starter_telegram_message_template'    => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field' ),
			'starter_discord_enabled'              => array( 'type' => 'string', 'default' => 'off' ),
			'starter_discord_message_template'     => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field' ),
		);
	}

	/**
	 * Flash notice after saving settings.
	 */
	public function settings_saved_notice() {
		if ( isset( $_GET['settings-updated'] ) && $_GET['page'] === self::PAGE_SLUG ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( '✅ Alpha settings saved.', 'starter-theme' ); ?></p>
			</div>
			<?php
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Webhook test AJAX
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Send a test notification to the configured webhook.
	 */
	public function ajax_test_webhook() {
		check_ajax_referer( 'starter_test_webhook', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter-theme' ) ) );
		}

		$channel = sanitize_key( $_POST['channel'] ?? 'telegram' );

		if ( 'telegram' === $channel ) {
			$bot_token = get_option( 'starter_telegram_bot_token', '' );
			$chat_id   = get_option( 'starter_telegram_chat_id', '' );

			if ( ! $bot_token || ! $chat_id ) {
				wp_send_json_error( array( 'message' => __( 'Telegram credentials not configured.', 'starter-theme' ) ) );
			}

			$response = wp_remote_post(
				'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendMessage',
				array(
					'body'    => array(
						'chat_id' => $chat_id,
						'text'    => __( '✅ Alpha Manga: Telegram webhook test successful!', 'starter-theme' ),
					),
					'timeout' => 10,
				)
			);
		} elseif ( 'discord' === $channel ) {
			$webhook_url = get_option( 'starter_discord_webhook_url', '' );

			if ( ! $webhook_url ) {
				wp_send_json_error( array( 'message' => __( 'Discord webhook URL not configured.', 'starter-theme' ) ) );
			}

			/* Validate it looks like a Discord webhook URL. */
			if ( ! preg_match( '#^https://discord(?:app)?\.com/api/webhooks/#', $webhook_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Discord webhook URL appears invalid.', 'starter-theme' ) ) );
			}

			$response = wp_remote_post(
				$webhook_url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'content' => __( '✅ Alpha Manga: Discord webhook test successful!', 'starter-theme' ),
					) ),
					'timeout' => 10,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown channel.', 'starter-theme' ) ) );
		}

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( array( 'message' => sprintf(
				/* translators: %s: channel name */
				__( 'Test sent successfully via %s!', 'starter-theme' ),
				ucfirst( $channel )
			) ) );
		} else {
			wp_send_json_error( array( 'message' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'Webhook returned HTTP %d.', 'starter-theme' ),
				$code
			) ) );
		}
	}
}

/* Auto-instantiate. */
Starter_Admin_Settings::get_instance();
