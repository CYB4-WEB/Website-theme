<?php
/**
 * Archive template.
 *
 * Displays posts from categories, tags, dates, authors, and
 * custom post type archives with pagination.
 *
 * @package starter-theme
 * @since   1.0.0
 */

get_header();
?>

<main id="primary" class="site-main" role="main">

	<?php if ( have_posts() ) : ?>

		<header class="archive-header">
			<?php
			the_archive_title( '<h1 class="archive-header__title">', '</h1>' );
			the_archive_description( '<div class="archive-header__description">', '</div>' );
			?>
		</header><!-- .archive-header -->

		<div class="posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>

					<?php if ( has_post_thumbnail() ) : ?>
						<a class="post-card__thumbnail" href="<?php echo esc_url( get_permalink() ); ?>">
							<?php the_post_thumbnail( 'starter-cover', array( 'loading' => 'lazy' ) ); ?>
						</a>
					<?php endif; ?>

					<div class="post-card__content">
						<header class="post-card__header">
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
								<span class="post-card__author">
									<?php echo esc_html( get_the_author() ); ?>
								</span>
							</div>
						</header>

						<div class="post-card__excerpt">
							<?php the_excerpt(); ?>
						</div>
					</div>

				</article>

			<?php endwhile; ?>
		</div><!-- .posts-grid -->

		<nav class="pagination" aria-label="<?php esc_attr_e( 'Archive pagination', 'starter-theme' ); ?>">
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
				<p><?php esc_html_e( 'No content matched your request. Try browsing by category or using the search.', 'starter-theme' ); ?></p>
				<?php get_search_form(); ?>
			</div>
		</section>

	<?php endif; ?>

</main><!-- #primary -->

<?php
get_sidebar();
get_footer();
