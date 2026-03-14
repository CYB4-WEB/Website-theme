<?php
/**
 * 404 (Not Found) template.
 *
 * @package starter-theme
 * @since   1.0.0
 */

get_header();
?>

<main id="primary" class="site-main" role="main">

	<section class="error-404 not-found">

		<header class="page-header">
			<h1 class="page-title"><?php esc_html_e( '404 — Page Not Found', 'starter-theme' ); ?></h1>
		</header><!-- .page-header -->

		<div class="page-content">

			<p><?php esc_html_e( 'It looks like the page you are looking for has been moved, deleted, or doesn\'t exist. Try one of the options below.', 'starter-theme' ); ?></p>

			<!-- Search Form -->
			<div class="error-404__search">
				<h2><?php esc_html_e( 'Search Our Site', 'starter-theme' ); ?></h2>
				<?php get_search_form(); ?>
			</div>

			<!-- Recent Posts -->
			<div class="error-404__recent">
				<h2><?php esc_html_e( 'Recent Posts', 'starter-theme' ); ?></h2>
				<ul class="error-404__post-list">
					<?php
					$starter_recent = new WP_Query( array(
						'posts_per_page' => 5,
						'post_status'    => 'publish',
						'no_found_rows'  => true,
					) );

					if ( $starter_recent->have_posts() ) :
						while ( $starter_recent->have_posts() ) :
							$starter_recent->the_post();
							?>
							<li>
								<a href="<?php echo esc_url( get_permalink() ); ?>">
									<?php echo esc_html( get_the_title() ); ?>
								</a>
							</li>
							<?php
						endwhile;
						wp_reset_postdata();
					endif;
					?>
				</ul>
			</div>

			<!-- Categories -->
			<?php
			$starter_categories = get_categories( array(
				'orderby' => 'count',
				'order'   => 'DESC',
				'number'  => 10,
			) );

			if ( ! empty( $starter_categories ) ) :
				?>
				<div class="error-404__categories">
					<h2><?php esc_html_e( 'Browse Categories', 'starter-theme' ); ?></h2>
					<ul class="error-404__category-list">
						<?php foreach ( $starter_categories as $category ) : ?>
							<li>
								<a href="<?php echo esc_url( get_category_link( $category->term_id ) ); ?>">
									<?php echo esc_html( $category->name ); ?>
									<span class="count">(<?php echo esc_html( $category->count ); ?>)</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Home Link -->
			<div class="error-404__home">
				<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Go to Homepage', 'starter-theme' ); ?>
				</a>
			</div>

		</div><!-- .page-content -->

	</section><!-- .error-404 -->

</main><!-- #primary -->

<?php
get_footer();
