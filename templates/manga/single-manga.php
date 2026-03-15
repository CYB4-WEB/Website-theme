<?php
/**
 * Template: Single Manga Detail Page
 *
 * Displays full manga information, chapter list, comments, and related manga.
 *
 * Template Name: Single Manga
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$manga_id      = get_the_ID();
$manga_title   = get_the_title();
$cover_url     = get_the_post_thumbnail_url( $manga_id, 'starter-cover' );
$alt_names     = get_post_meta( $manga_id, '_alternative_names', true );
$author        = get_post_meta( $manga_id, '_author', true );
$artist        = get_post_meta( $manga_id, '_artist', true );
$content_type  = get_post_meta( $manga_id, '_content_type', true );
$status        = get_post_meta( $manga_id, '_status', true );
$release_year  = get_post_meta( $manga_id, '_release_year', true );
$views         = get_post_meta( $manga_id, '_views', true );
$rating        = function_exists( 'starter_get_manga_rating' ) ? starter_get_manga_rating( $manga_id ) : 0;
$rating_count  = function_exists( 'starter_get_rating_count' ) ? starter_get_rating_count( $manga_id ) : 0;
$badge         = function_exists( 'starter_get_badge' ) ? starter_get_badge( $manga_id ) : '';
$is_adult      = get_post_meta( $manga_id, '_adult_content', true );
$is_bookmarked = function_exists( 'starter_is_bookmarked' ) ? starter_is_bookmarked( $manga_id ) : false;
$can_download  = function_exists( 'starter_can_download' ) ? starter_can_download( $manga_id ) : false;
$first_chapter = function_exists( 'starter_get_first_chapter_url' ) ? starter_get_first_chapter_url( $manga_id ) : '';
$continue_url  = function_exists( 'starter_get_continue_reading_url' ) ? starter_get_continue_reading_url( $manga_id ) : '';
$genres        = get_the_terms( $manga_id, 'genre' );
$tags          = get_the_terms( $manga_id, 'manga_tag' );
$description   = get_the_content();

get_header();
?>

<?php
/* ── Adult Content Gate ─────────────────────────────────────── */
if ( $is_adult ) :
?>
<div class="adult-gate" id="adult-gate" data-adult-gate>
	<div class="adult-gate__inner">
		<h2><?php esc_html_e( 'Adult Content Warning', 'starter-theme' ); ?></h2>
		<p><?php esc_html_e( 'This content is intended for mature audiences only. You must be 18 years or older to view this page.', 'starter-theme' ); ?></p>
		<div class="adult-gate__actions">
			<button class="btn btn--primary" data-adult-confirm><?php esc_html_e( 'I am 18 or older', 'starter-theme' ); ?></button>
			<a class="btn btn--outline" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go Back', 'starter-theme' ); ?></a>
		</div>
	</div>
</div>
<?php endif; ?>

<?php
/* ── Schema.org JSON-LD ─────────────────────────────────────── */
$schema = array(
	'@context'      => 'https://schema.org',
	'@type'         => 'CreativeWork',
	'name'          => $manga_title,
	'url'           => get_permalink(),
	'image'         => $cover_url ? $cover_url : '',
	'description'   => wp_strip_all_tags( $description ),
	'author'        => array( '@type' => 'Person', 'name' => $author ? $author : '' ),
	'genre'         => ! empty( $genres ) && ! is_wp_error( $genres ) ? wp_list_pluck( $genres, 'name' ) : array(),
	'datePublished' => get_the_date( 'c' ),
);
if ( $rating > 0 ) {
	$schema['aggregateRating'] = array(
		'@type'       => 'AggregateRating',
		'ratingValue' => $rating,
		'bestRating'  => 5,
		'ratingCount' => $rating_count,
	);
}
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<main id="primary" class="site-main single-manga" role="main">

	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'before_content' ) ); ?>

	<!-- ── Manga Detail Grid ──────────────────────────────── -->
	<article id="manga-<?php echo esc_attr( $manga_id ); ?>" <?php post_class( 'manga-detail' ); ?>>

		<div class="manga-detail__grid">

			<!-- Cover column -->
			<div class="manga-detail__cover-col">
				<?php if ( $cover_url ) : ?>
					<img class="manga-detail__cover" src="<?php echo esc_url( $cover_url ); ?>" alt="<?php echo esc_attr( $manga_title ); ?>" width="300" height="450">
				<?php endif; ?>

				<?php if ( $badge ) : ?>
					<span class="manga-detail__badge manga-detail__badge--<?php echo esc_attr( sanitize_html_class( $badge ) ); ?>">
						<?php echo esc_html( $badge ); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- Info column -->
			<div class="manga-detail__info-col">

				<h1 class="manga-detail__title"><?php echo esc_html( $manga_title ); ?></h1>

				<?php if ( $alt_names ) : ?>
					<p class="manga-detail__alt-names">
						<strong><?php esc_html_e( 'Alternative Names:', 'starter-theme' ); ?></strong>
						<?php echo esc_html( $alt_names ); ?>
					</p>
				<?php endif; ?>

				<dl class="manga-detail__meta">
					<?php if ( $author ) : ?>
						<dt><?php esc_html_e( 'Author', 'starter-theme' ); ?></dt>
						<dd><?php echo esc_html( $author ); ?></dd>
					<?php endif; ?>

					<?php if ( $artist ) : ?>
						<dt><?php esc_html_e( 'Artist', 'starter-theme' ); ?></dt>
						<dd><?php echo esc_html( $artist ); ?></dd>
					<?php endif; ?>

					<?php if ( ! empty( $genres ) && ! is_wp_error( $genres ) ) : ?>
						<dt><?php esc_html_e( 'Genres', 'starter-theme' ); ?></dt>
						<dd class="manga-detail__genres">
							<?php foreach ( $genres as $genre ) : ?>
								<a href="<?php echo esc_url( get_term_link( $genre ) ); ?>" class="manga-detail__genre-link" rel="tag">
									<?php echo esc_html( $genre->name ); ?>
								</a>
							<?php endforeach; ?>
						</dd>
					<?php endif; ?>

					<?php if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) : ?>
						<dt><?php esc_html_e( 'Tags', 'starter-theme' ); ?></dt>
						<dd class="manga-detail__tags">
							<?php foreach ( $tags as $tag ) : ?>
								<a href="<?php echo esc_url( get_term_link( $tag ) ); ?>" class="manga-detail__tag-link" rel="tag">
									<?php echo esc_html( $tag->name ); ?>
								</a>
							<?php endforeach; ?>
						</dd>
					<?php endif; ?>

					<?php if ( $content_type ) : ?>
						<dt><?php esc_html_e( 'Type', 'starter-theme' ); ?></dt>
						<dd><?php echo esc_html( ucfirst( $content_type ) ); ?></dd>
					<?php endif; ?>

					<?php if ( $status ) : ?>
						<dt><?php esc_html_e( 'Status', 'starter-theme' ); ?></dt>
						<dd><span class="manga-detail__status manga-detail__status--<?php echo esc_attr( sanitize_html_class( $status ) ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></dd>
					<?php endif; ?>

					<?php if ( $release_year ) : ?>
						<dt><?php esc_html_e( 'Release Year', 'starter-theme' ); ?></dt>
						<dd><?php echo esc_html( $release_year ); ?></dd>
					<?php endif; ?>

					<?php if ( $views ) : ?>
						<dt><?php esc_html_e( 'Views', 'starter-theme' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $views ) ); ?></dd>
					<?php endif; ?>
				</dl>

				<!-- Rating -->
				<?php if ( $rating > 0 ) : ?>
					<div class="manga-detail__rating" aria-label="<?php printf( esc_attr__( 'Rating: %1$s out of 5 from %2$s votes', 'starter-theme' ), esc_attr( $rating ), esc_attr( number_format_i18n( $rating_count ) ) ); ?>">
						<div class="manga-detail__stars">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<svg class="star <?php echo $i <= round( $rating ) ? 'star--filled' : ''; ?>" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="<?php echo $i <= round( $rating ) ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2">
									<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
								</svg>
							<?php endfor; ?>
						</div>
						<span class="manga-detail__rating-value"><?php echo esc_html( $rating ); ?></span>
						<span class="manga-detail__rating-count">
							<?php
							printf(
								/* translators: %s: number of ratings */
								esc_html__( '(%s votes)', 'starter-theme' ),
								esc_html( number_format_i18n( $rating_count ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'after_info' ) ); ?>

			</div><!-- .manga-detail__info-col -->

		</div><!-- .manga-detail__grid -->

		<!-- ── Synopsis ────────────────────────────────────── -->
		<section class="manga-detail__synopsis" aria-label="<?php esc_attr_e( 'Synopsis', 'starter-theme' ); ?>">
			<h2 class="manga-detail__section-title"><?php esc_html_e( 'Synopsis', 'starter-theme' ); ?></h2>
			<div class="manga-detail__description expandable" data-expandable data-max-height="200">
				<?php echo wp_kses_post( $description ); ?>
			</div>
			<button class="manga-detail__expand-btn" data-expand-toggle hidden>
				<span class="expand-more"><?php esc_html_e( 'Show More', 'starter-theme' ); ?></span>
				<span class="expand-less"><?php esc_html_e( 'Show Less', 'starter-theme' ); ?></span>
			</button>
		</section>

		<!-- ── Action Buttons ──────────────────────────────── -->
		<div class="manga-detail__actions">
			<button class="btn btn--bookmark <?php echo $is_bookmarked ? 'btn--bookmarked' : ''; ?>" data-bookmark="<?php echo esc_attr( $manga_id ); ?>" aria-pressed="<?php echo $is_bookmarked ? 'true' : 'false'; ?>">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="<?php echo $is_bookmarked ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
				<span><?php echo $is_bookmarked ? esc_html__( 'Bookmarked', 'starter-theme' ) : esc_html__( 'Bookmark', 'starter-theme' ); ?></span>
			</button>

			<button class="btn btn--share" data-share-toggle aria-label="<?php esc_attr_e( 'Share this manga', 'starter-theme' ); ?>">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
				<span><?php esc_html_e( 'Share', 'starter-theme' ); ?></span>
			</button>

			<?php if ( $first_chapter ) : ?>
				<a href="<?php echo esc_url( $first_chapter ); ?>" class="btn btn--primary btn--first-chapter">
					<?php esc_html_e( 'Read First Chapter', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $continue_url ) : ?>
				<a href="<?php echo esc_url( $continue_url ); ?>" class="btn btn--accent btn--continue">
					<?php esc_html_e( 'Continue Reading', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $can_download ) : ?>
				<button class="btn btn--outline btn--download" data-download="<?php echo esc_attr( $manga_id ); ?>">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					<span><?php esc_html_e( 'Download', 'starter-theme' ); ?></span>
				</button>
			<?php endif; ?>
		</div>

		<!-- ── Social Share Buttons ────────────────────────── -->
		<div class="manga-detail__share-panel" id="share-panel" data-share-panel hidden>
			<?php
			$share_url   = urlencode( get_permalink() );
			$share_title = urlencode( $manga_title );
			?>
			<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr( $share_url ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-btn--facebook" aria-label="<?php esc_attr_e( 'Share on Facebook', 'starter-theme' ); ?>">Facebook</a>
			<a href="https://twitter.com/intent/tweet?url=<?php echo esc_attr( $share_url ); ?>&text=<?php echo esc_attr( $share_title ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-btn--twitter" aria-label="<?php esc_attr_e( 'Share on Twitter', 'starter-theme' ); ?>">Twitter</a>
			<a href="https://pinterest.com/pin/create/button/?url=<?php echo esc_attr( $share_url ); ?>&description=<?php echo esc_attr( $share_title ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-btn--pinterest" aria-label="<?php esc_attr_e( 'Share on Pinterest', 'starter-theme' ); ?>">Pinterest</a>
			<a href="https://t.me/share/url?url=<?php echo esc_attr( $share_url ); ?>&text=<?php echo esc_attr( $share_title ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-btn--telegram" aria-label="<?php esc_attr_e( 'Share on Telegram', 'starter-theme' ); ?>">Telegram</a>
			<button class="share-btn share-btn--copy" data-copy-url="<?php echo esc_attr( get_permalink() ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'starter-theme' ); ?>">
				<?php esc_html_e( 'Copy Link', 'starter-theme' ); ?>
			</button>
		</div>

		<!-- ── Chapter List ────────────────────────────────── -->
		<section class="manga-detail__chapters" aria-label="<?php esc_attr_e( 'Chapter list', 'starter-theme' ); ?>">
			<?php get_template_part( 'templates/parts/chapter-list', null, array( 'manga_id' => $manga_id ) ); ?>
		</section>

		<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'after_chapter_list' ) ); ?>

		<!-- ── Comments ────────────────────────────────────── -->
		<section class="manga-detail__comments" aria-label="<?php esc_attr_e( 'Comments', 'starter-theme' ); ?>">
			<?php
			/**
			 * Hook: starter_manga_comments
			 *
			 * Allow plugins such as wpDiscuz to override comments.
			 *
			 * @param int $manga_id Current manga ID.
			 */
			if ( has_action( 'starter_manga_comments' ) ) {
				do_action( 'starter_manga_comments', $manga_id );
			} elseif ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</section>

		<!-- ── Related Manga ───────────────────────────────── -->
		<?php
		$related_args = array(
			'post_type'      => get_post_type(),
			'posts_per_page' => 6,
			'post__not_in'   => array( $manga_id ),
			'post_status'    => 'publish',
		);
		if ( ! empty( $genres ) && ! is_wp_error( $genres ) ) {
			$related_args['tax_query'] = array(
				array(
					'taxonomy' => 'genre',
					'field'    => 'term_id',
					'terms'    => wp_list_pluck( $genres, 'term_id' ),
				),
			);
		}
		$related_query = new WP_Query( $related_args );
		?>
		<?php if ( $related_query->have_posts() ) : ?>
			<section class="manga-detail__related" aria-label="<?php esc_attr_e( 'Related manga', 'starter-theme' ); ?>">
				<h2 class="manga-detail__section-title"><?php esc_html_e( 'You May Also Like', 'starter-theme' ); ?></h2>
				<div class="manga-grid manga-grid--4">
					<?php
					while ( $related_query->have_posts() ) :
						$related_query->the_post();
						get_template_part( 'templates/parts/manga-card' );
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</section>
		<?php endif; ?>

	</article>

	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'sidebar_manga' ) ); ?>

</main><!-- #primary -->

<?php
get_footer();
