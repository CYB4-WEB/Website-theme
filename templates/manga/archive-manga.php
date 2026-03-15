<?php
/**
 * Template: Manga Archive / Listing
 *
 * Displays a filterable, paginated grid of manga, novels, or videos.
 *
 * Template Name: Manga Archive
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Determine page title ─────────────────────────────────── */
$archive_title = esc_html__( 'All Manga', 'starter-theme' );

if ( is_tax( 'genre' ) ) {
	$archive_title = single_term_title( '', false );
} elseif ( is_tax( 'manga_tag' ) ) {
	$archive_title = single_term_title( '', false );
} elseif ( is_post_type_archive( 'manga' ) ) {
	$archive_title = post_type_archive_title( '', false );
}

$archive_title = apply_filters( 'starter_archive_manga_title', $archive_title );

/* ── Grid columns (from theme option, default 4) ──────────── */
$grid_cols     = function_exists( 'starter_get_option' ) ? starter_get_option( 'manga_grid_columns', 4 ) : 4;
$grid_cols     = max( 2, min( 5, (int) $grid_cols ) );
$thumb_layout  = function_exists( 'starter_get_option' ) ? starter_get_option( 'manga_thumb_layout', 'small' ) : 'small';

/* ── Filter values from request ───────────────────────────── */
$filter_genre  = isset( $_GET['genre'] ) ? sanitize_text_field( wp_unslash( $_GET['genre'] ) ) : '';
$filter_type   = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
$filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
$filter_sort   = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : 'latest';

$genres_terms = get_terms( array( 'taxonomy' => 'genre', 'hide_empty' => true ) );

get_header();
?>

<main id="primary" class="site-main archive-manga" role="main">

	<header class="archive-manga__header">
		<h1 class="archive-manga__title"><?php echo esc_html( $archive_title ); ?></h1>
	</header>

	<!-- ── Filter Bar ──────────────────────────────────────── -->
	<div class="archive-manga__filters" data-manga-filters>
		<form class="filter-bar" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>" aria-label="<?php esc_attr_e( 'Filter manga', 'starter-theme' ); ?>">

			<!-- Genre -->
			<div class="filter-bar__field">
				<label for="filter-genre" class="screen-reader-text"><?php esc_html_e( 'Genre', 'starter-theme' ); ?></label>
				<select id="filter-genre" name="genre" class="filter-bar__select">
					<option value=""><?php esc_html_e( 'All Genres', 'starter-theme' ); ?></option>
					<?php if ( ! empty( $genres_terms ) && ! is_wp_error( $genres_terms ) ) : ?>
						<?php foreach ( $genres_terms as $g ) : ?>
							<option value="<?php echo esc_attr( $g->slug ); ?>" <?php selected( $filter_genre, $g->slug ); ?>>
								<?php echo esc_html( $g->name ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<!-- Type -->
			<div class="filter-bar__field">
				<label for="filter-type" class="screen-reader-text"><?php esc_html_e( 'Type', 'starter-theme' ); ?></label>
				<select id="filter-type" name="type" class="filter-bar__select">
					<option value=""><?php esc_html_e( 'All Types', 'starter-theme' ); ?></option>
					<option value="manga" <?php selected( $filter_type, 'manga' ); ?>><?php esc_html_e( 'Manga', 'starter-theme' ); ?></option>
					<option value="manhwa" <?php selected( $filter_type, 'manhwa' ); ?>><?php esc_html_e( 'Manhwa', 'starter-theme' ); ?></option>
					<option value="manhua" <?php selected( $filter_type, 'manhua' ); ?>><?php esc_html_e( 'Manhua', 'starter-theme' ); ?></option>
					<option value="novel" <?php selected( $filter_type, 'novel' ); ?>><?php esc_html_e( 'Novel', 'starter-theme' ); ?></option>
					<option value="video" <?php selected( $filter_type, 'video' ); ?>><?php esc_html_e( 'Video', 'starter-theme' ); ?></option>
				</select>
			</div>

			<!-- Status -->
			<div class="filter-bar__field">
				<label for="filter-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'starter-theme' ); ?></label>
				<select id="filter-status" name="status" class="filter-bar__select">
					<option value=""><?php esc_html_e( 'All Statuses', 'starter-theme' ); ?></option>
					<option value="ongoing" <?php selected( $filter_status, 'ongoing' ); ?>><?php esc_html_e( 'Ongoing', 'starter-theme' ); ?></option>
					<option value="completed" <?php selected( $filter_status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'starter-theme' ); ?></option>
					<option value="hiatus" <?php selected( $filter_status, 'hiatus' ); ?>><?php esc_html_e( 'Hiatus', 'starter-theme' ); ?></option>
					<option value="cancelled" <?php selected( $filter_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'starter-theme' ); ?></option>
				</select>
			</div>

			<!-- Sort -->
			<div class="filter-bar__field">
				<label for="filter-sort" class="screen-reader-text"><?php esc_html_e( 'Sort order', 'starter-theme' ); ?></label>
				<select id="filter-sort" name="sort" class="filter-bar__select">
					<option value="latest" <?php selected( $filter_sort, 'latest' ); ?>><?php esc_html_e( 'Latest', 'starter-theme' ); ?></option>
					<option value="popular" <?php selected( $filter_sort, 'popular' ); ?>><?php esc_html_e( 'Popular', 'starter-theme' ); ?></option>
					<option value="a-z" <?php selected( $filter_sort, 'a-z' ); ?>><?php esc_html_e( 'A &ndash; Z', 'starter-theme' ); ?></option>
					<option value="rating" <?php selected( $filter_sort, 'rating' ); ?>><?php esc_html_e( 'Rating', 'starter-theme' ); ?></option>
				</select>
			</div>

			<button type="submit" class="btn btn--primary filter-bar__submit">
				<?php esc_html_e( 'Apply', 'starter-theme' ); ?>
			</button>

		</form>
	</div><!-- .archive-manga__filters -->

	<!-- ── Content + Sidebar ───────────────────────────────── -->
	<div class="archive-manga__layout">

		<div class="archive-manga__content">

			<?php if ( have_posts() ) : ?>

				<div class="manga-grid manga-grid--<?php echo esc_attr( $grid_cols ); ?> manga-grid--<?php echo esc_attr( $thumb_layout ); ?>" data-manga-grid>
					<?php
					$card_index = 0;
					while ( have_posts() ) :
						the_post();
						$card_index++;

						get_template_part( 'templates/parts/manga-card' );

						/* Ad slot between cards every N items. */
						$ad_interval = apply_filters( 'starter_archive_ad_interval', 8 );
						if ( 0 === $card_index % $ad_interval ) {
							get_template_part( 'templates/parts/ad-slot', null, array(
								'location' => 'between_cards',
								'class'    => 'ad-slot--inline',
							) );
						}
					endwhile;
					?>
				</div>

				<!-- AJAX Load More -->
				<?php
				$max_pages = $GLOBALS['wp_query']->max_num_pages;
				$paged     = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
				?>
				<?php if ( $paged < $max_pages ) : ?>
					<div class="archive-manga__load-more">
						<button class="btn btn--outline btn--load-more" data-load-more data-page="<?php echo esc_attr( $paged ); ?>" data-max-pages="<?php echo esc_attr( $max_pages ); ?>" aria-label="<?php esc_attr_e( 'Load more manga', 'starter-theme' ); ?>">
							<?php esc_html_e( 'Load More', 'starter-theme' ); ?>
						</button>
						<span class="archive-manga__loader" aria-hidden="true" hidden></span>
					</div>
				<?php endif; ?>

			<?php else : ?>

				<div class="archive-manga__empty">
					<p><?php esc_html_e( 'No manga found matching your criteria.', 'starter-theme' ); ?></p>
				</div>

			<?php endif; ?>

		</div><!-- .archive-manga__content -->

		<!-- Sidebar -->
		<aside class="archive-manga__sidebar" role="complementary" aria-label="<?php esc_attr_e( 'Sidebar', 'starter-theme' ); ?>">
			<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'sidebar_archive' ) ); ?>
			<?php dynamic_sidebar( 'sidebar-1' ); ?>
		</aside>

	</div><!-- .archive-manga__layout -->

</main><!-- #primary -->

<?php
get_footer();
