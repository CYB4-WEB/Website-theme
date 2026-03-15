<?php
/**
 * Template: Video Chapter Reader
 *
 * Displays video chapters with support for Pixeldrain and Google Drive
 * iframe embeds, theater mode, episode navigation, and related episodes.
 *
 * Expected query vars (set by Starter_Manga_Reader):
 *   starter_current_chapter  — chapter row object
 *   starter_current_page     — int (unused for video, kept for API parity)
 *   starter_manga_id         — int, parent manga/video post ID
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Data bootstrap ─────────────────────────────────────────── */
$chapter  = get_query_var( 'starter_current_chapter' );
$manga_id = get_query_var( 'starter_manga_id', 0 );

if ( ! $chapter || ! $manga_id ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$manga_title = get_the_title( $manga_id );
$manga_url   = get_permalink( $manga_id );

$chapter_mgr = Starter_Manga_Chapter::get_instance();
$prev_ch     = $chapter_mgr->get_prev_chapter( $chapter->id, $manga_id );
$next_ch     = $chapter_mgr->get_next_chapter( $chapter->id, $manga_id );
$all_chs     = $chapter_mgr->get_chapters_by_manga( $manga_id, array( 'order' => 'ASC' ) );

$prev_url = $prev_ch ? esc_url( $chapter_mgr->get_chapter_url( $manga_id, $prev_ch ) ) : '';
$next_url = $next_ch ? esc_url( $chapter_mgr->get_chapter_url( $manga_id, $next_ch ) ) : '';

/* Video URL(s). */
$video_data = json_decode( $chapter->chapter_data, true );
$video_urls = array();
if ( is_array( $video_data ) ) {
	$video_urls = array_map( 'esc_url', $video_data );
}
$primary_url = ! empty( $video_urls ) ? $video_urls[0] : '';

/* Detect source type via the embed class. */
$embed_html = '';
if ( $primary_url && class_exists( 'Starter_Video_Embed' ) ) {
	$video_embed = Starter_Video_Embed::get_instance();
	$embed_html  = $video_embed->generate_embed( $primary_url, array( 'lazy' => false ) );
}

/* Chapter label. */
$chapter_label = sprintf(
	/* translators: %s: chapter/episode number */
	__( 'Episode %s', 'starter-theme' ),
	$chapter->chapter_number
);
if ( ! empty( $chapter->chapter_name ) ) {
	$chapter_label .= ' — ' . $chapter->chapter_name;
}

/* Related episodes (other chapters of same manga). */
$related_episodes = array_filter( $all_chs, function ( $ch ) use ( $chapter ) {
	return (int) $ch->id !== (int) $chapter->id;
} );

/* Schema.org JSON-LD. */
$schema = array(
	'@context'    => 'https://schema.org',
	'@type'       => 'VideoObject',
	'name'        => $chapter_label,
	'url'         => $chapter_mgr->get_chapter_url( $manga_id, $chapter ),
	'embedUrl'    => $primary_url,
	'isPartOf'    => array(
		'@type' => 'CreativeWork',
		'name'  => $manga_title,
		'url'   => $manga_url,
	),
	'position'    => $chapter->chapter_number,
);

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<main id="primary" class="video-reader-page" role="main" data-manga-id="<?php echo esc_attr( $manga_id ); ?>" data-chapter-id="<?php echo esc_attr( $chapter->id ); ?>">

	<!-- ── Compact Header ───────────────────────────────────── -->
	<header class="reader-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Video player controls', 'starter-theme' ); ?>">
		<div class="reader-toolbar__inner">

			<a href="<?php echo esc_url( $manga_url ); ?>" class="reader-toolbar__title" title="<?php echo esc_attr( $manga_title ); ?>">
				<?php echo esc_html( $manga_title ); ?>
			</a>

			<!-- Chapter / Episode selector -->
			<div class="reader-toolbar__nav">
				<?php if ( $prev_url ) : ?>
					<a href="<?php echo $prev_url; ?>" class="reader-toolbar__btn reader-toolbar__btn--prev" aria-label="<?php esc_attr_e( 'Previous episode', 'starter-theme' ); ?>">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</a>
				<?php else : ?>
					<span class="reader-toolbar__btn reader-toolbar__btn--prev reader-toolbar__btn--disabled" aria-disabled="true">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</span>
				<?php endif; ?>

				<label for="video-chapter-select" class="screen-reader-text"><?php esc_html_e( 'Select episode', 'starter-theme' ); ?></label>
				<select id="video-chapter-select" class="reader-toolbar__select" data-chapter-select>
					<?php foreach ( $all_chs as $ch ) :
						$ep_label = sprintf(
							esc_html__( 'Episode %s', 'starter-theme' ),
							$ch->chapter_number
						);
						if ( ! empty( $ch->chapter_name ) ) {
							$ep_label .= ' - ' . $ch->chapter_name;
						}
						?>
						<option value="<?php echo esc_attr( $chapter_mgr->get_chapter_url( $manga_id, $ch ) ); ?>" <?php selected( (int) $ch->id, (int) $chapter->id ); ?>>
							<?php echo esc_html( $ep_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<?php if ( $next_url ) : ?>
					<a href="<?php echo $next_url; ?>" class="reader-toolbar__btn reader-toolbar__btn--next" aria-label="<?php esc_attr_e( 'Next episode', 'starter-theme' ); ?>">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
					</a>
				<?php else : ?>
					<span class="reader-toolbar__btn reader-toolbar__btn--next reader-toolbar__btn--disabled" aria-disabled="true">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
					</span>
				<?php endif; ?>
			</div>

			<!-- Theater mode toggle -->
			<div class="reader-toolbar__actions">
				<button class="reader-toolbar__btn" data-theater-toggle aria-label="<?php esc_attr_e( 'Toggle theater mode', 'starter-theme' ); ?>" aria-pressed="false">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/></svg>
					<span class="reader-toolbar__label"><?php esc_html_e( 'Theater', 'starter-theme' ); ?></span>
				</button>
			</div>

		</div>
	</header>

	<!-- ── Video Player Container ───────────────────────────── -->
	<section class="video-player" id="video-player" aria-label="<?php esc_attr_e( 'Video player', 'starter-theme' ); ?>">
		<div class="video-player__wrapper">
			<div class="video-player__ratio video-player__ratio--16-9">
				<?php if ( $embed_html ) : ?>
					<?php
					// The embed helper returns sanitized HTML with iframes.
					echo $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by Starter_Video_Embed.
					?>
				<?php elseif ( $primary_url ) : ?>
					<!-- Fallback: direct iframe for known sources -->
					<iframe
						class="video-player__iframe"
						src="<?php echo esc_url( $primary_url ); ?>"
						allowfullscreen
						allow="autoplay; encrypted-media; fullscreen"
						sandbox="allow-scripts allow-same-origin allow-popups"
						title="<?php echo esc_attr( $chapter_label ); ?>"
						loading="lazy"
						width="100%"
						height="100%"
					></iframe>
				<?php else : ?>
					<div class="video-player__empty">
						<p><?php esc_html_e( 'No video available for this episode.', 'starter-theme' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( count( $video_urls ) > 1 ) : ?>
			<div class="video-player__sources" aria-label="<?php esc_attr_e( 'Alternative video sources', 'starter-theme' ); ?>">
				<span class="video-player__sources-label"><?php esc_html_e( 'Sources:', 'starter-theme' ); ?></span>
				<?php foreach ( $video_urls as $idx => $v_url ) : ?>
					<button class="video-player__source-btn <?php echo 0 === $idx ? 'video-player__source-btn--active' : ''; ?>" data-video-source="<?php echo esc_url( $v_url ); ?>" aria-label="<?php printf( esc_attr__( 'Source %d', 'starter-theme' ), intval( $idx + 1 ) ); ?>">
						<?php
						printf(
							/* translators: %d: source number */
							esc_html__( 'Source %d', 'starter-theme' ),
							intval( $idx + 1 )
						);
						?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- ── Episode Info ─────────────────────────────────────── -->
	<section class="video-info" aria-label="<?php esc_attr_e( 'Episode information', 'starter-theme' ); ?>">
		<h1 class="video-info__title"><?php echo esc_html( $chapter_label ); ?></h1>
		<p class="video-info__manga">
			<?php
			printf(
				/* translators: %s: manga/series title (linked) */
				esc_html__( 'Series: %s', 'starter-theme' ),
				'<a href="' . esc_url( $manga_url ) . '">' . esc_html( $manga_title ) . '</a>'
			);
			?>
		</p>

		<?php if ( ! empty( $chapter->chapter_warning ) ) : ?>
			<div class="video-info__warning" role="alert">
				<?php echo wp_kses_post( $chapter->chapter_warning ); ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- ── Chapter Navigation ───────────────────────────────── -->
	<nav class="video-chapter-nav" aria-label="<?php esc_attr_e( 'Episode navigation', 'starter-theme' ); ?>">
		<?php if ( $prev_url ) : ?>
			<a href="<?php echo $prev_url; ?>" class="video-chapter-nav__btn video-chapter-nav__btn--prev">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				<?php esc_html_e( 'Previous Episode', 'starter-theme' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $next_url ) : ?>
			<a href="<?php echo $next_url; ?>" class="video-chapter-nav__btn video-chapter-nav__btn--next">
				<?php esc_html_e( 'Next Episode', 'starter-theme' ); ?>
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</a>
		<?php endif; ?>
	</nav>

	<!-- ── Comments ─────────────────────────────────────────── -->
	<section class="video-comments" aria-label="<?php esc_attr_e( 'Comments', 'starter-theme' ); ?>">
		<?php
		if ( has_action( 'starter_manga_comments' ) ) {
			do_action( 'starter_manga_comments', $manga_id );
		} elseif ( comments_open( $manga_id ) || get_comments_number( $manga_id ) ) {
			comments_template();
		}
		?>
	</section>

	<!-- ── Related Episodes Grid ────────────────────────────── -->
	<?php if ( ! empty( $related_episodes ) ) : ?>
		<section class="video-related" aria-label="<?php esc_attr_e( 'Related episodes', 'starter-theme' ); ?>">
			<h2 class="video-related__title"><?php esc_html_e( 'More Episodes', 'starter-theme' ); ?></h2>
			<div class="video-related__grid">
				<?php foreach ( $related_episodes as $rel_ep ) : ?>
					<a href="<?php echo esc_url( $chapter_mgr->get_chapter_url( $manga_id, $rel_ep ) ); ?>" class="video-related__card <?php echo (int) $rel_ep->id === (int) $chapter->id ? 'video-related__card--current' : ''; ?>">
						<div class="video-related__thumb">
							<svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
						</div>
						<div class="video-related__meta">
							<span class="video-related__ep-num">
								<?php
								printf(
									esc_html__( 'Ep. %s', 'starter-theme' ),
									esc_html( $rel_ep->chapter_number )
								);
								?>
							</span>
							<?php if ( ! empty( $rel_ep->chapter_name ) ) : ?>
								<span class="video-related__ep-name"><?php echo esc_html( $rel_ep->chapter_name ); ?></span>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

</main>

<?php
get_footer();
