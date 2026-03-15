<?php
/**
 * Security hardening.
 *
 * Removes unnecessary WordPress head meta, disables XML-RPC,
 * adds HTTP security headers, provides rate-limiting for logins,
 * and supplies helper methods for nonce verification, input sanitization,
 * CSRF protection, and honeypot fields.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Security
 */
class Starter_Security {

	/**
	 * Maximum failed login attempts before lockout.
	 *
	 * @var int
	 */
	const MAX_LOGIN_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds (15 minutes).
	 *
	 * @var int
	 */
	const LOCKOUT_DURATION = 900;

	/**
	 * Transient prefix for login attempt tracking.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'starter_login_attempts_';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Clean up <head>.
		add_action( 'init', array( $this, 'remove_head_junk' ) );

		// Remove WordPress version.
		add_filter( 'the_generator', '__return_empty_string' );
		remove_action( 'wp_head', 'wp_generator' );

		// Disable XML-RPC.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_xmlrpc_server_class', array( $this, 'disable_xmlrpc_class' ) );

		// Security headers.
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );

		// Login rate limiting.
		add_filter( 'authenticate', array( $this, 'check_login_rate_limit' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ) );
		add_action( 'wp_login', array( $this, 'clear_failed_logins' ), 10, 2 );

		// Protect sensitive files on theme activation.
		add_action( 'after_switch_theme', array( $this, 'generate_htaccess_protection' ) );

		// Remove version from enqueued assets.
		add_filter( 'style_loader_src', array( $this, 'remove_version_from_assets' ), 9999 );
		add_filter( 'script_loader_src', array( $this, 'remove_version_from_assets' ), 9999 );

		// Disable file editing in admin.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
	}

	/**
	 * Remove unnecessary links from wp_head.
	 *
	 * @return void
	 */
	public function remove_head_junk() {
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	}

	/**
	 * Return an empty class name to effectively disable XML-RPC.
	 *
	 * @return string
	 */
	public function disable_xmlrpc_class() {
		return 'wp_xmlrpc_server';
	}

	/**
	 * Send security headers on every response.
	 *
	 * @return void
	 */
	public function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-XSS-Protection: 1; mode=block' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( "Permissions-Policy: camera=(), microphone=(), geolocation=()" );

		// Basic Content-Security-Policy — upgrade insecure requests, allow self.
		$csp = apply_filters( 'starter_csp_header', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';" );

		header( 'Content-Security-Policy: ' . $csp );
	}

	/**
	 * Remove version query strings from enqueued assets.
	 *
	 * @param string $src Asset source URL.
	 * @return string
	 */
	public function remove_version_from_assets( $src ) {
		if ( strpos( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	// ---------------------------------------------------------------
	// Login Rate Limiting
	// ---------------------------------------------------------------

	/**
	 * Check whether the IP has exceeded the allowed login attempts.
	 *
	 * @param WP_User|WP_Error|null $user     User object, error, or null.
	 * @param string                $username  Username.
	 * @param string                $password  Password.
	 * @return WP_User|WP_Error|null
	 */
	public function check_login_rate_limit( $user, $username, $password ) {
		if ( empty( $username ) ) {
			return $user;
		}

		$ip       = $this->get_client_ip();
		$key      = self::TRANSIENT_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::MAX_LOGIN_ATTEMPTS ) {
			return new WP_Error(
				'starter_rate_limited',
				sprintf(
					/* translators: %d: lockout duration in minutes */
					esc_html__( 'Too many failed login attempts. Please try again in %d minutes.', 'starter-theme' ),
					ceil( self::LOCKOUT_DURATION / 60 )
				)
			);
		}

		return $user;
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username The attempted username.
	 * @return void
	 */
	public function record_failed_login( $username ) {
		$ip       = $this->get_client_ip();
		$key      = self::TRANSIENT_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );

		set_transient( $key, $attempts + 1, self::LOCKOUT_DURATION );
	}

	/**
	 * Clear failed login count on successful login.
	 *
	 * @param string  $username The username.
	 * @param WP_User $user     The user object.
	 * @return void
	 */
	public function clear_failed_logins( $username, $user ) {
		$ip  = $this->get_client_ip();
		$key = self::TRANSIENT_PREFIX . md5( $ip );
		delete_transient( $key );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain a chain — take the first.
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	// ---------------------------------------------------------------
	// .htaccess Protection
	// ---------------------------------------------------------------

	/**
	 * Generate .htaccess rules to protect .env and other sensitive files.
	 *
	 * Called on theme activation.
	 *
	 * @return void
	 */
	public function generate_htaccess_protection() {
		$theme_dir     = get_template_directory();
		$htaccess_path = $theme_dir . '/.htaccess';

		$rules = <<<'HTACCESS'
# ===== Starter Theme – Sensitive File Protection =====

# Block .env files
<FilesMatch "^\.env(\..+)?$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

# Block common sensitive files
<FilesMatch "(^#.*#|~$|\.bak$|\.log$|\.sql$|\.swp$|\.swo$|composer\.(json|lock)$|package(-lock)?\.json$)">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

# Block access to inc/ directory directly
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^inc/ - [F,L]
</IfModule>

# ===== End Starter Theme Protection =====
HTACCESS;

		// Write only if our marker is not already present.
		$existing = file_exists( $htaccess_path ) ? file_get_contents( $htaccess_path ) : '';

		if ( false === strpos( $existing, 'Starter Theme – Sensitive File Protection' ) ) {
			file_put_contents(
				$htaccess_path,
				$existing . "\n" . $rules . "\n",
				LOCK_EX
			);
		}
	}

	// ---------------------------------------------------------------
	// Nonce Verification Helpers
	// ---------------------------------------------------------------

	/**
	 * Verify a nonce from a request.
	 *
	 * @param string $action Nonce action name.
	 * @param string $key    The $_REQUEST key containing the nonce. Default '_wpnonce'.
	 * @return bool True if valid.
	 */
	public static function verify_nonce( $action, $key = '_wpnonce' ) {
		$nonce = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $action ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Verify an AJAX nonce and die on failure.
	 *
	 * @param string $action Nonce action name.
	 * @param string $key    The request key for the nonce. Default 'nonce'.
	 * @return void
	 */
	public static function verify_ajax_nonce( $action, $key = 'nonce' ) {
		check_ajax_referer( $action, $key );
	}

	// ---------------------------------------------------------------
	// Input Sanitization Helpers
	// ---------------------------------------------------------------

	/**
	 * Sanitize a text string.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	public static function sanitize_text( $input ) {
		return sanitize_text_field( wp_unslash( $input ) );
	}

	/**
	 * Sanitize an email address.
	 *
	 * @param string $input Raw email.
	 * @return string Sanitized email or empty string.
	 */
	public static function sanitize_email( $input ) {
		return sanitize_email( wp_unslash( $input ) );
	}

	/**
	 * Sanitize a URL.
	 *
	 * @param string $input Raw URL.
	 * @return string
	 */
	public static function sanitize_url( $input ) {
		return esc_url_raw( wp_unslash( $input ) );
	}

	/**
	 * Sanitize an integer value.
	 *
	 * @param mixed $input Raw input.
	 * @return int
	 */
	public static function sanitize_int( $input ) {
		return absint( $input );
	}

	/**
	 * Sanitize HTML content (keep allowed tags).
	 *
	 * @param string $input Raw HTML.
	 * @return string
	 */
	public static function sanitize_html( $input ) {
		return wp_kses_post( wp_unslash( $input ) );
	}

	/**
	 * Sanitize an array of values recursively.
	 *
	 * @param array $input Raw array.
	 * @return array
	 */
	public static function sanitize_array( $input ) {
		if ( ! is_array( $input ) ) {
			return self::sanitize_text( $input );
		}

		$clean = array();
		foreach ( $input as $key => $value ) {
			$clean_key            = sanitize_key( $key );
			$clean[ $clean_key ]  = is_array( $value )
				? self::sanitize_array( $value )
				: self::sanitize_text( $value );
		}

		return $clean;
	}

	// ---------------------------------------------------------------
	// CSRF Protection
	// ---------------------------------------------------------------

	/**
	 * Output a hidden CSRF nonce field for a form.
	 *
	 * @param string $action Action name.
	 * @param string $name   Field name. Default 'starter_csrf'.
	 * @return void
	 */
	public static function csrf_field( $action, $name = 'starter_csrf' ) {
		wp_nonce_field( $action, $name, true, true );
	}

	/**
	 * Verify the CSRF token submitted by a form.
	 *
	 * @param string $action Action name.
	 * @param string $name   Field name. Default 'starter_csrf'.
	 * @return bool
	 */
	public static function verify_csrf( $action, $name = 'starter_csrf' ) {
		return self::verify_nonce( $action, $name );
	}

	// ---------------------------------------------------------------
	// Honeypot Field
	// ---------------------------------------------------------------

	/**
	 * Output a honeypot field hidden from real users but visible to bots.
	 *
	 * The field uses CSS to hide it (display:none + position:absolute)
	 * and should always be empty on legitimate submissions.
	 *
	 * @param string $form_id A unique identifier for the form.
	 * @return void
	 */
	public static function honeypot_field( $form_id = 'default' ) {
		$field_name = 'starter_hp_' . sanitize_key( $form_id );
		printf(
			'<div style="position:absolute;left:-9999px;" aria-hidden="true">' .
			'<label for="%1$s">%2$s</label>' .
			'<input type="text" name="%1$s" id="%1$s" value="" tabindex="-1" autocomplete="off" />' .
			'</div>',
			esc_attr( $field_name ),
			esc_html__( 'Leave this field empty', 'starter-theme' )
		);
	}

	/**
	 * Verify the honeypot field is empty (i.e., no bot submission).
	 *
	 * @param string $form_id The form identifier used in honeypot_field().
	 * @return bool True if the field is empty (legitimate submission).
	 */
	public static function verify_honeypot( $form_id = 'default' ) {
		$field_name = 'starter_hp_' . sanitize_key( $form_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified separately.
		$value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) : '';

		return '' === $value;
	}
}
