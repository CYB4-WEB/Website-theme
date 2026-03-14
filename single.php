<?php
/**
 * Single post template.
 *
 * @package starter-theme
 * @since   1.0.0
 */

get_header();
?>

<main id="primary" class="site-main" role="main">

	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<article id="post-<?php the_ID(); ?>" <?php post_class( 'single-post' ); ?>>

			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>

				<div class="entry-meta">
					<span class="entry-meta__author">
						<?php
						printf(
							/* translators: %s: author name */
							esc_html__( 'By %s', 'starter-theme' ),
							'<a href="' . esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ) . '">' . esc_html( get_the_author() ) . '</a>'
						);
						?>
					</span>

					<time class="entry-meta__date" datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
						<?php echo esc_html( get_the_date() ); ?>
					</time>

					<?php
					$starter_categories = get_the_category_list( esc_html__( ', ', 'starter-theme' ) );
					if ( $starter_categories ) :
						?>
						<span class="entry-meta__categories">
							<?php echo wp_kses_post( $starter_categories ); ?>
						</span>
					<?php endif; ?>

					<?php if ( comments_open() ) : ?>
						<span class="entry-meta__comments">
							<?php
							comments_popup_link(
								esc_html__( 'Leave a comment', 'starter-theme' ),
								esc_html__( '1 Comment', 'starter-theme' ),
								/* translators: %: number of comments */
								esc_html__( '% Comments', 'starter-theme' )
							);
							?>
						</span>
					<?php endif; ?>
				</div><!-- .entry-meta -->
			</header><!-- .entry-header -->

			<?php if ( has_post_thumbnail() ) : ?>
				<div class="entry-thumbnail">
					<?php the_post_thumbnail( 'starter-wide', array( 'loading' => 'lazy' ) ); ?>
				</div>
			<?php endif; ?>

			<div class="entry-content">
				<?php
				the_content( sprintf(
					wp_kses(
						/* translators: %s: post title */
						__( 'Continue reading<span class="screen-reader-text"> "%s"</span>', 'starter-theme' ),
						array( 'span' => array( 'class' => array() ) )
					),
					wp_kses_post( get_the_title() )
				) );

				wp_link_pages( array(
					'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'starter-theme' ),
					'after'  => '</div>',
				) );
				?>
			</div><!-- .entry-content -->

			<footer class="entry-footer">
				<?php
				$starter_tags = get_the_tag_list( '', esc_html_x( ', ', 'tag list separator', 'starter-theme' ) );
				if ( $starter_tags ) :
					?>
					<span class="entry-footer__tags">
						<?php
						printf(
							/* translators: %s: tag list */
							esc_html__( 'Tagged: %s', 'starter-theme' ),
							wp_kses_post( $starter_tags )
						);
						?>
					</span>
				<?php endif; ?>
			</footer><!-- .entry-footer -->

		</article><!-- #post-<?php the_ID(); ?> -->

		<?php
		// Post navigation.
		the_post_navigation( array(
			'prev_text' => '<span class="nav-subtitle">' . esc_html__( 'Previous:', 'starter-theme' ) . '</span> <span class="nav-title">%title</span>',
			'next_text' => '<span class="nav-subtitle">' . esc_html__( 'Next:', 'starter-theme' ) . '</span> <span class="nav-title">%title</span>',
		) );

		// Comments.
		if ( comments_open() || get_comments_number() ) :
			comments_template();
		endif;

	endwhile;
	?>

</main><!-- #primary -->

<?php
get_sidebar();
get_footer();
