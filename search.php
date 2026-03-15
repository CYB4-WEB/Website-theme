<?php
/**
 * Search results template.
 *
 * @package starter-theme
 * @since   1.0.0
 */

get_header();
?>

<main id="primary" class="site-main" role="main">

	<?php if ( have_posts() ) : ?>

		<header class="page-header">
			<h1 class="page-title">
				<?php
				printf(
					/* translators: %s: search query */
					esc_html__( 'Search Results for: %s', 'starter-theme' ),
					'<span>' . esc_html( get_search_query() ) . '</span>'
				);
				?>
			</h1>
		</header><!-- .page-header -->

		<div class="search-results-count">
			<p>
				<?php
				printf(
					/* translators: %s: number of results */
					esc_html( _n( '%s result found', '%s results found', (int) $wp_query->found_posts, 'starter-theme' ) ),
					'<strong>' . esc_html( number_format_i18n( $wp_query->found_posts ) ) . '</strong>'
				);
				?>
			</p>
		</div>

		<div class="posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>

					<?php if ( has_post_thumbnail() ) : ?>
						<a class="post-card__thumbnail" href="<?php echo esc_url( get_permalink() ); ?>">
							<?php the_post_thumbnail( 'starter-thumb', array( 'loading' => 'lazy' ) ); ?>
						</a>
					<?php endif; ?>

					<div class="post-card__content">
						<header class="post-card__header">
							<span class="post-card__type">
								<?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ); ?>
							</span>

							<?php the_title(
								sprintf(
									'<h2 class="post-card__title"><a href="%s" rel="bookmark">',
									esc_url( get_permalink() )
								),
								'</a></h2>'
							); ?>

							<div class="post-card__meta">
								<time class="post-card__date" datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
									<?php echo esc_html( get_the_date() ); ?>
								</time>
							</div>
						</header>

						<div class="post-card__excerpt">
							<?php the_excerpt(); ?>
						</div>
					</div>

				</article>

			<?php endwhile; ?>
		</div><!-- .posts-grid -->

		<nav class="pagination" aria-label="<?php esc_attr_e( 'Search results pagination', 'starter-theme' ); ?>">
			<?php
			the_posts_pagination( array(
				'mid_size'  => 2,
				'prev_text' => sprintf(
					'<span class="screen-reader-text">%s</span><span aria-hidden="true">&laquo;</span>',
					esc_html__( 'Previous page', 'starter-theme' )
				),
				'next_text' => sprintf(
					'<span class="screen-reader-text">%s</span><span aria-hidden="true">&raquo;</span>',
					esc_html__( 'Next page', 'starter-theme' )
				),
			) );
			?>
		</nav>

	<?php else : ?>

		<section class="no-results">
			<header class="page-header">
				<h1 class="page-title"><?php esc_html_e( 'Nothing Found', 'starter-theme' ); ?></h1>
			</header>
			<div class="page-content">
				<p><?php esc_html_e( 'Sorry, nothing matched your search terms. Please try again with different keywords.', 'starter-theme' ); ?></p>
				<?php get_search_form(); ?>
			</div>
		</section>

	<?php endif; ?>

</main><!-- #primary -->

<?php
get_sidebar();
get_footer();
