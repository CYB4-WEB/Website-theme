<?php
/**
 * Front-end user settings and profile management.
 *
 * Provides a front-end settings page where users can edit their profile,
 * manage reading preferences, notification settings, and account details.
 *
 * Shortcode: [starter_user_settings]
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_User_Settings
 */
class Starter_User_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_User_Settings|null
	 */
	private static $instance = null;

	/**
	 * Allowed avatar MIME types.
	 *
	 * @var array
	 */
	private $allowed_avatar_types = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Maximum avatar file size in bytes (2 MB).
	 *
	 * @var int
	 */
	private $max_avatar_size = 2097152;

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_User_Settings
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
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_shortcode( 'starter_user_settings', array( $this, 'render_settings_page' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_starter_save_profile', array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_starter_save_reading_prefs', array( $this, 'ajax_save_reading_prefs' ) );
		add_action( 'wp_ajax_starter_save_notifications', array( $this, 'ajax_save_notifications' ) );
		add_action( 'wp_ajax_starter_change_password', array( $this, 'ajax_change_password' ) );
		add_action( 'wp_ajax_starter_change_email', array( $this, 'ajax_change_email' ) );
		add_action( 'wp_ajax_starter_delete_account', array( $this, 'ajax_delete_account' ) );
		add_action( 'wp_ajax_starter_upload_avatar', array( $this, 'ajax_upload_avatar' ) );
		add_action( 'wp_ajax_starter_remove_avatar', array( $this, 'ajax_remove_avatar' ) );

		// Enqueue scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Custom avatar filter.
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 10, 3 );
	}

	/*------------------------------------------------------------------
	 * Scripts
	 *-----------------------------------------------------------------*/

	/**
	 * Enqueue settings scripts on pages with the shortcode.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'starter_user_settings' ) ) {
			return;
		}

		wp_enqueue_script(
			'starter-user-settings',
			STARTER_THEME_URI . '/assets/js/user-settings.js',
			array( 'jquery' ),
			STARTER_THEME_VERSION,
			true
		);

		wp_localize_script( 'starter-user-settings', 'starterSettings', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'starter_user_settings_nonce' ),
			'i18n'    => array(
				'saving'          => esc_html__( 'Saving...', 'starter-theme' ),
				'saved'           => esc_html__( 'Settings saved.', 'starter-theme' ),
				'error'           => esc_html__( 'An error occurred. Please try again.', 'starter-theme' ),
				'confirm_delete'  => esc_html__( 'Are you sure you want to delete your account? This cannot be undone.', 'starter-theme' ),
				'uploading'       => esc_html__( 'Uploading...', 'starter-theme' ),
			),
		) );
	}

	/*------------------------------------------------------------------
	 * Shortcode renderer
	 *-----------------------------------------------------------------*/

	/**
	 * Render the user settings page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_settings_page( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="starter-auth-notice">' . esc_html__( 'You must be logged in to access settings.', 'starter-theme' ) . '</p>';
		}

		$user    = wp_get_current_user();
		$user_id = $user->ID;

		// Retrieve current meta values.
		$bio             = get_user_meta( $user_id, 'description', true );
		$display_name    = $user->display_name;
		$social_links    = $this->get_social_links( $user_id );
		$reading_prefs   = $this->get_reading_prefs( $user_id );
		$notif_prefs     = $this->get_notification_prefs( $user_id );
		$avatar_url      = $this->get_custom_avatar_url( $user_id );

		$atts = shortcode_atts( array(
			'tab' => 'profile',
		), $atts, 'starter_user_settings' );

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : $atts['tab'];

		ob_start();
		?>
		<div class="starter-settings-wrap">
			<nav class="starter-settings-tabs">
				<a href="#profile" class="starter-settings-tab<?php echo 'profile' === $active_tab ? ' active' : ''; ?>" data-tab="profile">
					<?php esc_html_e( 'Profile', 'starter-theme' ); ?>
				</a>
				<a href="#reading" class="starter-settings-tab<?php echo 'reading' === $active_tab ? ' active' : ''; ?>" data-tab="reading">
					<?php esc_html_e( 'Reading', 'starter-theme' ); ?>
				</a>
				<a href="#notifications" class="starter-settings-tab<?php echo 'notifications' === $active_tab ? ' active' : ''; ?>" data-tab="notifications">
					<?php esc_html_e( 'Notifications', 'starter-theme' ); ?>
				</a>
				<a href="#account" class="starter-settings-tab<?php echo 'account' === $active_tab ? ' active' : ''; ?>" data-tab="account">
					<?php esc_html_e( 'Account', 'starter-theme' ); ?>
				</a>
			</nav>

			<!-- Profile Tab -->
			<div class="starter-settings-panel" id="starter-tab-profile" <?php echo 'profile' !== $active_tab ? 'style="display:none;"' : ''; ?>>
				<form id="starter-profile-form" class="starter-settings-form" enctype="multipart/form-data" novalidate>
					<div class="starter-settings-messages"></div>

					<div class="starter-field starter-avatar-field">
						<label><?php esc_html_e( 'Avatar', 'starter-theme' ); ?></label>
						<div class="starter-avatar-preview">
							<img src="<?php echo esc_url( $avatar_url ? $avatar_url : get_avatar_url( $user_id, array( 'size' => 150 ) ) ); ?>" alt="<?php esc_attr_e( 'Avatar', 'starter-theme' ); ?>" width="150" height="150" />
						</div>
						<input type="file" id="starter-avatar-file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" />
						<p class="starter-field-hint"><?php esc_html_e( 'Max 2 MB. JPG, PNG, GIF, or WebP.', 'starter-theme' ); ?></p>
						<?php if ( $avatar_url ) : ?>
							<button type="button" class="starter-btn starter-btn--small starter-btn--danger" id="starter-remove-avatar">
								<?php esc_html_e( 'Remove Avatar', 'starter-theme' ); ?>
							</button>
						<?php endif; ?>
					</div>

					<div class="starter-field">
						<label for="starter-display-name"><?php esc_html_e( 'Display Name', 'starter-theme' ); ?></label>
						<input type="text" id="starter-display-name" name="display_name" value="<?php echo esc_attr( $display_name ); ?>" required />
					</div>

					<div class="starter-field">
						<label for="starter-bio"><?php esc_html_e( 'Bio', 'starter-theme' ); ?></label>
						<textarea id="starter-bio" name="bio" rows="4" maxlength="500"><?php echo esc_textarea( $bio ); ?></textarea>
						<span class="starter-field-hint"><?php esc_html_e( 'Max 500 characters.', 'starter-theme' ); ?></span>
					</div>

					<fieldset class="starter-fieldset">
						<legend><?php esc_html_e( 'Social Links', 'starter-theme' ); ?></legend>

						<?php foreach ( $this->get_social_fields() as $key => $label ) : ?>
							<div class="starter-field">
								<label for="starter-social-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<input type="url" id="starter-social-<?php echo esc_attr( $key ); ?>" name="social[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_url( isset( $social_links[ $key ] ) ? $social_links[ $key ] : '' ); ?>" placeholder="https://" />
							</div>
						<?php endforeach; ?>
					</fieldset>

					<input type="hidden" name="action" value="starter_save_profile" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Save Profile', 'starter-theme' ); ?>
					</button>
				</form>
			</div>

			<!-- Reading Preferences Tab -->
			<div class="starter-settings-panel" id="starter-tab-reading" <?php echo 'reading' !== $active_tab ? 'style="display:none;"' : ''; ?>>
				<form id="starter-reading-form" class="starter-settings-form" novalidate>
					<div class="starter-settings-messages"></div>

					<div class="starter-field">
						<label for="starter-chapter-layout"><?php esc_html_e( 'Default Chapter Layout', 'starter-theme' ); ?></label>
						<select id="starter-chapter-layout" name="chapter_layout">
							<option value="single" <?php selected( $reading_prefs['chapter_layout'], 'single' ); ?>>
								<?php esc_html_e( 'Single Page (one image at a time)', 'starter-theme' ); ?>
							</option>
							<option value="all" <?php selected( $reading_prefs['chapter_layout'], 'all' ); ?>>
								<?php esc_html_e( 'Long Strip (all images)', 'starter-theme' ); ?>
							</option>
						</select>
					</div>

					<div class="starter-field">
						<label for="starter-reading-direction"><?php esc_html_e( 'Default Reading Direction', 'starter-theme' ); ?></label>
						<select id="starter-reading-direction" name="reading_direction">
							<option value="ltr" <?php selected( $reading_prefs['reading_direction'], 'ltr' ); ?>>
								<?php esc_html_e( 'Left to Right', 'starter-theme' ); ?>
							</option>
							<option value="rtl" <?php selected( $reading_prefs['reading_direction'], 'rtl' ); ?>>
								<?php esc_html_e( 'Right to Left (Manga)', 'starter-theme' ); ?>
							</option>
						</select>
					</div>

					<input type="hidden" name="action" value="starter_save_reading_prefs" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Save Reading Preferences', 'starter-theme' ); ?>
					</button>
				</form>
			</div>

			<!-- Notifications Tab -->
			<div class="starter-settings-panel" id="starter-tab-notifications" <?php echo 'notifications' !== $active_tab ? 'style="display:none;"' : ''; ?>>
				<form id="starter-notifications-form" class="starter-settings-form" novalidate>
					<div class="starter-settings-messages"></div>

					<div class="starter-field starter-field--inline">
						<label>
							<input type="checkbox" name="notif_new_chapter" value="1" <?php checked( $notif_prefs['new_chapter'], '1' ); ?> />
							<?php esc_html_e( 'New chapter in bookmarked series', 'starter-theme' ); ?>
						</label>
					</div>

					<div class="starter-field starter-field--inline">
						<label>
							<input type="checkbox" name="notif_replies" value="1" <?php checked( $notif_prefs['replies'], '1' ); ?> />
							<?php esc_html_e( 'Replies to my comments', 'starter-theme' ); ?>
						</label>
					</div>

					<div class="starter-field starter-field--inline">
						<label>
							<input type="checkbox" name="notif_system" value="1" <?php checked( $notif_prefs['system'], '1' ); ?> />
							<?php esc_html_e( 'System announcements', 'starter-theme' ); ?>
						</label>
					</div>

					<div class="starter-field starter-field--inline">
						<label>
							<input type="checkbox" name="notif_email" value="1" <?php checked( $notif_prefs['email'], '1' ); ?> />
							<?php esc_html_e( 'Receive notifications by email', 'starter-theme' ); ?>
						</label>
					</div>

					<input type="hidden" name="action" value="starter_save_notifications" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Save Notification Preferences', 'starter-theme' ); ?>
					</button>
				</form>
			</div>

			<!-- Account Tab -->
			<div class="starter-settings-panel" id="starter-tab-account" <?php echo 'account' !== $active_tab ? 'style="display:none;"' : ''; ?>>
				<!-- Change Password -->
				<form id="starter-change-password-form" class="starter-settings-form" novalidate>
					<h3><?php esc_html_e( 'Change Password', 'starter-theme' ); ?></h3>
					<div class="starter-settings-messages"></div>

					<div class="starter-field">
						<label for="starter-current-pass"><?php esc_html_e( 'Current Password', 'starter-theme' ); ?></label>
						<input type="password" id="starter-current-pass" name="current_password" required autocomplete="current-password" />
					</div>

					<div class="starter-field">
						<label for="starter-new-pass"><?php esc_html_e( 'New Password', 'starter-theme' ); ?></label>
						<input type="password" id="starter-new-pass" name="new_password" required autocomplete="new-password" />
						<span class="starter-field-hint">
							<?php esc_html_e( 'Min 8 characters, uppercase, lowercase, and a number.', 'starter-theme' ); ?>
						</span>
					</div>

					<div class="starter-field">
						<label for="starter-confirm-pass"><?php esc_html_e( 'Confirm New Password', 'starter-theme' ); ?></label>
						<input type="password" id="starter-confirm-pass" name="confirm_password" required autocomplete="new-password" />
					</div>

					<input type="hidden" name="action" value="starter_change_password" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Update Password', 'starter-theme' ); ?>
					</button>
				</form>

				<hr class="starter-settings-divider" />

				<!-- Change Email -->
				<form id="starter-change-email-form" class="starter-settings-form" novalidate>
					<h3><?php esc_html_e( 'Change Email', 'starter-theme' ); ?></h3>
					<div class="starter-settings-messages"></div>

					<div class="starter-field">
						<label><?php esc_html_e( 'Current Email', 'starter-theme' ); ?></label>
						<p class="starter-field-value"><?php echo esc_html( $user->user_email ); ?></p>
					</div>

					<div class="starter-field">
						<label for="starter-new-email"><?php esc_html_e( 'New Email Address', 'starter-theme' ); ?></label>
						<input type="email" id="starter-new-email" name="new_email" required autocomplete="email" />
					</div>

					<div class="starter-field">
						<label for="starter-email-pass"><?php esc_html_e( 'Current Password (for verification)', 'starter-theme' ); ?></label>
						<input type="password" id="starter-email-pass" name="current_password" required autocomplete="current-password" />
					</div>

					<input type="hidden" name="action" value="starter_change_email" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Update Email', 'starter-theme' ); ?>
					</button>
				</form>

				<hr class="starter-settings-divider" />

				<!-- Delete Account -->
				<form id="starter-delete-account-form" class="starter-settings-form" novalidate>
					<h3><?php esc_html_e( 'Delete Account', 'starter-theme' ); ?></h3>
					<div class="starter-settings-messages"></div>

					<p class="starter-warning">
						<?php esc_html_e( 'This action is permanent and cannot be undone. All your data, bookmarks, and uploaded content will be removed.', 'starter-theme' ); ?>
					</p>

					<div class="starter-field">
						<label for="starter-delete-pass"><?php esc_html_e( 'Enter Your Password to Confirm', 'starter-theme' ); ?></label>
						<input type="password" id="starter-delete-pass" name="current_password" required autocomplete="current-password" />
					</div>

					<div class="starter-field starter-field--inline">
						<label>
							<input type="checkbox" name="confirm_delete" value="1" required />
							<?php esc_html_e( 'I understand this action is irreversible.', 'starter-theme' ); ?>
						</label>
					</div>

					<input type="hidden" name="action" value="starter_delete_account" />
					<?php wp_nonce_field( 'starter_user_settings_nonce', 'starter_settings_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--danger">
						<?php esc_html_e( 'Delete My Account', 'starter-theme' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/*------------------------------------------------------------------
	 * AJAX: Profile
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Save profile settings.
	 *
	 * @return void
	 */
	public function ajax_save_profile() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id      = get_current_user_id();
		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$bio          = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';

		if ( empty( $display_name ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Display name cannot be empty.', 'starter-theme' ) ) );
		}

		// Limit bio length.
		if ( mb_strlen( $bio ) > 500 ) {
			$bio = mb_substr( $bio, 0, 500 );
		}

		wp_update_user( array(
			'ID'           => $user_id,
			'display_name' => $display_name,
		) );

		update_user_meta( $user_id, 'description', $bio );

		// Social links.
		if ( isset( $_POST['social'] ) && is_array( $_POST['social'] ) ) {
			$social = array();
			foreach ( $this->get_social_fields() as $key => $label ) {
				$social[ $key ] = isset( $_POST['social'][ $key ] ) ? esc_url_raw( wp_unslash( $_POST['social'][ $key ] ) ) : '';
			}
			update_user_meta( $user_id, 'starter_social_links', $social );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Profile saved.', 'starter-theme' ) ) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Avatar
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Upload avatar.
	 *
	 * @return void
	 */
	public function ajax_upload_avatar() {
		check_ajax_referer( 'starter_user_settings_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		if ( empty( $_FILES['avatar'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No file uploaded.', 'starter-theme' ) ) );
		}

		$file = $_FILES['avatar'];

		// Validate MIME type.
		$file_type = wp_check_filetype( $file['name'] );
		if ( ! in_array( $file_type['type'], $this->allowed_avatar_types, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.', 'starter-theme' ) ) );
		}

		// Validate size.
		if ( $file['size'] > $this->max_avatar_size ) {
			wp_send_json_error( array( 'message' => esc_html__( 'File is too large. Maximum size is 2 MB.', 'starter-theme' ) ) );
		}

		// Validate it is a real image.
		$image_info = getimagesize( $file['tmp_name'] );
		if ( false === $image_info ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The uploaded file is not a valid image.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();

		// Delete old avatar if exists.
		$this->delete_avatar_file( $user_id );

		// Upload to local storage.
		$upload_dir  = wp_upload_dir();
		$avatar_dir  = $upload_dir['basedir'] . '/starter-avatars';
		$avatar_url  = $upload_dir['baseurl'] . '/starter-avatars';

		if ( ! file_exists( $avatar_dir ) ) {
			wp_mkdir_p( $avatar_dir );

			// Add index.php for security.
			file_put_contents( $avatar_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$extension  = $file_type['ext'];
		$filename   = 'avatar-' . $user_id . '-' . wp_generate_password( 8, false ) . '.' . $extension;
		$filepath   = $avatar_dir . '/' . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $filepath ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to save avatar. Please try again.', 'starter-theme' ) ) );
		}

		// Store in user meta.
		update_user_meta( $user_id, 'starter_avatar_file', $filename );

		wp_send_json_success( array(
			'message'    => esc_html__( 'Avatar uploaded.', 'starter-theme' ),
			'avatar_url' => esc_url( $avatar_url . '/' . $filename ),
		) );
	}

	/**
	 * AJAX: Remove avatar.
	 *
	 * @return void
	 */
	public function ajax_remove_avatar() {
		check_ajax_referer( 'starter_user_settings_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();
		$this->delete_avatar_file( $user_id );
		delete_user_meta( $user_id, 'starter_avatar_file' );

		wp_send_json_success( array(
			'message'    => esc_html__( 'Avatar removed.', 'starter-theme' ),
			'avatar_url' => esc_url( get_avatar_url( $user_id, array( 'size' => 150 ) ) ),
		) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Reading Preferences
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Save reading preferences.
	 *
	 * @return void
	 */
	public function ajax_save_reading_prefs() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();

		$chapter_layout    = isset( $_POST['chapter_layout'] ) ? sanitize_key( $_POST['chapter_layout'] ) : 'single';
		$reading_direction = isset( $_POST['reading_direction'] ) ? sanitize_key( $_POST['reading_direction'] ) : 'ltr';

		// Validate values.
		$chapter_layout    = in_array( $chapter_layout, array( 'single', 'all' ), true ) ? $chapter_layout : 'single';
		$reading_direction = in_array( $reading_direction, array( 'ltr', 'rtl' ), true ) ? $reading_direction : 'ltr';

		update_user_meta( $user_id, 'starter_chapter_layout', $chapter_layout );
		update_user_meta( $user_id, 'starter_reading_direction', $reading_direction );

		wp_send_json_success( array( 'message' => esc_html__( 'Reading preferences saved.', 'starter-theme' ) ) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Notifications
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Save notification preferences.
	 *
	 * @return void
	 */
	public function ajax_save_notifications() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();

		$prefs = array(
			'new_chapter' => ! empty( $_POST['notif_new_chapter'] ) ? '1' : '0',
			'replies'     => ! empty( $_POST['notif_replies'] ) ? '1' : '0',
			'system'      => ! empty( $_POST['notif_system'] ) ? '1' : '0',
			'email'       => ! empty( $_POST['notif_email'] ) ? '1' : '0',
		);

		update_user_meta( $user_id, 'starter_notification_prefs', $prefs );

		wp_send_json_success( array( 'message' => esc_html__( 'Notification preferences saved.', 'starter-theme' ) ) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Change Password
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Change password.
	 *
	 * @return void
	 */
	public function ajax_change_password() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user = wp_get_current_user();

		$current_password = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new_password     = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$confirm_password = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $current_password ) || empty( $new_password ) || empty( $confirm_password ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please fill in all fields.', 'starter-theme' ) ) );
		}

		if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Current password is incorrect.', 'starter-theme' ) ) );
		}

		if ( $new_password !== $confirm_password ) {
			wp_send_json_error( array( 'message' => esc_html__( 'New passwords do not match.', 'starter-theme' ) ) );
		}

		// Validate strength.
		if ( strlen( $new_password ) < 8
			|| ! preg_match( '/[A-Z]/', $new_password )
			|| ! preg_match( '/[a-z]/', $new_password )
			|| ! preg_match( '/[0-9]/', $new_password )
		) {
			wp_send_json_error( array(
				'message' => esc_html__( 'Password must be at least 8 characters with uppercase, lowercase, and a number.', 'starter-theme' ),
			) );
		}

		wp_set_password( $new_password, $user->ID );

		// Re-authenticate.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		wp_send_json_success( array( 'message' => esc_html__( 'Password updated successfully.', 'starter-theme' ) ) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Change Email
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Change email address.
	 *
	 * @return void
	 */
	public function ajax_change_email() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user = wp_get_current_user();

		$new_email        = isset( $_POST['new_email'] ) ? sanitize_email( wp_unslash( $_POST['new_email'] ) ) : '';
		$current_password = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $new_email ) || ! is_email( $new_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a valid email address.', 'starter-theme' ) ) );
		}

		if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Password is incorrect.', 'starter-theme' ) ) );
		}

		if ( $new_email === $user->user_email ) {
			wp_send_json_error( array( 'message' => esc_html__( 'New email is the same as your current email.', 'starter-theme' ) ) );
		}

		if ( email_exists( $new_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This email address is already in use.', 'starter-theme' ) ) );
		}

		wp_update_user( array(
			'ID'         => $user->ID,
			'user_email' => $new_email,
		) );

		wp_send_json_success( array( 'message' => esc_html__( 'Email address updated.', 'starter-theme' ) ) );
	}

	/*------------------------------------------------------------------
	 * AJAX: Delete Account
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Delete user account.
	 *
	 * @return void
	 */
	public function ajax_delete_account() {
		check_ajax_referer( 'starter_user_settings_nonce', 'starter_settings_nonce_field' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user = wp_get_current_user();

		// Prevent admins from deleting their own account via front-end.
		if ( user_can( $user, 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Administrators cannot delete their account from the front-end.', 'starter-theme' ) ) );
		}

		$current_password = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$confirm_delete   = ! empty( $_POST['confirm_delete'] );

		if ( ! $confirm_delete ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please confirm account deletion.', 'starter-theme' ) ) );
		}

		if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Password is incorrect.', 'starter-theme' ) ) );
		}

		// Delete avatar file.
		$this->delete_avatar_file( $user->ID );

		/**
		 * Fires before a user account is deleted from the front-end.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User ID being deleted.
		 */
		do_action( 'starter_before_delete_account', $user->ID );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );

		wp_send_json_success( array(
			'message'  => esc_html__( 'Account deleted.', 'starter-theme' ),
			'redirect' => home_url( '/' ),
		) );
	}

	/*------------------------------------------------------------------
	 * Helper methods
	 *-----------------------------------------------------------------*/

	/**
	 * Get social link fields definition.
	 *
	 * @return array Key => Label pairs.
	 */
	private function get_social_fields() {
		$fields = array(
			'twitter'   => __( 'Twitter / X', 'starter-theme' ),
			'facebook'  => __( 'Facebook', 'starter-theme' ),
			'instagram' => __( 'Instagram', 'starter-theme' ),
			'discord'   => __( 'Discord', 'starter-theme' ),
			'youtube'   => __( 'YouTube', 'starter-theme' ),
			'website'   => __( 'Website', 'starter-theme' ),
		);

		/**
		 * Filter available social-link fields on the user profile.
		 *
		 * @since 1.0.0
		 *
		 * @param array $fields Key => Label pairs.
		 */
		return apply_filters( 'starter_social_link_fields', $fields );
	}

	/**
	 * Get a user's social links.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_social_links( $user_id ) {
		$links = get_user_meta( $user_id, 'starter_social_links', true );
		return is_array( $links ) ? $links : array();
	}

	/**
	 * Get a user's reading preferences.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_reading_prefs( $user_id ) {
		return array(
			'chapter_layout'    => get_user_meta( $user_id, 'starter_chapter_layout', true ) ?: 'single',
			'reading_direction' => get_user_meta( $user_id, 'starter_reading_direction', true ) ?: 'ltr',
		);
	}

	/**
	 * Get a user's notification preferences.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_notification_prefs( $user_id ) {
		$defaults = array(
			'new_chapter' => '1',
			'replies'     => '1',
			'system'      => '1',
			'email'       => '1',
		);

		$prefs = get_user_meta( $user_id, 'starter_notification_prefs', true );

		if ( ! is_array( $prefs ) ) {
			return $defaults;
		}

		return wp_parse_args( $prefs, $defaults );
	}

	/**
	 * Get the custom avatar URL for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string|false URL or false if no custom avatar.
	 */
	private function get_custom_avatar_url( $user_id ) {
		$filename = get_user_meta( $user_id, 'starter_avatar_file', true );

		if ( empty( $filename ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$avatar_url = $upload_dir['baseurl'] . '/starter-avatars/' . $filename;
		$avatar_path = $upload_dir['basedir'] . '/starter-avatars/' . $filename;

		if ( ! file_exists( $avatar_path ) ) {
			return false;
		}

		return $avatar_url;
	}

	/**
	 * Delete the avatar file for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function delete_avatar_file( $user_id ) {
		$filename = get_user_meta( $user_id, 'starter_avatar_file', true );

		if ( empty( $filename ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$filepath   = $upload_dir['basedir'] . '/starter-avatars/' . $filename;

		if ( file_exists( $filepath ) ) {
			wp_delete_file( $filepath );
		}
	}

	/**
	 * Filter the avatar URL to use custom avatar if available.
	 *
	 * @param string $url         Default avatar URL.
	 * @param mixed  $id_or_email User ID, email, or WP_Comment.
	 * @param array  $args        Avatar arguments.
	 * @return string
	 */
	public function filter_avatar_url( $url, $id_or_email, $args ) {
		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = absint( $id_or_email );
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user ) {
				$user_id = $user->ID;
			}
		} elseif ( $id_or_email instanceof WP_Comment ) {
			if ( $id_or_email->user_id ) {
				$user_id = absint( $id_or_email->user_id );
			}
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = $id_or_email->ID;
		}

		if ( $user_id ) {
			$custom_url = $this->get_custom_avatar_url( $user_id );
			if ( $custom_url ) {
				return $custom_url;
			}
		}

		return $url;
	}

	/*------------------------------------------------------------------
	 * Public static helpers
	 *-----------------------------------------------------------------*/

	/**
	 * Get reading preferences for a given user (public access).
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return array
	 */
	public static function get_user_reading_prefs( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$instance = self::get_instance();
		return $instance->get_reading_prefs( $user_id );
	}
}
