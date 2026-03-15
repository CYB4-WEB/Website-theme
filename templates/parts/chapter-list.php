<?php
/**
 * Template part: Chapter List
 *
 * Paginated, reversible chapter list with volume grouping,
 * AJAX lazy-load, and search.
 *
 * @package starter-theme
 * @since   1.0.0
 *
 * @param array $args {
 *     @type int    $manga_id       The parent manga/novel/video ID.
 *     @type bool   $show_thumbnails Whether to display chapter thumbnails.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$manga_id        = isset( $args['manga_id'] ) ? absint( $args['manga_id'] ) : get_the_ID();
$show_thumbnails = ! empty( $args['show_thumbnails'] );
$chapters        = function_exists( 'starter_get_chapters' ) ? starter_get_chapters( $manga_id, array( 'page' => 1 ) ) : array();
$volumes         = function_exists( 'starter_get_volumes' ) ? starter_get_volumes( $manga_id ) : array();
$total_chapters  = function_exists( 'starter_get_chapter_count' ) ? starter_get_chapter_count( $manga_id ) : 0;
$per_page        = apply_filters( 'starter_chapters_per_page', 50 );
$total_pages     = $total_chapters > 0 ? ceil( $total_chapters / $per_page ) : 1;
?>
<div class="chapter-list" data-manga-id="<?php echo esc_attr( $manga_id ); ?>" data-total-pages="<?php echo esc_attr( $total_pages ); ?>" data-current-page="1">

	<!-- Chapter list header / controls -->
	<div class="chapter-list__header">
		<h3 class="chapter-list__title">
			<?php
			printf(
				/* translators: %d: total chapter count */
				esc_html__( 'Chapters (%d)', 'starter-theme' ),
				absint( $total_chapters )
			);
			?>
		</h3>

		<div class="chapter-list__controls">
			<!-- Search chapters -->
			<div class="chapter-list__search">
				<label class="screen-reader-text" for="chapter-search-<?php echo esc_attr( $manga_id ); ?>">
					<?php esc_html_e( 'Search chapters', 'starter-theme' ); ?>
				</label>
				<input
					type="search"
					id="chapter-search-<?php echo esc_attr( $manga_id ); ?>"
					class="chapter-list__search-input"
					placeholder="<?php esc_attr_e( 'Search chapters...', 'starter-theme' ); ?>"
					data-chapter-search
				>
			</div>

			<!-- Reverse toggle -->
			<button class="chapter-list__reverse-btn" data-chapter-reverse aria-label="<?php esc_attr_e( 'Reverse chapter order', 'starter-theme' ); ?>" title="<?php esc_attr_e( 'Reverse order', 'starter-theme' ); ?>">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="7 3 7 21"/><polyline points="3 7 7 3 11 7"/><polyline points="17 21 17 3"/><polyline points="13 17 17 21 21 17"/></svg>
			</button>
		</div>
	</div>

	<!-- Volume-grouped or flat list -->
	<div class="chapter-list__body" role="list" aria-label="<?php esc_attr_e( 'Chapter list', 'starter-theme' ); ?>">

		<?php if ( ! empty( $volumes ) ) : ?>
			<?php foreach ( $volumes as $volume ) : ?>
				<div class="chapter-list__volume" data-volume="<?php echo esc_attr( $volume['number'] ); ?>">
					<button class="chapter-list__volume-toggle" aria-expanded="true" data-volume-toggle>
						<span class="chapter-list__volume-title">
							<?php
							printf(
								/* translators: %s: volume number or title */
								esc_html__( 'Volume %s', 'starter-theme' ),
								esc_html( $volume['number'] )
							);
							if ( ! empty( $volume['title'] ) ) {
								echo ' &mdash; ' . esc_html( $volume['title'] );
							}
							?>
						</span>
						<svg class="chapter-list__volume-chevron" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
					</button>

					<div class="chapter-list__volume-chapters">
						<?php if ( ! empty( $volume['chapters'] ) ) : ?>
							<?php foreach ( $volume['chapters'] as $chapter ) : ?>
								<?php starter_render_chapter_row( $chapter, $show_thumbnails ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

		<?php else : ?>
			<!-- Flat chapter list -->
			<?php if ( ! empty( $chapters ) ) : ?>
				<?php foreach ( $chapters as $chapter ) : ?>
					<?php starter_render_chapter_row( $chapter, $show_thumbnails ); ?>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="chapter-list__empty">
					<?php esc_html_e( 'No chapters available yet.', 'starter-theme' ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

	</div><!-- .chapter-list__body -->

	<!-- AJAX Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
		<div class="chapter-list__pagination">
			<button class="chapter-list__load-more btn btn--outline" data-chapter-load-more aria-label="<?php esc_attr_e( 'Load more chapters', 'starter-theme' ); ?>">
				<?php esc_html_e( 'Load More Chapters', 'starter-theme' ); ?>
			</button>
			<span class="chapter-list__page-info" aria-live="polite">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'starter-theme' ),
					1,
					absint( $total_pages )
				);
				?>
			</span>
		</div>
	<?php endif; ?>

</div><!-- .chapter-list -->

<?php
/**
 * Renders a single chapter row.
 *
 * @param array $chapter       Chapter data array.
 * @param bool  $show_thumbnail Whether to show thumbnail.
 */
if ( ! function_exists( 'starter_render_chapter_row' ) ) :
	function starter_render_chapter_row( $chapter, $show_thumbnail = false ) {
		$ch_url        = isset( $chapter['url'] ) ? $chapter['url'] : '#';
		$ch_number     = isset( $chapter['number'] ) ? $chapter['number'] : '';
		$ch_title      = isset( $chapter['title'] ) ? $chapter['title'] : '';
		$ch_extend     = isset( $chapter['extend_name'] ) ? $chapter['extend_name'] : '';
		$ch_date       = isset( $chapter['date'] ) ? $chapter['date'] : '';
		$ch_views      = isset( $chapter['views'] ) ? $chapter['views'] : 0;
		$ch_premium    = ! empty( $chapter['premium'] );
		$ch_restricted = ! empty( $chapter['restricted'] );
		$ch_scheduled  = ! empty( $chapter['scheduled'] );
		$ch_thumb      = isset( $chapter['thumbnail'] ) ? $chapter['thumbnail'] : '';
		?>
		<div class="chapter-row <?php echo $ch_scheduled ? 'chapter-row--scheduled' : ''; ?>" role="listitem" data-chapter="<?php echo esc_attr( $ch_number ); ?>">
			<a href="<?php echo esc_url( $ch_url ); ?>" class="chapter-row__link" <?php echo $ch_restricted ? 'data-restricted="true"' : ''; ?>>

				<?php if ( $show_thumbnail && $ch_thumb ) : ?>
					<img class="chapter-row__thumb lazyload" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="<?php echo esc_url( $ch_thumb ); ?>" alt="" width="50" height="50" loading="lazy">
				<?php endif; ?>

				<span class="chapter-row__number">
					<?php
					printf(
						/* translators: %s: chapter number */
						esc_html__( 'Chapter %s', 'starter-theme' ),
						esc_html( $ch_number )
					);
					?>
				</span>

				<?php if ( $ch_title ) : ?>
					<span class="chapter-row__title"><?php echo esc_html( $ch_title ); ?></span>
				<?php endif; ?>

				<?php if ( $ch_extend ) : ?>
					<span class="chapter-row__extend"><?php echo esc_html( $ch_extend ); ?></span>
				<?php endif; ?>

				<?php if ( $ch_scheduled ) : ?>
					<span class="chapter-row__tag chapter-row__tag--scheduled"><?php esc_html_e( 'Scheduled', 'starter-theme' ); ?></span>
				<?php endif; ?>

				<span class="chapter-row__meta">
					<?php if ( $ch_date ) : ?>
						<time class="chapter-row__date" datetime="<?php echo esc_attr( $ch_date ); ?>">
							<?php echo esc_html( human_time_diff( strtotime( $ch_date ), current_time( 'timestamp' ) ) ); ?>
						</time>
					<?php endif; ?>

					<?php if ( $ch_views ) : ?>
						<span class="chapter-row__views" aria-label="<?php printf( esc_attr__( '%s views', 'starter-theme' ), number_format_i18n( $ch_views ) ); ?>">
							<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
							<?php echo esc_html( number_format_i18n( $ch_views ) ); ?>
						</span>
					<?php endif; ?>
				</span>

				<!-- Icons -->
				<span class="chapter-row__icons">
					<?php if ( $ch_premium ) : ?>
						<span class="chapter-row__icon chapter-row__icon--coin" title="<?php esc_attr_e( 'Premium chapter', 'starter-theme' ); ?>">
							<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" font-size="12" fill="#fff">C</text></svg>
						</span>
					<?php endif; ?>

					<?php if ( $ch_restricted ) : ?>
						<span class="chapter-row__icon chapter-row__icon--lock" title="<?php esc_attr_e( 'Restricted', 'starter-theme' ); ?>">
							<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
						</span>
					<?php endif; ?>
				</span>

			</a>
		</div>
		<?php
	}
endif;
?>
