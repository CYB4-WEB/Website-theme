<?php
/**
 * User authentication: front-end login, registration, lost-password.
 *
 * Provides AJAX-based forms with reCAPTCHA v3, honeypot, rate-limiting,
 * password-strength enforcement, email verification, social-login hooks,
 * remember-me, and configurable redirects.
 *
 * Shortcodes: [starter_login], [starter_register], [starter_lost_password]
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_User_Auth
 */
class Starter_User_Auth {

	/*------------------------------------------------------------------
	 * Properties
	 *-----------------------------------------------------------------*/

	/**
	 * Singleton instance.
	 *
	 * @var Starter_User_Auth|null
	 */
	private static $instance = null;

	/**
	 * Maximum login attempts allowed per window.
	 *
	 * @var int
	 */
	private $max_attempts = 5;

	/**
	 * Rate-limit window in seconds (15 minutes).
	 *
	 * @var int
	 */
	private $rate_limit_window = 900;

	/**
	 * reCAPTCHA site key.
	 *
	 * @var string
	 */
	private $recaptcha_site_key = '';

	/**
	 * reCAPTCHA secret key.
	 *
	 * @var string
	 */
	private $recaptcha_secret_key = '';

	/*------------------------------------------------------------------
	 * Bootstrap
	 *-----------------------------------------------------------------*/

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_User_Auth
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
		$this->recaptcha_site_key   = defined( 'STARTER_RECAPTCHA_SITE_KEY' ) ? STARTER_RECAPTCHA_SITE_KEY : '';
		$this->recaptcha_secret_key = defined( 'STARTER_RECAPTCHA_SECRET_KEY' ) ? STARTER_RECAPTCHA_SECRET_KEY : '';

		$this->register_hooks();
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Shortcodes.
		add_shortcode( 'starter_login', array( $this, 'render_login_form' ) );
		add_shortcode( 'starter_register', array( $this, 'render_register_form' ) );
		add_shortcode( 'starter_lost_password', array( $this, 'render_lost_password_form' ) );

		// AJAX endpoints — logged-out users.
		add_action( 'wp_ajax_nopriv_starter_login', array( $this, 'ajax_login' ) );
		add_action( 'wp_ajax_nopriv_starter_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_starter_lost_password', array( $this, 'ajax_lost_password' ) );
		add_action( 'wp_ajax_nopriv_starter_reset_password', array( $this, 'ajax_reset_password' ) );

		// AJAX endpoints — logged-in users (logout).
		add_action( 'wp_ajax_starter_logout', array( $this, 'ajax_logout' ) );

		// Email verification callback.
		add_action( 'init', array( $this, 'handle_email_verification' ) );

		// Enqueue reCAPTCHA script when shortcode is present.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/*------------------------------------------------------------------
	 * Script enqueue
	 *-----------------------------------------------------------------*/

	/**
	 * Enqueue reCAPTCHA v3 and auth scripts on pages that use auth shortcodes.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$has_shortcode = has_shortcode( $post->post_content, 'starter_login' )
			|| has_shortcode( $post->post_content, 'starter_register' )
			|| has_shortcode( $post->post_content, 'starter_lost_password' );

		if ( ! $has_shortcode ) {
			return;
		}

		// reCAPTCHA v3.
		if ( ! empty( $this->recaptcha_site_key ) ) {
			wp_enqueue_script(
				'google-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . esc_attr( $this->recaptcha_site_key ),
				array(),
				null, // External resource — no version.
				true
			);
		}

		// Theme auth script (handles AJAX form submission).
		wp_enqueue_script(
			'starter-auth',
			STARTER_THEME_URI . '/assets/js/auth.js',
			array( 'jquery' ),
			STARTER_THEME_VERSION,
			true
		);

		wp_localize_script( 'starter-auth', 'starterAuth', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'loginNonce'     => wp_create_nonce( 'starter_login_nonce' ),
			'registerNonce'  => wp_create_nonce( 'starter_register_nonce' ),
			'lostPwdNonce'   => wp_create_nonce( 'starter_lost_password_nonce' ),
			'resetPwdNonce'  => wp_create_nonce( 'starter_reset_password_nonce' ),
			'recaptchaSite'  => esc_attr( $this->recaptcha_site_key ),
			'i18n'           => array(
				'logging_in'    => esc_html__( 'Logging in...', 'starter-theme' ),
				'registering'   => esc_html__( 'Creating account...', 'starter-theme' ),
				'sending'       => esc_html__( 'Sending...', 'starter-theme' ),
				'weak_password' => esc_html__( 'Password must be at least 8 characters with uppercase, lowercase, and a number.', 'starter-theme' ),
			),
		) );
	}

	/*------------------------------------------------------------------
	 * Shortcode renderers
	 *-----------------------------------------------------------------*/

	/**
	 * Render the login form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_login_form( $atts ) {
		if ( is_user_logged_in() ) {
			return '<p class="starter-auth-notice">' . esc_html__( 'You are already logged in.', 'starter-theme' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'redirect' => home_url( '/' ),
		), $atts, 'starter_login' );

		ob_start();
		?>
		<div class="starter-auth-wrap starter-login-wrap">
			<form id="starter-login-form" class="starter-auth-form" method="post" novalidate>
				<div class="starter-auth-messages"></div>

				<div class="starter-field">
					<label for="starter-login-user"><?php esc_html_e( 'Username or Email', 'starter-theme' ); ?></label>
					<input type="text" id="starter-login-user" name="user_login" required autocomplete="username" />
				</div>

				<div class="starter-field">
					<label for="starter-login-pass"><?php esc_html_e( 'Password', 'starter-theme' ); ?></label>
					<input type="password" id="starter-login-pass" name="user_pass" required autocomplete="current-password" />
				</div>

				<div class="starter-field starter-field--inline">
					<label>
						<input type="checkbox" name="remember_me" value="1" />
						<?php esc_html_e( 'Remember me', 'starter-theme' ); ?>
					</label>
				</div>

				<?php $this->render_honeypot_field(); ?>

				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $atts['redirect'] ); ?>" />
				<input type="hidden" name="recaptcha_token" value="" />
				<input type="hidden" name="action" value="starter_login" />

				<?php wp_nonce_field( 'starter_login_nonce', 'starter_login_nonce_field' ); ?>

				<button type="submit" class="starter-btn starter-btn--primary">
					<?php esc_html_e( 'Log In', 'starter-theme' ); ?>
				</button>

				<p class="starter-auth-links">
					<a href="<?php echo esc_url( $this->get_page_url( 'lost_password' ) ); ?>"><?php esc_html_e( 'Lost your password?', 'starter-theme' ); ?></a>
					&middot;
					<a href="<?php echo esc_url( $this->get_page_url( 'register' ) ); ?>"><?php esc_html_e( 'Create an account', 'starter-theme' ); ?></a>
				</p>

				<?php
				/**
				 * Fires after the login form fields, before the closing form tag.
				 * Use this hook to add social-login buttons.
				 *
				 * @since 1.0.0
				 */
				do_action( 'starter_login_form_footer' );
				?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the registration form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_register_form( $atts ) {
		if ( is_user_logged_in() ) {
			return '<p class="starter-auth-notice">' . esc_html__( 'You are already logged in.', 'starter-theme' ) . '</p>';
		}

		if ( ! get_option( 'users_can_register' ) ) {
			return '<p class="starter-auth-notice">' . esc_html__( 'Registration is currently disabled.', 'starter-theme' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'redirect' => home_url( '/' ),
		), $atts, 'starter_register' );

		ob_start();
		?>
		<div class="starter-auth-wrap starter-register-wrap">
			<form id="starter-register-form" class="starter-auth-form" method="post" novalidate>
				<div class="starter-auth-messages"></div>

				<div class="starter-field">
					<label for="starter-reg-user"><?php esc_html_e( 'Username', 'starter-theme' ); ?></label>
					<input type="text" id="starter-reg-user" name="user_login" required autocomplete="username" />
				</div>

				<div class="starter-field">
					<label for="starter-reg-email"><?php esc_html_e( 'Email Address', 'starter-theme' ); ?></label>
					<input type="email" id="starter-reg-email" name="user_email" required autocomplete="email" />
				</div>

				<div class="starter-field">
					<label for="starter-reg-pass"><?php esc_html_e( 'Password', 'starter-theme' ); ?></label>
					<input type="password" id="starter-reg-pass" name="user_pass" required autocomplete="new-password" />
					<span class="starter-field-hint">
						<?php esc_html_e( 'Min 8 characters, uppercase, lowercase, and a number.', 'starter-theme' ); ?>
					</span>
				</div>

				<div class="starter-field">
					<label for="starter-reg-pass2"><?php esc_html_e( 'Confirm Password', 'starter-theme' ); ?></label>
					<input type="password" id="starter-reg-pass2" name="user_pass_confirm" required autocomplete="new-password" />
				</div>

				<?php $this->render_honeypot_field(); ?>

				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $atts['redirect'] ); ?>" />
				<input type="hidden" name="recaptcha_token" value="" />
				<input type="hidden" name="action" value="starter_register" />

				<?php wp_nonce_field( 'starter_register_nonce', 'starter_register_nonce_field' ); ?>

				<button type="submit" class="starter-btn starter-btn--primary">
					<?php esc_html_e( 'Register', 'starter-theme' ); ?>
				</button>

				<p class="starter-auth-links">
					<a href="<?php echo esc_url( $this->get_page_url( 'login' ) ); ?>"><?php esc_html_e( 'Already have an account? Log in', 'starter-theme' ); ?></a>
				</p>

				<?php
				/**
				 * Fires after the registration form fields.
				 * Use this hook to add social-login buttons.
				 *
				 * @since 1.0.0
				 */
				do_action( 'starter_register_form_footer' );
				?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the lost-password form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_lost_password_form( $atts ) {
		if ( is_user_logged_in() ) {
			return '<p class="starter-auth-notice">' . esc_html__( 'You are already logged in.', 'starter-theme' ) . '</p>';
		}

		$atts = shortcode_atts( array(), $atts, 'starter_lost_password' );

		// Detect reset-key flow.
		$reset_key  = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$reset_user = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';

		ob_start();
		?>
		<div class="starter-auth-wrap starter-lost-password-wrap">
			<?php if ( ! empty( $reset_key ) && ! empty( $reset_user ) ) : ?>
				<!-- Reset password form -->
				<form id="starter-reset-password-form" class="starter-auth-form" method="post" novalidate>
					<div class="starter-auth-messages"></div>

					<p><?php esc_html_e( 'Enter your new password below.', 'starter-theme' ); ?></p>

					<div class="starter-field">
						<label for="starter-new-pass"><?php esc_html_e( 'New Password', 'starter-theme' ); ?></label>
						<input type="password" id="starter-new-pass" name="new_pass" required autocomplete="new-password" />
					</div>

					<div class="starter-field">
						<label for="starter-new-pass2"><?php esc_html_e( 'Confirm New Password', 'starter-theme' ); ?></label>
						<input type="password" id="starter-new-pass2" name="new_pass_confirm" required autocomplete="new-password" />
					</div>

					<?php $this->render_honeypot_field(); ?>

					<input type="hidden" name="reset_key" value="<?php echo esc_attr( $reset_key ); ?>" />
					<input type="hidden" name="reset_login" value="<?php echo esc_attr( $reset_user ); ?>" />
					<input type="hidden" name="recaptcha_token" value="" />
					<input type="hidden" name="action" value="starter_reset_password" />

					<?php wp_nonce_field( 'starter_reset_password_nonce', 'starter_reset_password_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Reset Password', 'starter-theme' ); ?>
					</button>
				</form>
			<?php else : ?>
				<!-- Request reset link form -->
				<form id="starter-lost-password-form" class="starter-auth-form" method="post" novalidate>
					<div class="starter-auth-messages"></div>

					<p><?php esc_html_e( 'Enter your email address and we will send you a link to reset your password.', 'starter-theme' ); ?></p>

					<div class="starter-field">
						<label for="starter-lost-email"><?php esc_html_e( 'Email Address', 'starter-theme' ); ?></label>
						<input type="email" id="starter-lost-email" name="user_email" required autocomplete="email" />
					</div>

					<?php $this->render_honeypot_field(); ?>

					<input type="hidden" name="recaptcha_token" value="" />
					<input type="hidden" name="action" value="starter_lost_password" />

					<?php wp_nonce_field( 'starter_lost_password_nonce', 'starter_lost_password_nonce_field' ); ?>

					<button type="submit" class="starter-btn starter-btn--primary">
						<?php esc_html_e( 'Send Reset Link', 'starter-theme' ); ?>
					</button>

					<p class="starter-auth-links">
						<a href="<?php echo esc_url( $this->get_page_url( 'login' ) ); ?>"><?php esc_html_e( 'Back to login', 'starter-theme' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/*------------------------------------------------------------------
	 * AJAX handlers
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX: Handle login.
	 *
	 * @return void
	 */
	public function ajax_login() {
		check_ajax_referer( 'starter_login_nonce', 'starter_login_nonce_field' );

		// Honeypot check.
		if ( $this->honeypot_triggered() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Spam detected.', 'starter-theme' ) ) );
		}

		$ip = $this->get_client_ip();

		// Rate limiting.
		if ( $this->is_rate_limited( $ip ) ) {
			wp_send_json_error( array(
				'message' => esc_html__( 'Too many login attempts. Please try again in 15 minutes.', 'starter-theme' ),
			) );
		}

		// reCAPTCHA verification.
		if ( ! $this->verify_recaptcha( 'login' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'reCAPTCHA verification failed. Please try again.', 'starter-theme' ) ) );
		}

		$user_login  = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		$user_pass   = isset( $_POST['user_pass'] ) ? wp_unslash( $_POST['user_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw password.
		$remember    = ! empty( $_POST['remember_me'] );
		$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );

		if ( empty( $user_login ) || empty( $user_pass ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please fill in all fields.', 'starter-theme' ) ) );
		}

		$creds = array(
			'user_login'    => $user_login,
			'user_password' => $user_pass,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			$this->increment_login_attempts( $ip );
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid username or password.', 'starter-theme' ) ) );
		}

		// Check email verification.
		$verified = get_user_meta( $user->ID, 'starter_email_verified', true );
		if ( '1' !== $verified && apply_filters( 'starter_require_email_verification', true ) ) {
			wp_logout();
			wp_send_json_error( array(
				'message' => esc_html__( 'Please verify your email address before logging in. Check your inbox for the verification link.', 'starter-theme' ),
			) );
		}

		/**
		 * Filter the redirect URL after successful login.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $redirect_to Redirect URL.
		 * @param WP_User $user        Authenticated user object.
		 */
		$redirect_to = apply_filters( 'starter_login_redirect', $redirect_to, $user );

		wp_send_json_success( array(
			'message'  => esc_html__( 'Login successful. Redirecting...', 'starter-theme' ),
			'redirect' => esc_url( $redirect_to ),
		) );
	}

	/**
	 * AJAX: Handle registration.
	 *
	 * @return void
	 */
	public function ajax_register() {
		check_ajax_referer( 'starter_register_nonce', 'starter_register_nonce_field' );

		if ( $this->honeypot_triggered() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Spam detected.', 'starter-theme' ) ) );
		}

		if ( ! get_option( 'users_can_register' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Registration is currently disabled.', 'starter-theme' ) ) );
		}

		// reCAPTCHA verification.
		if ( ! $this->verify_recaptcha( 'register' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'reCAPTCHA verification failed. Please try again.', 'starter-theme' ) ) );
		}

		$user_login   = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ) ) : '';
		$user_email   = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
		$user_pass    = isset( $_POST['user_pass'] ) ? wp_unslash( $_POST['user_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_pass2   = isset( $_POST['user_pass_confirm'] ) ? wp_unslash( $_POST['user_pass_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect_to  = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );

		// Validation.
		if ( empty( $user_login ) || empty( $user_email ) || empty( $user_pass ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please fill in all fields.', 'starter-theme' ) ) );
		}

		if ( $user_pass !== $user_pass2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Passwords do not match.', 'starter-theme' ) ) );
		}

		if ( ! $this->is_password_strong( $user_pass ) ) {
			wp_send_json_error( array(
				'message' => esc_html__( 'Password must be at least 8 characters and contain uppercase, lowercase, and a number.', 'starter-theme' ),
			) );
		}

		if ( ! is_email( $user_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a valid email address.', 'starter-theme' ) ) );
		}

		if ( username_exists( $user_login ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This username is already taken.', 'starter-theme' ) ) );
		}

		if ( email_exists( $user_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This email is already registered.', 'starter-theme' ) ) );
		}

		// Sanitize username.
		if ( ! validate_username( $user_login ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid username. Use only letters, numbers, and underscores.', 'starter-theme' ) ) );
		}

		/**
		 * Fires before a new user is created.
		 *
		 * @since 1.0.0
		 *
		 * @param string $user_login Username.
		 * @param string $user_email Email address.
		 */
		do_action( 'starter_before_register', $user_login, $user_email );

		$user_id = wp_insert_user( array(
			'user_login' => $user_login,
			'user_email' => $user_email,
			'user_pass'  => $user_pass,
			'role'       => 'starter_member',
		) );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// Mark email as unverified.
		update_user_meta( $user_id, 'starter_email_verified', '0' );

		// Send verification email.
		$this->send_verification_email( $user_id );

		/**
		 * Fires after a new user is registered.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id    New user ID.
		 * @param string $user_login Username.
		 * @param string $user_email Email address.
		 */
		do_action( 'starter_after_register', $user_id, $user_login, $user_email );

		wp_send_json_success( array(
			'message'  => esc_html__( 'Registration successful! Please check your email to verify your account.', 'starter-theme' ),
			'redirect' => esc_url( $redirect_to ),
		) );
	}

	/**
	 * AJAX: Handle lost-password request.
	 *
	 * @return void
	 */
	public function ajax_lost_password() {
		check_ajax_referer( 'starter_lost_password_nonce', 'starter_lost_password_nonce_field' );

		if ( $this->honeypot_triggered() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Spam detected.', 'starter-theme' ) ) );
		}

		if ( ! $this->verify_recaptcha( 'lost_password' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'reCAPTCHA verification failed.', 'starter-theme' ) ) );
		}

		$user_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';

		if ( empty( $user_email ) || ! is_email( $user_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter a valid email address.', 'starter-theme' ) ) );
		}

		$user = get_user_by( 'email', $user_email );

		// Always return success to avoid user enumeration.
		if ( ! $user ) {
			wp_send_json_success( array(
				'message' => esc_html__( 'If an account exists with that email, a reset link has been sent.', 'starter-theme' ),
			) );
		}

		$reset_key = get_password_reset_key( $user );

		if ( is_wp_error( $reset_key ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unable to generate reset link. Please try again later.', 'starter-theme' ) ) );
		}

		$reset_url = add_query_arg( array(
			'key'   => $reset_key,
			'login' => rawurlencode( $user->user_login ),
		), $this->get_page_url( 'lost_password' ) );

		$subject = sprintf(
			/* translators: %s: Site name */
			esc_html__( '[%s] Password Reset Request', 'starter-theme' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: username, 2: reset URL */
			esc_html__( "Hello %1\$s,\n\nYou requested a password reset. Click the link below to set a new password:\n\n%2\$s\n\nIf you did not request this, you can safely ignore this email.\n\nThanks,\n%3\$s", 'starter-theme' ),
			$user->user_login,
			esc_url( $reset_url ),
			get_bloginfo( 'name' )
		);

		wp_mail( $user_email, $subject, $message );

		wp_send_json_success( array(
			'message' => esc_html__( 'If an account exists with that email, a reset link has been sent.', 'starter-theme' ),
		) );
	}

	/**
	 * AJAX: Handle password reset.
	 *
	 * @return void
	 */
	public function ajax_reset_password() {
		check_ajax_referer( 'starter_reset_password_nonce', 'starter_reset_password_nonce_field' );

		if ( $this->honeypot_triggered() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Spam detected.', 'starter-theme' ) ) );
		}

		if ( ! $this->verify_recaptcha( 'reset_password' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'reCAPTCHA verification failed.', 'starter-theme' ) ) );
		}

		$reset_key   = isset( $_POST['reset_key'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_key'] ) ) : '';
		$reset_login = isset( $_POST['reset_login'] ) ? sanitize_user( wp_unslash( $_POST['reset_login'] ) ) : '';
		$new_pass    = isset( $_POST['new_pass'] ) ? wp_unslash( $_POST['new_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new_pass2   = isset( $_POST['new_pass_confirm'] ) ? wp_unslash( $_POST['new_pass_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $reset_key ) || empty( $reset_login ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid reset link.', 'starter-theme' ) ) );
		}

		if ( empty( $new_pass ) || $new_pass !== $new_pass2 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Passwords do not match.', 'starter-theme' ) ) );
		}

		if ( ! $this->is_password_strong( $new_pass ) ) {
			wp_send_json_error( array(
				'message' => esc_html__( 'Password must be at least 8 characters and contain uppercase, lowercase, and a number.', 'starter-theme' ),
			) );
		}

		$user = check_password_reset_key( $reset_key, $reset_login );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid or expired reset link. Please request a new one.', 'starter-theme' ) ) );
		}

		reset_password( $user, $new_pass );

		wp_send_json_success( array(
			'message'  => esc_html__( 'Password has been reset successfully. You can now log in.', 'starter-theme' ),
			'redirect' => esc_url( $this->get_page_url( 'login' ) ),
		) );
	}

	/**
	 * AJAX: Handle logout.
	 *
	 * @return void
	 */
	public function ajax_logout() {
		check_ajax_referer( 'starter_login_nonce', 'nonce' );

		wp_logout();

		wp_send_json_success( array(
			'message'  => esc_html__( 'Logged out successfully.', 'starter-theme' ),
			'redirect' => home_url( '/' ),
		) );
	}

	/*------------------------------------------------------------------
	 * Email verification
	 *-----------------------------------------------------------------*/

	/**
	 * Send a verification email to a newly registered user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function send_verification_email( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$token = wp_generate_password( 32, false );
		update_user_meta( $user_id, 'starter_email_verify_token', $token );
		update_user_meta( $user_id, 'starter_email_verify_expiry', time() + DAY_IN_SECONDS );

		$verify_url = add_query_arg( array(
			'starter_verify_email' => '1',
			'token'                => $token,
			'uid'                  => $user_id,
		), home_url( '/' ) );

		$subject = sprintf(
			/* translators: %s: Site name */
			esc_html__( '[%s] Verify Your Email Address', 'starter-theme' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: username, 2: verification URL, 3: site name */
			esc_html__( "Welcome %1\$s!\n\nPlease verify your email address by clicking the link below:\n\n%2\$s\n\nThis link expires in 24 hours.\n\nThanks,\n%3\$s", 'starter-theme' ),
			$user->user_login,
			esc_url( $verify_url ),
			get_bloginfo( 'name' )
		);

		return wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Handle the email verification link callback.
	 *
	 * @return void
	 */
	public function handle_email_verification() {
		if ( empty( $_GET['starter_verify_email'] ) ) {
			return;
		}

		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$user_id = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;

		if ( empty( $token ) || empty( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'verify', 'invalid', $this->get_page_url( 'login' ) ) );
			exit;
		}

		$stored_token  = get_user_meta( $user_id, 'starter_email_verify_token', true );
		$stored_expiry = (int) get_user_meta( $user_id, 'starter_email_verify_expiry', true );

		if ( ! hash_equals( $stored_token, $token ) || time() > $stored_expiry ) {
			wp_safe_redirect( add_query_arg( 'verify', 'expired', $this->get_page_url( 'login' ) ) );
			exit;
		}

		update_user_meta( $user_id, 'starter_email_verified', '1' );
		delete_user_meta( $user_id, 'starter_email_verify_token' );
		delete_user_meta( $user_id, 'starter_email_verify_expiry' );

		/**
		 * Fires after a user verifies their email.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id Verified user ID.
		 */
		do_action( 'starter_email_verified', $user_id );

		wp_safe_redirect( add_query_arg( 'verify', 'success', $this->get_page_url( 'login' ) ) );
		exit;
	}

	/*------------------------------------------------------------------
	 * Rate limiting
	 *-----------------------------------------------------------------*/

	/**
	 * Check if the given IP is rate-limited.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function is_rate_limited( $ip ) {
		$transient_key = 'starter_login_attempts_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		return $attempts >= $this->max_attempts;
	}

	/**
	 * Increment login attempt count for an IP.
	 *
	 * @param string $ip Client IP.
	 * @return void
	 */
	private function increment_login_attempts( $ip ) {
		$transient_key = 'starter_login_attempts_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		set_transient( $transient_key, $attempts + 1, $this->rate_limit_window );
	}

	/*------------------------------------------------------------------
	 * reCAPTCHA
	 *-----------------------------------------------------------------*/

	/**
	 * Verify reCAPTCHA v3 token.
	 *
	 * @param string $expected_action The action name expected in the response.
	 * @return bool
	 */
	private function verify_recaptcha( $expected_action = 'login' ) {
		// Skip if keys are not configured.
		if ( empty( $this->recaptcha_secret_key ) || empty( $this->recaptcha_site_key ) ) {
			return true;
		}

		$token = isset( $_POST['recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_token'] ) ) : '';

		if ( empty( $token ) ) {
			return false;
		}

		$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'body' => array(
				'secret'   => $this->recaptcha_secret_key,
				'response' => $token,
				'remoteip' => $this->get_client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			// Fail open on network error (configurable).
			return apply_filters( 'starter_recaptcha_fail_open', false );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['success'] ) ) {
			return false;
		}

		// Verify action matches.
		if ( isset( $body['action'] ) && $body['action'] !== $expected_action ) {
			return false;
		}

		// Check score threshold.
		$threshold = apply_filters( 'starter_recaptcha_threshold', 0.5 );
		if ( isset( $body['score'] ) && $body['score'] < $threshold ) {
			return false;
		}

		return true;
	}

	/*------------------------------------------------------------------
	 * Honeypot
	 *-----------------------------------------------------------------*/

	/**
	 * Render a honeypot hidden field in the form.
	 *
	 * @return void
	 */
	private function render_honeypot_field() {
		?>
		<div class="starter-hp-field" style="position:absolute;left:-9999px;" aria-hidden="true">
			<label for="starter-hp-website"><?php esc_html_e( 'Website', 'starter-theme' ); ?></label>
			<input type="text" id="starter-hp-website" name="starter_hp_website" value="" tabindex="-1" autocomplete="off" />
		</div>
		<?php
	}

	/**
	 * Check if the honeypot field was filled (indicates a bot).
	 *
	 * @return bool True if honeypot was triggered (i.e., a bot).
	 */
	private function honeypot_triggered() {
		return ! empty( $_POST['starter_hp_website'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked before this call.
	}

	/*------------------------------------------------------------------
	 * Password strength
	 *-----------------------------------------------------------------*/

	/**
	 * Validate password strength.
	 *
	 * Requires minimum 8 characters, at least one uppercase letter,
	 * one lowercase letter, and one digit.
	 *
	 * @param string $password Plain-text password.
	 * @return bool
	 */
	private function is_password_strong( $password ) {
		if ( strlen( $password ) < 8 ) {
			return false;
		}

		if ( ! preg_match( '/[A-Z]/', $password ) ) {
			return false;
		}

		if ( ! preg_match( '/[a-z]/', $password ) ) {
			return false;
		}

		if ( ! preg_match( '/[0-9]/', $password ) ) {
			return false;
		}

		return true;
	}

	/*------------------------------------------------------------------
	 * Helpers
	 *-----------------------------------------------------------------*/

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return $ip;
	}

	/**
	 * Get the URL for a theme auth page.
	 *
	 * Falls back to wp-login.php if no custom page is configured.
	 *
	 * @param string $page One of 'login', 'register', 'lost_password'.
	 * @return string
	 */
	private function get_page_url( $page ) {
		$page_ids = array(
			'login'         => get_theme_mod( 'starter_login_page', 0 ),
			'register'      => get_theme_mod( 'starter_register_page', 0 ),
			'lost_password' => get_theme_mod( 'starter_lost_password_page', 0 ),
		);

		$page_id = isset( $page_ids[ $page ] ) ? absint( $page_ids[ $page ] ) : 0;

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return get_permalink( $page_id );
		}

		// Fallback.
		switch ( $page ) {
			case 'register':
				return wp_registration_url();
			case 'lost_password':
				return wp_lostpassword_url();
			default:
				return wp_login_url();
		}
	}

	/*------------------------------------------------------------------
	 * Social Login Hooks (extensible)
	 *-----------------------------------------------------------------*/

	/**
	 * Register a social-login provider.
	 *
	 * Providers should hook into 'starter_login_form_footer' and
	 * 'starter_register_form_footer' to render their buttons, and handle
	 * OAuth callbacks independently.
	 *
	 * This method provides a standard way to register a provider so the
	 * theme can list available providers in settings.
	 *
	 * @param string $provider_id   Unique provider slug (e.g., 'google', 'facebook').
	 * @param array  $provider_args {
	 *     Provider configuration.
	 *
	 *     @type string   $label        Display label.
	 *     @type string   $icon         Icon CSS class or SVG.
	 *     @type callable $auth_url_cb  Callback that returns the OAuth authorization URL.
	 *     @type callable $callback_cb  Callback that handles the OAuth callback.
	 * }
	 * @return void
	 */
	public static function register_social_provider( $provider_id, $provider_args ) {
		$defaults = array(
			'label'       => '',
			'icon'        => '',
			'auth_url_cb' => '__return_empty_string',
			'callback_cb' => '__return_false',
		);

		$provider_args = wp_parse_args( $provider_args, $defaults );

		/**
		 * Filter registered social-login providers.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $provider_args Provider configuration.
		 * @param string $provider_id   Provider slug.
		 */
		$provider_args = apply_filters( 'starter_social_provider', $provider_args, $provider_id );

		// Store in a global registry.
		global $starter_social_providers;

		if ( ! is_array( $starter_social_providers ) ) {
			$starter_social_providers = array();
		}

		$starter_social_providers[ sanitize_key( $provider_id ) ] = $provider_args;
	}

	/**
	 * Get all registered social-login providers.
	 *
	 * @return array
	 */
	public static function get_social_providers() {
		global $starter_social_providers;
		return is_array( $starter_social_providers ) ? $starter_social_providers : array();
	}
}
