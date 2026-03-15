<?php
/**
 * Template: Manga Chapter Reader
 *
 * Full-width, distraction-free chapter reading experience with single-page
 * and one-shot (long-strip) modes, theme toggling, keyboard shortcuts,
 * bookmark support, and adult content gating.
 *
 * Expected query vars (set by Starter_Manga_Reader):
 *   starter_current_chapter  — chapter row object
 *   starter_current_page     — int, current page number
 *   starter_manga_id         — int, parent manga post ID
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Data bootstrap ─────────────────────────────────────────── */
$chapter    = get_query_var( 'starter_current_chapter' );
$page_num   = get_query_var( 'starter_current_page', 1 );
$manga_id   = get_query_var( 'starter_manga_id', 0 );

if ( ! $chapter || ! $manga_id ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$manga_title  = get_the_title( $manga_id );
$manga_url    = get_permalink( $manga_id );
$is_adult     = '1' === get_post_meta( $manga_id, '_adult_content', true );
$adult_ok     = isset( $_COOKIE['starter_adult_confirmed'] ) && '1' === $_COOKIE['starter_adult_confirmed'];
$is_bookmarked = function_exists( 'starter_is_chapter_bookmarked' )
	? starter_is_chapter_bookmarked( $manga_id, $chapter->id )
	: false;

$chapter_mgr = Starter_Manga_Chapter::get_instance();
$prev_ch     = $chapter_mgr->get_prev_chapter( $chapter->id, $manga_id );
$next_ch     = $chapter_mgr->get_next_chapter( $chapter->id, $manga_id );
$all_chs     = $chapter_mgr->get_chapters_by_manga( $manga_id, array( 'order' => 'ASC' ) );

$prev_url = $prev_ch ? esc_url( $chapter_mgr->get_chapter_url( $manga_id, $prev_ch ) ) : '';
$next_url = $next_ch ? esc_url( $chapter_mgr->get_chapter_url( $manga_id, $next_ch ) ) : '';

$chapter_images = array();
$chapter_data   = json_decode( $chapter->chapter_data, true );
if ( is_array( $chapter_data ) ) {
	$chapter_images = array_map( 'esc_url', $chapter_data );
}
$total_pages = count( $chapter_images );

$protector_active = function_exists( 'starter_is_protector_active' ) && starter_is_protector_active( $manga_id );

/* ── Chapter label ──────────────────────────────────────────── */
$chapter_label = sprintf(
	/* translators: %s: chapter number */
	__( 'Chapter %s', 'starter-theme' ),
	$chapter->chapter_number
);
if ( ! empty( $chapter->chapter_name ) ) {
	$chapter_label .= ' — ' . $chapter->chapter_name;
}

/* ── Schema.org JSON-LD ─────────────────────────────────────── */
$schema = array(
	'@context'    => 'https://schema.org',
	'@type'       => 'Chapter',
	'name'        => $chapter_label,
	'url'         => $chapter_mgr->get_chapter_url( $manga_id, $chapter ),
	'isPartOf'    => array(
		'@type' => 'CreativeWork',
		'name'  => $manga_title,
		'url'   => $manga_url,
	),
	'position'    => $chapter->chapter_number,
	'datePublished' => isset( $chapter->created_at ) ? $chapter->created_at : '',
);

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<?php
/* ── Adult Content Warning Overlay ──────────────────────────── */
if ( $is_adult && ! $adult_ok ) : ?>
<div class="reader-adult-gate" id="reader-adult-gate" role="alertdialog" aria-modal="true" aria-labelledby="adult-gate-title" aria-describedby="adult-gate-desc">
	<div class="reader-adult-gate__inner">
		<h2 id="adult-gate-title"><?php esc_html_e( 'Adult Content Warning', 'starter-theme' ); ?></h2>
		<p id="adult-gate-desc"><?php esc_html_e( 'This chapter contains mature content intended for readers 18 years or older. By proceeding you confirm you meet the age requirement.', 'starter-theme' ); ?></p>
		<div class="reader-adult-gate__actions">
			<button class="btn btn--primary" data-adult-confirm><?php esc_html_e( 'I am 18 or older — Continue', 'starter-theme' ); ?></button>
			<a class="btn btn--outline" href="<?php echo esc_url( $manga_url ); ?>"><?php esc_html_e( 'Go Back', 'starter-theme' ); ?></a>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Reading Progress Bar -->
<div class="reader-progress" id="reader-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Reading progress', 'starter-theme' ); ?>">
	<div class="reader-progress__bar"></div>
</div>

<main id="primary" class="reader-page" role="main" data-manga-id="<?php echo esc_attr( $manga_id ); ?>" data-chapter-id="<?php echo esc_attr( $chapter->id ); ?>" data-total-pages="<?php echo esc_attr( $total_pages ); ?>" data-current-page="<?php echo esc_attr( $page_num ); ?>" data-protector="<?php echo esc_attr( $protector_active ? '1' : '0' ); ?>">

	<!-- ── Compact Header / Toolbar ─────────────────────────── -->
	<header class="reader-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Reader controls', 'starter-theme' ); ?>">
		<div class="reader-toolbar__inner">

			<!-- Manga title link -->
			<a href="<?php echo esc_url( $manga_url ); ?>" class="reader-toolbar__title" title="<?php echo esc_attr( $manga_title ); ?>">
				<?php echo esc_html( $manga_title ); ?>
			</a>

			<!-- Chapter selector -->
			<div class="reader-toolbar__nav">
				<?php if ( $prev_url ) : ?>
					<a href="<?php echo $prev_url; // Already escaped. ?>" class="reader-toolbar__btn reader-toolbar__btn--prev" aria-label="<?php esc_attr_e( 'Previous chapter', 'starter-theme' ); ?>">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</a>
				<?php else : ?>
					<span class="reader-toolbar__btn reader-toolbar__btn--prev reader-toolbar__btn--disabled" aria-disabled="true">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</span>
				<?php endif; ?>

				<label for="reader-chapter-select" class="screen-reader-text"><?php esc_html_e( 'Select chapter', 'starter-theme' ); ?></label>
				<select id="reader-chapter-select" class="reader-toolbar__select" data-chapter-select>
					<?php foreach ( $all_chs as $ch ) :
						$ch_label = sprintf(
							/* translators: %s: chapter number */
							esc_html__( 'Chapter %s', 'starter-theme' ),
							$ch->chapter_number
						);
						if ( ! empty( $ch->chapter_name ) ) {
							$ch_label .= ' - ' . $ch->chapter_name;
						}
						?>
						<option value="<?php echo esc_attr( $chapter_mgr->get_chapter_url( $manga_id, $ch ) ); ?>" <?php selected( (int) $ch->id, (int) $chapter->id ); ?>>
							<?php echo esc_html( $ch_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<?php if ( $next_url ) : ?>
					<a href="<?php echo $next_url; ?>" class="reader-toolbar__btn reader-toolbar__btn--next" aria-label="<?php esc_attr_e( 'Next chapter', 'starter-theme' ); ?>">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
					</a>
				<?php else : ?>
					<span class="reader-toolbar__btn reader-toolbar__btn--next reader-toolbar__btn--disabled" aria-disabled="true">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
					</span>
				<?php endif; ?>
			</div>

			<!-- Toolbar actions -->
			<div class="reader-toolbar__actions">
				<!-- Theme toggle (light / dark / sepia) -->
				<button class="reader-toolbar__btn" data-theme-cycle aria-label="<?php esc_attr_e( 'Toggle reading theme', 'starter-theme' ); ?>">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
				</button>

				<!-- Reading mode selector -->
				<button class="reader-toolbar__btn" data-mode-toggle aria-label="<?php esc_attr_e( 'Toggle reading mode', 'starter-theme' ); ?>" aria-pressed="false">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="12" x2="21" y2="12"/></svg>
					<span class="reader-toolbar__label"><?php esc_html_e( 'Single', 'starter-theme' ); ?></span>
				</button>

				<!-- Bookmark -->
				<button class="reader-toolbar__btn <?php echo $is_bookmarked ? 'reader-toolbar__btn--active' : ''; ?>" data-bookmark-chapter="<?php echo esc_attr( $chapter->id ); ?>" aria-label="<?php esc_attr_e( 'Bookmark this chapter', 'starter-theme' ); ?>" aria-pressed="<?php echo $is_bookmarked ? 'true' : 'false'; ?>">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="<?php echo $is_bookmarked ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
				</button>

				<!-- Keyboard shortcuts hint -->
				<button class="reader-toolbar__btn" data-shortcuts-toggle aria-label="<?php esc_attr_e( 'Keyboard shortcuts', 'starter-theme' ); ?>">
					<span aria-hidden="true" style="font-weight:700;font-size:16px;">?</span>
				</button>
			</div>

		</div>
	</header>

	<!-- ── Ad slot: before chapter ──────────────────────────── -->
	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'before_chapter' ) ); ?>

	<!-- ── Main Reading Area ────────────────────────────────── -->
	<section class="reader-content" id="reader-content" aria-label="<?php esc_attr_e( 'Chapter pages', 'starter-theme' ); ?>">

		<!-- Single-page mode container -->
		<div class="reader-single" id="reader-single" data-reader-mode="single" aria-live="polite">
			<?php if ( ! empty( $chapter_images ) ) : ?>
				<?php
				$safe_page = max( 1, min( $page_num, $total_pages ) );
				$img_url   = $chapter_images[ $safe_page - 1 ];
				?>

				<!-- Prev page arrow -->
				<button class="reader-single__arrow reader-single__arrow--prev" data-page-prev aria-label="<?php esc_attr_e( 'Previous page', 'starter-theme' ); ?>" <?php echo ( $safe_page <= 1 ) ? 'disabled' : ''; ?>>
					<svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				</button>

				<!-- Page image or canvas -->
				<div class="reader-single__page" id="reader-current-page">
					<?php if ( $protector_active ) : ?>
						<canvas class="reader-single__canvas" data-page-src="<?php echo esc_url( $img_url ); ?>" aria-label="<?php printf( esc_attr__( 'Page %1$d of %2$d', 'starter-theme' ), intval( $safe_page ), intval( $total_pages ) ); ?>"></canvas>
					<?php else : ?>
						<img class="reader-single__image" src="<?php echo esc_url( $img_url ); ?>" alt="<?php printf( esc_attr__( 'Page %1$d of %2$d', 'starter-theme' ), intval( $safe_page ), intval( $total_pages ) ); ?>" loading="eager">
					<?php endif; ?>
				</div>

				<!-- Next page arrow -->
				<button class="reader-single__arrow reader-single__arrow--next" data-page-next aria-label="<?php esc_attr_e( 'Next page', 'starter-theme' ); ?>" <?php echo ( $safe_page >= $total_pages ) ? 'disabled' : ''; ?>>
					<svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
				</button>

				<!-- Page indicator -->
				<div class="reader-single__indicator" aria-live="polite">
					<span data-page-current><?php echo esc_html( $safe_page ); ?></span>
					<span>/</span>
					<span data-page-total><?php echo esc_html( $total_pages ); ?></span>
				</div>
			<?php else : ?>
				<p class="reader-content__empty"><?php esc_html_e( 'No pages available for this chapter.', 'starter-theme' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- One-shot (long-strip) mode container -->
		<div class="reader-longstrip" id="reader-longstrip" data-reader-mode="longstrip" hidden>
			<?php if ( ! empty( $chapter_images ) ) : ?>
				<?php foreach ( $chapter_images as $idx => $img ) : ?>
					<?php if ( $protector_active ) : ?>
						<canvas class="reader-longstrip__canvas" data-page-src="<?php echo esc_url( $img ); ?>" data-page-index="<?php echo esc_attr( $idx + 1 ); ?>" aria-label="<?php printf( esc_attr__( 'Page %1$d of %2$d', 'starter-theme' ), intval( $idx + 1 ), intval( $total_pages ) ); ?>"></canvas>
					<?php else : ?>
						<img class="reader-longstrip__image" src="<?php echo esc_url( $img ); ?>" alt="<?php printf( esc_attr__( 'Page %1$d of %2$d', 'starter-theme' ), intval( $idx + 1 ), intval( $total_pages ) ); ?>" loading="lazy">
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<!-- Loading spinner -->
		<div class="reader-content__loading" id="reader-loading" hidden>
			<span class="reader-content__spinner" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Loading pages…', 'starter-theme' ); ?></span>
		</div>

	</section>

	<!-- ── Floating Navigation (fixed bottom) ───────────────── -->
	<nav class="reader-float-nav" id="reader-float-nav" aria-label="<?php esc_attr_e( 'Chapter navigation', 'starter-theme' ); ?>">
		<?php if ( $prev_url ) : ?>
			<a href="<?php echo $prev_url; ?>" class="reader-float-nav__btn reader-float-nav__btn--prev">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				<?php esc_html_e( 'Prev Chapter', 'starter-theme' ); ?>
			</a>
		<?php else : ?>
			<span class="reader-float-nav__btn reader-float-nav__btn--prev reader-float-nav__btn--disabled" aria-disabled="true">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				<?php esc_html_e( 'Prev Chapter', 'starter-theme' ); ?>
			</span>
		<?php endif; ?>

		<?php if ( $next_url ) : ?>
			<a href="<?php echo $next_url; ?>" class="reader-float-nav__btn reader-float-nav__btn--next">
				<?php esc_html_e( 'Next Chapter', 'starter-theme' ); ?>
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</a>
		<?php else : ?>
			<span class="reader-float-nav__btn reader-float-nav__btn--next reader-float-nav__btn--disabled" aria-disabled="true">
				<?php esc_html_e( 'Next Chapter', 'starter-theme' ); ?>
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</span>
		<?php endif; ?>
	</nav>

	<!-- ── Keyboard Shortcuts Overlay ───────────────────────── -->
	<div class="reader-shortcuts-overlay" id="reader-shortcuts-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="shortcuts-title">
		<div class="reader-shortcuts-overlay__inner">
			<h2 id="shortcuts-title"><?php esc_html_e( 'Keyboard Shortcuts', 'starter-theme' ); ?></h2>
			<button class="reader-shortcuts-overlay__close" data-shortcuts-close aria-label="<?php esc_attr_e( 'Close shortcuts', 'starter-theme' ); ?>">&times;</button>
			<dl class="reader-shortcuts-overlay__list">
				<div>
					<dt><kbd>&larr;</kbd></dt>
					<dd><?php esc_html_e( 'Previous page', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>&rarr;</kbd></dt>
					<dd><?php esc_html_e( 'Next page', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>[</kbd></dt>
					<dd><?php esc_html_e( 'Previous chapter', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>]</kbd></dt>
					<dd><?php esc_html_e( 'Next chapter', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>F</kbd></dt>
					<dd><?php esc_html_e( 'Toggle fullscreen', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>M</kbd></dt>
					<dd><?php esc_html_e( 'Toggle reading mode', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>T</kbd></dt>
					<dd><?php esc_html_e( 'Cycle theme', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>B</kbd></dt>
					<dd><?php esc_html_e( 'Bookmark chapter', 'starter-theme' ); ?></dd>
				</div>
				<div>
					<dt><kbd>?</kbd></dt>
					<dd><?php esc_html_e( 'Show / hide shortcuts', 'starter-theme' ); ?></dd>
				</div>
			</dl>
		</div>
	</div>

	<!-- ── Chapter End Section ───────────────────────────────── -->
	<section class="reader-end" id="reader-end" aria-label="<?php esc_attr_e( 'Chapter end', 'starter-theme' ); ?>">

		<?php if ( $next_ch ) : ?>
			<div class="reader-end__next-card">
				<span class="reader-end__label"><?php esc_html_e( 'Next Chapter', 'starter-theme' ); ?></span>
				<a href="<?php echo $next_url; ?>" class="reader-end__next-link">
					<?php
					$next_label = sprintf(
						/* translators: %s: chapter number */
						esc_html__( 'Chapter %s', 'starter-theme' ),
						esc_html( $next_ch->chapter_number )
					);
					if ( ! empty( $next_ch->chapter_name ) ) {
						$next_label .= ' — ' . esc_html( $next_ch->chapter_name );
					}
					echo esc_html( $next_label );
					?>
				</a>
			</div>
		<?php else : ?>
			<p class="reader-end__finished"><?php esc_html_e( 'You have reached the latest chapter.', 'starter-theme' ); ?></p>
		<?php endif; ?>

		<a href="<?php echo esc_url( $manga_url ); ?>" class="btn btn--outline reader-end__back">
			<?php esc_html_e( 'Back to Manga', 'starter-theme' ); ?>
		</a>

		<!-- Comments -->
		<div class="reader-end__comments" aria-label="<?php esc_attr_e( 'Chapter comments', 'starter-theme' ); ?>">
			<?php
			if ( has_action( 'starter_manga_comments' ) ) {
				do_action( 'starter_manga_comments', $manga_id );
			} elseif ( comments_open( $manga_id ) || get_comments_number( $manga_id ) ) {
				comments_template();
			}
			?>
		</div>
	</section>

	<!-- ── Ad slot: after chapter ───────────────────────────── -->
	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'after_chapter' ) ); ?>

</main>

<?php
get_footer();
