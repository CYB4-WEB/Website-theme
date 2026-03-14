<?php
/**
 * Theme header template.
 *
 * Displays the <head> section and site header with navigation,
 * dark/light mode toggle, AJAX search, and login/register links.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<?php
if ( function_exists( 'wp_body_open' ) ) {
	wp_body_open();
}
?>

<a class="skip-link screen-reader-text" href="#primary">
	<?php esc_html_e( 'Skip to content', 'starter-theme' ); ?>
</a>

<header id="masthead" class="site-header" role="banner">
	<div class="site-header__inner container">

		<!-- Site Branding -->
		<div class="site-branding">
			<?php if ( has_custom_logo() ) : ?>
				<div class="site-logo">
					<?php the_custom_logo(); ?>
				</div>
			<?php endif; ?>

			<div class="site-branding__text">
				<h1 class="site-title">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
						<?php bloginfo( 'name' ); ?>
					</a>
				</h1>
				<?php
				$starter_description = get_bloginfo( 'description', 'display' );
				if ( $starter_description || is_customize_preview() ) :
					?>
					<p class="site-description"><?php echo esc_html( $starter_description ); ?></p>
				<?php endif; ?>
			</div>
		</div><!-- .site-branding -->

		<!-- Primary Navigation -->
		<nav id="site-navigation" class="main-navigation" role="navigation" aria-label="<?php esc_attr_e( 'Primary Menu', 'starter-theme' ); ?>">

			<!-- Mobile Hamburger Button -->
			<button
				class="menu-toggle"
				aria-controls="primary-menu"
				aria-expanded="false"
				aria-label="<?php esc_attr_e( 'Toggle navigation menu', 'starter-theme' ); ?>"
			>
				<span class="hamburger-line"></span>
				<span class="hamburger-line"></span>
				<span class="hamburger-line"></span>
			</button>

			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'menu_id'        => 'primary-menu',
				'menu_class'     => 'primary-menu__list',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 3,
			) );
			?>
		</nav><!-- #site-navigation -->

		<!-- Header Actions -->
		<div class="site-header__actions">

			<!-- AJAX Search Bar -->
			<div class="header-search" role="search">
				<button
					class="header-search__toggle"
					aria-expanded="false"
					aria-controls="header-search-form"
					aria-label="<?php esc_attr_e( 'Open search', 'starter-theme' ); ?>"
				>
					<svg class="icon icon-search" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="11" cy="11" r="8"></circle>
						<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
					</svg>
				</button>

				<form id="header-search-form" class="header-search__form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" aria-hidden="true">
					<label class="screen-reader-text" for="header-search-input">
						<?php esc_html_e( 'Search for:', 'starter-theme' ); ?>
					</label>
					<input
						type="search"
						id="header-search-input"
						class="header-search__input"
						name="s"
						placeholder="<?php esc_attr_e( 'Search manga, novels, videos...', 'starter-theme' ); ?>"
						value="<?php echo esc_attr( get_search_query() ); ?>"
						autocomplete="off"
					>
					<button type="submit" class="header-search__submit" aria-label="<?php esc_attr_e( 'Submit search', 'starter-theme' ); ?>">
						<svg class="icon icon-search" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="11" cy="11" r="8"></circle>
							<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
						</svg>
					</button>
					<div class="header-search__results" aria-live="polite" aria-relevant="additions"></div>
				</form>
			</div><!-- .header-search -->

			<!-- Dark / Light Mode Toggle -->
			<button
				class="dark-mode-toggle"
				aria-label="<?php esc_attr_e( 'Toggle dark mode', 'starter-theme' ); ?>"
				data-theme-toggle
			>
				<svg class="icon icon-moon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"></path>
				</svg>
				<svg class="icon icon-sun" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<circle cx="12" cy="12" r="5"></circle>
					<line x1="12" y1="1" x2="12" y2="3"></line>
					<line x1="12" y1="21" x2="12" y2="23"></line>
					<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
					<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
					<line x1="1" y1="12" x2="3" y2="12"></line>
					<line x1="21" y1="12" x2="23" y2="12"></line>
					<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
					<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
				</svg>
			</button>

			<!-- User Account: Login / Register or Dropdown -->
			<div class="user-account">
				<?php if ( is_user_logged_in() ) : ?>
					<?php $current_user = wp_get_current_user(); ?>
					<button
						class="user-account__toggle"
						aria-expanded="false"
						aria-controls="user-dropdown"
						aria-label="<?php esc_attr_e( 'User menu', 'starter-theme' ); ?>"
					>
						<?php echo get_avatar( $current_user->ID, 32 ); ?>
						<span class="user-account__name"><?php echo esc_html( $current_user->display_name ); ?></span>
					</button>

					<ul id="user-dropdown" class="user-account__dropdown" aria-hidden="true">
						<li>
							<a href="<?php echo esc_url( get_edit_profile_url() ); ?>">
								<?php esc_html_e( 'Profile', 'starter-theme' ); ?>
							</a>
						</li>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url() ); ?>">
									<?php esc_html_e( 'Dashboard', 'starter-theme' ); ?>
								</a>
							</li>
						<?php endif; ?>
						<li>
							<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
								<?php esc_html_e( 'Log Out', 'starter-theme' ); ?>
							</a>
						</li>
					</ul>
				<?php else : ?>
					<div class="user-account__links">
						<a class="btn btn--login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
							<?php esc_html_e( 'Log In', 'starter-theme' ); ?>
						</a>
						<?php if ( get_option( 'users_can_register' ) ) : ?>
							<a class="btn btn--register" href="<?php echo esc_url( wp_registration_url() ); ?>">
								<?php esc_html_e( 'Register', 'starter-theme' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div><!-- .user-account -->

		</div><!-- .site-header__actions -->

	</div><!-- .site-header__inner -->
</header><!-- #masthead -->

<!-- Mobile Navigation Drawer -->
<nav id="mobile-navigation" class="mobile-navigation" role="navigation" aria-label="<?php esc_attr_e( 'Mobile Menu', 'starter-theme' ); ?>" aria-hidden="true">
	<div class="mobile-navigation__overlay"></div>
	<div class="mobile-navigation__panel">
		<button class="mobile-navigation__close" aria-label="<?php esc_attr_e( 'Close menu', 'starter-theme' ); ?>">
			<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				<line x1="18" y1="6" x2="6" y2="18"></line>
				<line x1="6" y1="6" x2="18" y2="18"></line>
			</svg>
		</button>

		<?php
		wp_nav_menu( array(
			'theme_location' => 'mobile',
			'menu_id'        => 'mobile-menu',
			'menu_class'     => 'mobile-menu__list',
			'container'      => false,
			'fallback_cb'    => false,
			'depth'          => 2,
		) );
		?>
	</div>
</nav><!-- #mobile-navigation -->

<div id="page" class="site">
	<div id="content" class="site-content container">
