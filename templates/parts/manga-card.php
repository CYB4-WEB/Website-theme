<?php
/**
 * Template part: Manga Card
 *
 * Reusable card component for manga/novel/video grid displays.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$card_id       = get_the_ID();
$card_title    = get_the_title();
$card_url      = get_permalink();
$card_type     = get_post_type();
$cover_url     = get_the_post_thumbnail_url( $card_id, 'starter-cover' );
$thumb_url     = get_the_post_thumbnail_url( $card_id, 'starter-thumb' );
$rating        = function_exists( 'starter_get_manga_rating' ) ? starter_get_manga_rating( $card_id ) : 0;
$badge         = function_exists( 'starter_get_badge' ) ? starter_get_badge( $card_id ) : '';
$latest_ch     = function_exists( 'starter_get_latest_chapter' ) ? starter_get_latest_chapter( $card_id ) : null;
$genres        = get_the_terms( $card_id, 'genre' );
$content_type  = get_post_meta( $card_id, '_content_type', true );

/* Type icon label. */
$type_labels = array(
	'manga' => __( 'Manga', 'starter-theme' ),
	'novel' => __( 'Novel', 'starter-theme' ),
	'video' => __( 'Video', 'starter-theme' ),
);
$type_label = isset( $type_labels[ $card_type ] ) ? $type_labels[ $card_type ] : $card_type;
?>
<article class="manga-card manga-card--<?php echo esc_attr( $card_type ); ?>" data-id="<?php echo esc_attr( $card_id ); ?>" aria-label="<?php echo esc_attr( $card_title ); ?>">
	<a href="<?php echo esc_url( $card_url ); ?>" class="manga-card__link" tabindex="0">

		<!-- Cover Image -->
		<div class="manga-card__cover">
			<?php if ( $cover_url ) : ?>
				<img
					class="manga-card__image lazyload"
					src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
					data-src="<?php echo esc_url( $cover_url ); ?>"
					alt="<?php echo esc_attr( $card_title ); ?>"
					width="300"
					height="450"
					loading="lazy"
				>
			<?php else : ?>
				<div class="manga-card__placeholder" aria-hidden="true">
					<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
				</div>
			<?php endif; ?>

			<?php if ( $badge ) : ?>
				<span class="manga-card__badge manga-card__badge--<?php echo esc_attr( sanitize_html_class( $badge ) ); ?>">
					<?php echo esc_html( $badge ); ?>
				</span>
			<?php endif; ?>

			<!-- Type icon -->
			<span class="manga-card__type-icon manga-card__type-icon--<?php echo esc_attr( $card_type ); ?>" aria-label="<?php echo esc_attr( $type_label ); ?>">
				<?php if ( 'novel' === $card_type ) : ?>
					<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
				<?php elseif ( 'video' === $card_type ) : ?>
					<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
				<?php else : ?>
					<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
				<?php endif; ?>
			</span>

			<!-- Hover overlay -->
			<div class="manga-card__overlay" aria-hidden="true">
				<?php if ( ! empty( $genres ) && ! is_wp_error( $genres ) ) : ?>
					<div class="manga-card__genres">
						<?php
						$genre_count = 0;
						foreach ( $genres as $genre ) :
							if ( $genre_count >= 3 ) break;
							?>
							<span class="manga-card__genre"><?php echo esc_html( $genre->name ); ?></span>
							<?php
							$genre_count++;
						endforeach;
						?>
					</div>
				<?php endif; ?>
			</div>
		</div><!-- .manga-card__cover -->

		<!-- Info -->
		<div class="manga-card__info">
			<h3 class="manga-card__title" title="<?php echo esc_attr( $card_title ); ?>">
				<?php echo esc_html( $card_title ); ?>
			</h3>

			<?php if ( $latest_ch ) : ?>
				<div class="manga-card__chapter">
					<span class="manga-card__chapter-num">
						<?php
						printf(
							/* translators: %s: chapter number */
							esc_html__( 'Ch. %s', 'starter-theme' ),
							esc_html( $latest_ch['number'] )
						);
						?>
					</span>
					<?php if ( ! empty( $latest_ch['date'] ) ) : ?>
						<time class="manga-card__chapter-date" datetime="<?php echo esc_attr( $latest_ch['date'] ); ?>">
							<?php echo esc_html( human_time_diff( strtotime( $latest_ch['date'] ), current_time( 'timestamp' ) ) ); ?>
						</time>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $rating > 0 ) : ?>
				<div class="manga-card__rating" aria-label="<?php printf( esc_attr__( 'Rating: %s out of 5', 'starter-theme' ), esc_attr( $rating ) ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<svg class="manga-card__star <?php echo $i <= round( $rating ) ? 'manga-card__star--filled' : ''; ?>" aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="<?php echo $i <= round( $rating ) ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2">
							<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
						</svg>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div><!-- .manga-card__info -->

	</a>
</article>
