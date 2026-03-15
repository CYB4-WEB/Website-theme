<?php
/**
 * Theme footer template.
 *
 * Displays three footer widget columns, social links, copyright,
 * and a back-to-top button.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

	</div><!-- #content -->
</div><!-- #page -->

<footer id="colophon" class="site-footer" role="contentinfo">

	<!-- Footer Widget Areas (3 columns) -->
	<?php if ( is_active_sidebar( 'footer-1' ) || is_active_sidebar( 'footer-2' ) || is_active_sidebar( 'footer-3' ) ) : ?>
		<div class="site-footer__widgets container">
			<div class="footer-columns">

				<div class="footer-column footer-column--1">
					<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
						<?php dynamic_sidebar( 'footer-1' ); ?>
					<?php endif; ?>
				</div>

				<div class="footer-column footer-column--2">
					<?php if ( is_active_sidebar( 'footer-2' ) ) : ?>
						<?php dynamic_sidebar( 'footer-2' ); ?>
					<?php endif; ?>
				</div>

				<div class="footer-column footer-column--3">
					<?php if ( is_active_sidebar( 'footer-3' ) ) : ?>
						<?php dynamic_sidebar( 'footer-3' ); ?>
					<?php endif; ?>
				</div>

			</div><!-- .footer-columns -->
		</div><!-- .site-footer__widgets -->
	<?php endif; ?>

	<!-- Social Links -->
	<div class="site-footer__social container">
		<nav class="social-links" aria-label="<?php esc_attr_e( 'Social Links', 'starter-theme' ); ?>">
			<ul class="social-links__list">
				<?php
				/**
				 * Social links are filterable so modules / customizer can inject them.
				 *
				 * @param array $links Array of arrays with 'url', 'label', 'icon' keys.
				 */
				$starter_social_links = apply_filters( 'starter_theme_social_links', array() );

				foreach ( $starter_social_links as $link ) :
					if ( empty( $link['url'] ) || empty( $link['label'] ) ) {
						continue;
					}
					?>
					<li class="social-links__item">
						<a
							href="<?php echo esc_url( $link['url'] ); ?>"
							class="social-links__link"
							target="_blank"
							rel="noopener noreferrer"
							aria-label="<?php echo esc_attr( $link['label'] ); ?>"
						>
							<?php
							if ( ! empty( $link['icon'] ) ) {
								echo wp_kses( $link['icon'], array(
									'svg'    => array(
										'class'       => true,
										'aria-hidden' => true,
										'width'       => true,
										'height'      => true,
										'viewbox'     => true,
										'fill'        => true,
										'xmlns'       => true,
									),
									'path'   => array( 'd' => true, 'fill' => true ),
									'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true ),
								) );
							}
							?>
							<span class="social-links__label"><?php echo esc_html( $link['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div><!-- .site-footer__social -->

	<!-- Footer Bottom: Menu + Copyright -->
	<div class="site-footer__bottom container">
		<?php
		wp_nav_menu( array(
			'theme_location' => 'footer',
			'menu_id'        => 'footer-menu',
			'menu_class'     => 'footer-menu__list',
			'container'      => false,
			'fallback_cb'    => false,
			'depth'          => 1,
		) );
		?>

		<div class="site-footer__copyright">
			<p>
				<?php
				printf(
					/* translators: 1: copyright year, 2: site name */
					esc_html__( '&copy; %1$s %2$s. All rights reserved.', 'starter-theme' ),
					esc_html( gmdate( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</p>
		</div>
	</div><!-- .site-footer__bottom -->

</footer><!-- #colophon -->

<!-- Back to Top Button -->
<button
	class="back-to-top"
	aria-label="<?php esc_attr_e( 'Back to top', 'starter-theme' ); ?>"
	data-back-to-top
>
	<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
		<polyline points="18 15 12 9 6 15"></polyline>
	</svg>
</button>

<?php wp_footer(); ?>

</body>
</html>
