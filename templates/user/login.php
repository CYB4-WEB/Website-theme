<?php
/**
 * Template: Front-end Login
 *
 * Glass-morphism login card with AJAX form submission, social login
 * hooks, reCAPTCHA placeholder, and show/hide password toggle.
 *
 * Template Name: Login
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Redirect already-authenticated users. */
if ( is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$register_url = function_exists( 'starter_get_register_url' )
	? starter_get_register_url()
	: wp_registration_url();
$forgot_url   = function_exists( 'starter_get_forgot_password_url' )
	? starter_get_forgot_password_url()
	: wp_lostpassword_url();

get_header();
?>

<main id="primary" class="auth-page" role="main">

	<div class="auth-card auth-card--glass" aria-labelledby="login-heading">

		<!-- Logo / Site Name -->
		<div class="auth-card__brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="auth-card__site-name">
					<?php bloginfo( 'name' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<h1 id="login-heading" class="auth-card__title"><?php esc_html_e( 'Log In', 'starter-theme' ); ?></h1>

		<!-- Error / Success message container -->
		<div class="auth-card__messages" id="login-messages" role="alert" aria-live="polite"></div>

		<!-- Login Form (AJAX) -->
		<form class="auth-form" id="starter-login-form" method="post" novalidate>

			<?php wp_nonce_field( 'starter_login_nonce', 'starter_login_nonce_field' ); ?>
			<input type="hidden" name="action" value="starter_ajax_login">

			<!-- Username / Email -->
			<div class="auth-form__field">
				<label for="login-username" class="auth-form__label"><?php esc_html_e( 'Username or Email', 'starter-theme' ); ?></label>
				<div class="auth-form__input-wrap">
					<span class="auth-form__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
					</span>
					<input
						type="text"
						id="login-username"
						name="log"
						class="auth-form__input"
						placeholder="<?php esc_attr_e( 'Enter your username or email', 'starter-theme' ); ?>"
						required
						autocomplete="username"
					>
				</div>
			</div>

			<!-- Password -->
			<div class="auth-form__field">
				<label for="login-password" class="auth-form__label"><?php esc_html_e( 'Password', 'starter-theme' ); ?></label>
				<div class="auth-form__input-wrap">
					<span class="auth-form__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
					</span>
					<input
						type="password"
						id="login-password"
						name="pwd"
						class="auth-form__input"
						placeholder="<?php esc_attr_e( 'Enter your password', 'starter-theme' ); ?>"
						required
						autocomplete="current-password"
					>
					<button type="button" class="auth-form__toggle-pw" data-toggle-password="login-password" aria-label="<?php esc_attr_e( 'Show password', 'starter-theme' ); ?>">
						<svg class="icon-eye" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
						<svg class="icon-eye-off" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
					</button>
				</div>
			</div>

			<!-- Remember me -->
			<div class="auth-form__field auth-form__field--row">
				<label class="auth-form__checkbox">
					<input type="checkbox" name="rememberme" value="forever">
					<span><?php esc_html_e( 'Remember me', 'starter-theme' ); ?></span>
				</label>
				<a href="<?php echo esc_url( $forgot_url ); ?>" class="auth-form__link">
					<?php esc_html_e( 'Forgot Password?', 'starter-theme' ); ?>
				</a>
			</div>

			<!-- reCAPTCHA placeholder -->
			<div class="auth-form__recaptcha" id="login-recaptcha" data-recaptcha-placeholder>
				<?php
				/**
				 * Hook: starter_login_recaptcha
				 *
				 * Allows reCAPTCHA plugins to inject their widget here.
				 */
				do_action( 'starter_login_recaptcha' );
				?>
			</div>

			<!-- Submit -->
			<button type="submit" class="btn btn--primary btn--full auth-form__submit" id="login-submit">
				<span class="auth-form__submit-text"><?php esc_html_e( 'Log In', 'starter-theme' ); ?></span>
				<span class="auth-form__spinner" hidden aria-hidden="true"></span>
			</button>

		</form>

		<!-- Social Login Hooks -->
		<div class="auth-card__social" id="login-social">
			<?php
			/**
			 * Hook: starter_social_login_buttons
			 *
			 * Plugins (e.g. Nextend Social Login) can inject OAuth buttons here.
			 */
			if ( has_action( 'starter_social_login_buttons' ) ) : ?>
				<div class="auth-card__divider">
					<span><?php esc_html_e( 'or', 'starter-theme' ); ?></span>
				</div>
				<?php do_action( 'starter_social_login_buttons' ); ?>
			<?php endif; ?>
		</div>

		<!-- Register link -->
		<?php if ( get_option( 'users_can_register' ) ) : ?>
			<p class="auth-card__footer">
				<?php
				printf(
					/* translators: %s: register page link */
					esc_html__( 'Don\'t have an account? %s', 'starter-theme' ),
					'<a href="' . esc_url( $register_url ) . '">' . esc_html__( 'Register', 'starter-theme' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

	</div>

</main>

<?php
get_footer();
