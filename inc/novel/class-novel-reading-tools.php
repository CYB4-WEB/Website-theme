<?php
/**
 * Novel reading tools — frontend customization widget and admin settings.
 *
 * Provides real-time reading customization (background, text color, font,
 * size, line height) via CSS custom properties and a small vanilla JS
 * controller.  Settings persist per-user (user meta) or in localStorage
 * for guests.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Novel_Reading_Tools
 */
class Starter_Novel_Reading_Tools {

	/**
	 * Option key for admin default settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'starter_novel_reading_tools';

	/**
	 * User meta key for per-user settings.
	 *
	 * @var string
	 */
	const USER_META_KEY = 'starter_novel_reading_prefs';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const ADMIN_SLUG = 'starter-novel-reading-tools';

	/**
	 * Nonce action for AJAX save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_novel_reading_tools';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
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
		// Frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );

		// AJAX save for logged-in users.
		add_action( 'wp_ajax_starter_save_reading_prefs', array( $this, 'ajax_save_prefs' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/* ------------------------------------------------------------------
	 * Default settings helpers.
	 * ----------------------------------------------------------------*/

	/**
	 * Get hardcoded factory defaults.
	 *
	 * @return array
	 */
	public static function factory_defaults() {
		return array(
			'background_color' => '#ffffff',
			'text_color'       => '#222222',
			'font_family'      => 'Georgia, serif',
			'font_size'        => 18,
			'line_height'      => 1.8,
		);
	}

	/**
	 * Get admin-configured defaults (falls back to factory).
	 *
	 * @return array
	 */
	public function get_defaults() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::factory_defaults() );
	}

	/**
	 * Available font families.
	 *
	 * @return array Associative label => CSS value.
	 */
	public static function get_font_options() {
		return array(
			__( 'Georgia (serif)', 'starter-theme' )       => 'Georgia, serif',
			__( 'Times New Roman', 'starter-theme' )       => '"Times New Roman", Times, serif',
			__( 'System Sans-serif', 'starter-theme' )     => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
			__( 'Arial', 'starter-theme' )                 => 'Arial, Helvetica, sans-serif',
			__( 'Monospace', 'starter-theme' )             => '"Courier New", Courier, monospace',
			__( 'Lora', 'starter-theme' )                  => '"Lora", serif',
			__( 'Merriweather', 'starter-theme' )          => '"Merriweather", serif',
			__( 'Noto Serif', 'starter-theme' )            => '"Noto Serif", serif',
			__( 'Open Sans', 'starter-theme' )             => '"Open Sans", sans-serif',
			__( 'Literata', 'starter-theme' )              => '"Literata", serif',
		);
	}

	/**
	 * Background color presets.
	 *
	 * @return array Associative label => hex.
	 */
	public static function get_bg_presets() {
		return array(
			'white' => '#ffffff',
			'dark'  => '#1a1a2e',
			'sepia' => '#f4ecd8',
		);
	}

	/**
	 * Text color presets.
	 *
	 * @return array
	 */
	public static function get_text_presets() {
		return array(
			'dark'  => '#222222',
			'light' => '#e0e0e0',
			'sepia' => '#5b4636',
		);
	}

	/* ------------------------------------------------------------------
	 * Frontend.
	 * ----------------------------------------------------------------*/

	/**
	 * Enqueue frontend assets on novel chapter pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->should_load() ) {
			return;
		}

		// Optional Google Fonts for the web-font families.
		wp_enqueue_style(
			'starter-novel-gfonts',
			'https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,700;1,400&family=Merriweather:wght@300;400;700&family=Noto+Serif:wght@400;700&family=Open+Sans:wght@400;600;700&family=Literata:wght@400;700&display=swap',
			array(),
			'1.0.0'
		);

		wp_enqueue_style(
			'starter-novel-reading-tools',
			get_template_directory_uri() . '/assets/css/novel-reading-tools.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'starter-novel-reading-tools',
			get_template_directory_uri() . '/assets/js/novel-reading-tools.js',
			array(),
			'1.0.0',
			true
		);

		$user_prefs = $this->get_user_prefs();

		wp_localize_script(
			'starter-novel-reading-tools',
			'starterReadingTools',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'isLoggedIn' => is_user_logged_in(),
				'defaults'   => $this->get_defaults(),
				'userPrefs'  => $user_prefs,
				'fonts'      => self::get_font_options(),
				'bgPresets'  => self::get_bg_presets(),
				'textPresets' => self::get_text_presets(),
				'i18n'       => array(
					'title'       => esc_html__( 'Reading Settings', 'starter-theme' ),
					'background'  => esc_html__( 'Background', 'starter-theme' ),
					'textColor'   => esc_html__( 'Text Color', 'starter-theme' ),
					'fontFamily'  => esc_html__( 'Font', 'starter-theme' ),
					'fontSize'    => esc_html__( 'Font Size', 'starter-theme' ),
					'lineHeight'  => esc_html__( 'Line Height', 'starter-theme' ),
					'reset'       => esc_html__( 'Reset to Defaults', 'starter-theme' ),
					'custom'      => esc_html__( 'Custom', 'starter-theme' ),
				),
			)
		);
	}

	/**
	 * Whether reading tools should load on this page.
	 *
	 * @return bool
	 */
	private function should_load() {
		/** This filter is documented in class-novel-reader.php */
		return (bool) apply_filters( 'starter_is_novel_chapter', is_singular( 'chapter' ) );
	}

	/**
	 * Get current user's reading preferences.
	 *
	 * @return array
	 */
	public function get_user_prefs() {
		if ( ! is_user_logged_in() ) {
			// Guests use localStorage — return defaults so JS can merge.
			return $this->get_defaults();
		}

		$prefs = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );

		if ( ! is_array( $prefs ) ) {
			return $this->get_defaults();
		}

		return wp_parse_args( $prefs, $this->get_defaults() );
	}

	/**
	 * Render the reading customization widget in the footer.
	 *
	 * The actual interactive UI is built by JS; this provides the mount
	 * point and trigger button.
	 *
	 * @return void
	 */
	public function render_widget() {
		if ( ! $this->should_load() ) {
			return;
		}

		$defaults = $this->get_defaults();
		$prefs    = $this->get_user_prefs();
		?>
		<div id="starter-reading-tools" class="starter-reading-tools" aria-label="<?php esc_attr_e( 'Reading customization', 'starter-theme' ); ?>" role="dialog" aria-hidden="true">
			<button type="button" class="starter-reading-tools__toggle" aria-expanded="false" aria-controls="starter-reading-tools-panel" title="<?php esc_attr_e( 'Reading Settings', 'starter-theme' ); ?>">
				<span class="dashicons dashicons-admin-settings"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Open reading settings', 'starter-theme' ); ?></span>
			</button>

			<div id="starter-reading-tools-panel" class="starter-reading-tools__panel" hidden>
				<h3 class="starter-reading-tools__title"><?php esc_html_e( 'Reading Settings', 'starter-theme' ); ?></h3>

				<!-- Background color -->
				<fieldset class="starter-reading-tools__group">
					<legend><?php esc_html_e( 'Background', 'starter-theme' ); ?></legend>
					<div class="starter-reading-tools__presets" data-setting="background_color">
						<?php foreach ( self::get_bg_presets() as $label => $hex ) : ?>
							<button type="button" class="starter-reading-tools__preset-btn" data-value="<?php echo esc_attr( $hex ); ?>" aria-label="<?php echo esc_attr( ucfirst( $label ) ); ?>" style="background-color: <?php echo esc_attr( $hex ); ?>;"></button>
						<?php endforeach; ?>
						<input type="color" class="starter-reading-tools__color-picker" data-setting="background_color" value="<?php echo esc_attr( $prefs['background_color'] ); ?>" title="<?php esc_attr_e( 'Custom color', 'starter-theme' ); ?>">
					</div>
				</fieldset>

				<!-- Text color -->
				<fieldset class="starter-reading-tools__group">
					<legend><?php esc_html_e( 'Text Color', 'starter-theme' ); ?></legend>
					<div class="starter-reading-tools__presets" data-setting="text_color">
						<?php foreach ( self::get_text_presets() as $label => $hex ) : ?>
							<button type="button" class="starter-reading-tools__preset-btn" data-value="<?php echo esc_attr( $hex ); ?>" aria-label="<?php echo esc_attr( ucfirst( $label ) ); ?>" style="background-color: <?php echo esc_attr( $hex ); ?>;"></button>
						<?php endforeach; ?>
						<input type="color" class="starter-reading-tools__color-picker" data-setting="text_color" value="<?php echo esc_attr( $prefs['text_color'] ); ?>" title="<?php esc_attr_e( 'Custom color', 'starter-theme' ); ?>">
					</div>
				</fieldset>

				<!-- Font family -->
				<fieldset class="starter-reading-tools__group">
					<legend><?php esc_html_e( 'Font', 'starter-theme' ); ?></legend>
					<select class="starter-reading-tools__select" data-setting="font_family">
						<?php foreach ( self::get_font_options() as $label => $css_value ) : ?>
							<option value="<?php echo esc_attr( $css_value ); ?>" <?php selected( $prefs['font_family'], $css_value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</fieldset>

				<!-- Font size -->
				<fieldset class="starter-reading-tools__group">
					<legend><?php esc_html_e( 'Font Size', 'starter-theme' ); ?></legend>
					<input type="range" class="starter-reading-tools__range" data-setting="font_size" min="12" max="32" step="1" value="<?php echo esc_attr( intval( $prefs['font_size'] ) ); ?>">
					<output class="starter-reading-tools__output"><?php echo esc_html( intval( $prefs['font_size'] ) ); ?>px</output>
				</fieldset>

				<!-- Line height -->
				<fieldset class="starter-reading-tools__group">
					<legend><?php esc_html_e( 'Line Height', 'starter-theme' ); ?></legend>
					<input type="range" class="starter-reading-tools__range" data-setting="line_height" min="1.2" max="3.0" step="0.1" value="<?php echo esc_attr( floatval( $prefs['line_height'] ) ); ?>">
					<output class="starter-reading-tools__output"><?php echo esc_html( number_format( floatval( $prefs['line_height'] ), 1 ) ); ?></output>
				</fieldset>

				<!-- Reset -->
				<button type="button" class="starter-reading-tools__reset" data-defaults="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>">
					<?php esc_html_e( 'Reset to Defaults', 'starter-theme' ); ?>
				</button>
			</div>
		</div>

		<style id="starter-reading-tools-vars">
			:root {
				--starter-rt-bg: <?php echo esc_attr( $prefs['background_color'] ); ?>;
				--starter-rt-color: <?php echo esc_attr( $prefs['text_color'] ); ?>;
				--starter-rt-font: <?php echo esc_attr( $prefs['font_family'] ); ?>;
				--starter-rt-size: <?php echo esc_attr( intval( $prefs['font_size'] ) ); ?>px;
				--starter-rt-lh: <?php echo esc_attr( floatval( $prefs['line_height'] ) ); ?>;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler — save reading preferences for logged-in users.
	 *
	 * @return void
	 */
	public function ajax_save_prefs() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to save preferences.', 'starter-theme' ) ) );
		}

		$sanitized = $this->sanitize_prefs( $_POST );

		update_user_meta( get_current_user_id(), self::USER_META_KEY, $sanitized );

		wp_send_json_success( $sanitized );
	}

	/**
	 * Sanitize reading preference values.
	 *
	 * @param array $raw Raw input.
	 * @return array Sanitized values.
	 */
	private function sanitize_prefs( $raw ) {
		$defaults = self::factory_defaults();

		$bg = isset( $raw['background_color'] ) ? sanitize_hex_color( $raw['background_color'] ) : '';
		$tc = isset( $raw['text_color'] ) ? sanitize_hex_color( $raw['text_color'] ) : '';

		// Validate font family against whitelist.
		$allowed_fonts = array_values( self::get_font_options() );
		$font          = isset( $raw['font_family'] ) ? sanitize_text_field( $raw['font_family'] ) : '';
		if ( ! in_array( $font, $allowed_fonts, true ) ) {
			$font = $defaults['font_family'];
		}

		$size = isset( $raw['font_size'] ) ? intval( $raw['font_size'] ) : $defaults['font_size'];
		$size = max( 12, min( 32, $size ) );

		$lh = isset( $raw['line_height'] ) ? floatval( $raw['line_height'] ) : $defaults['line_height'];
		$lh = max( 1.2, min( 3.0, round( $lh, 1 ) ) );

		return array(
			'background_color' => $bg ? $bg : $defaults['background_color'],
			'text_color'       => $tc ? $tc : $defaults['text_color'],
			'font_family'      => $font,
			'font_size'        => $size,
			'line_height'      => $lh,
		);
	}

	/* ------------------------------------------------------------------
	 * Admin settings page.
	 * ----------------------------------------------------------------*/

	/**
	 * Register admin submenu page under Manga parent.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=manga',
			__( 'Novel Reading Tools', 'starter-theme' ),
			__( 'Novel Reading Tools', 'starter-theme' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::ADMIN_SLUG,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_admin_settings' ),
			)
		);

		add_settings_section(
			'starter_novel_rt_defaults',
			__( 'Default Reading Settings', 'starter-theme' ),
			function () {
				echo '<p>' . esc_html__( 'Configure the default reading appearance for novel chapters. Users can override these on the frontend.', 'starter-theme' ) . '</p>';
			},
			self::ADMIN_SLUG
		);

		// Background color.
		add_settings_field(
			'background_color',
			__( 'Background Color', 'starter-theme' ),
			array( $this, 'render_color_field' ),
			self::ADMIN_SLUG,
			'starter_novel_rt_defaults',
			array( 'field' => 'background_color' )
		);

		// Text color.
		add_settings_field(
			'text_color',
			__( 'Text Color', 'starter-theme' ),
			array( $this, 'render_color_field' ),
			self::ADMIN_SLUG,
			'starter_novel_rt_defaults',
			array( 'field' => 'text_color' )
		);

		// Font family.
		add_settings_field(
			'font_family',
			__( 'Font Family', 'starter-theme' ),
			array( $this, 'render_font_field' ),
			self::ADMIN_SLUG,
			'starter_novel_rt_defaults'
		);

		// Font size.
		add_settings_field(
			'font_size',
			__( 'Font Size (px)', 'starter-theme' ),
			array( $this, 'render_number_field' ),
			self::ADMIN_SLUG,
			'starter_novel_rt_defaults',
			array(
				'field' => 'font_size',
				'min'   => 12,
				'max'   => 32,
				'step'  => 1,
			)
		);

		// Line height.
		add_settings_field(
			'line_height',
			__( 'Line Height', 'starter-theme' ),
			array( $this, 'render_number_field' ),
			self::ADMIN_SLUG,
			'starter_novel_rt_defaults',
			array(
				'field' => 'line_height',
				'min'   => 1.2,
				'max'   => 3.0,
				'step'  => 0.1,
			)
		);
	}

	/**
	 * Sanitize admin settings on save.
	 *
	 * @param array $input Raw form input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_admin_settings( $input ) {
		return $this->sanitize_prefs( is_array( $input ) ? $input : array() );
	}

	/**
	 * Render a color picker field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_color_field( $args ) {
		$options = $this->get_defaults();
		$field   = $args['field'];
		$value   = isset( $options[ $field ] ) ? $options[ $field ] : '';
		printf(
			'<input type="color" name="%s[%s]" value="%s" class="starter-color-field">',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $field ),
			esc_attr( $value )
		);
	}

	/**
	 * Render font family select field.
	 *
	 * @return void
	 */
	public function render_font_field() {
		$options = $this->get_defaults();
		$current = $options['font_family'];
		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[font_family]">';
		foreach ( self::get_font_options() as $label => $css ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $css ),
				selected( $current, $css, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a number/range field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_number_field( $args ) {
		$options = $this->get_defaults();
		$field   = $args['field'];
		$value   = isset( $options[ $field ] ) ? $options[ $field ] : '';
		printf(
			'<input type="number" name="%s[%s]" value="%s" min="%s" max="%s" step="%s" class="small-text">',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $field ),
			esc_attr( $value ),
			esc_attr( $args['min'] ),
			esc_attr( $args['max'] ),
			esc_attr( $args['step'] )
		);
	}

	/**
	 * Render the admin settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'starter-theme' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Novel Reading Tools', 'starter-theme' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::ADMIN_SLUG );
				do_settings_sections( self::ADMIN_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
