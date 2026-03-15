<?php
/**
 * Template: Novel Chapter Reader
 *
 * Text-based chapter reading experience with customisable typography,
 * reading tools, estimated reading time, TTS integration,
 * and scroll-based progress tracking.
 *
 * Expected query vars (set by Starter_Manga_Reader):
 *   starter_current_chapter  — chapter row object
 *   starter_current_page     — int (text page for in-chapter pagination)
 *   starter_manga_id         — int, parent manga/novel post ID
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Data bootstrap ─────────────────────────────────────────── */
$chapter  = get_query_var( 'starter_current_chapter' );
$page_num = get_query_var( 'starter_current_page', 1 );
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

/* Chapter content (HTML text). */
$raw_data       = json_decode( $chapter->chapter_data, true );
$chapter_html   = '';
if ( is_array( $raw_data ) && isset( $raw_data['content'] ) ) {
	$chapter_html = $raw_data['content'];
} elseif ( is_string( $raw_data ) ) {
	$chapter_html = $raw_data;
} elseif ( is_string( $chapter->chapter_data ) ) {
	$chapter_html = $chapter->chapter_data;
}

/* In-chapter pagination. */
$novel_reader = Starter_Novel_Reader::get_instance();
$pages        = $novel_reader->paginate_html( $chapter_html );
$total_pages  = count( $pages );
$current_page = max( 1, min( intval( $page_num ), $total_pages ) );
$page_content = isset( $pages[ $current_page - 1 ] ) ? $pages[ $current_page - 1 ] : '';

/* Estimated reading time (avg 200 wpm). */
$word_count   = str_word_count( wp_strip_all_tags( $chapter_html ) );
$reading_mins = max( 1, intval( ceil( $word_count / 200 ) ) );

/* Chapter label. */
$chapter_label = sprintf(
	/* translators: %s: chapter number */
	__( 'Chapter %s', 'starter-theme' ),
	$chapter->chapter_number
);
if ( ! empty( $chapter->chapter_name ) ) {
	$chapter_label .= ' — ' . $chapter->chapter_name;
}

/* Schema.org JSON-LD. */
$schema = array(
	'@context'    => 'https://schema.org',
	'@type'       => 'Chapter',
	'name'        => $chapter_label,
	'url'         => $chapter_mgr->get_chapter_url( $manga_id, $chapter ),
	'wordCount'   => $word_count,
	'isPartOf'    => array(
		'@type' => 'Book',
		'name'  => $manga_title,
		'url'   => $manga_url,
	),
	'position'    => $chapter->chapter_number,
);

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<!-- Reading Progress Indicator (scroll-based) -->
<div class="novel-progress" id="novel-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Reading progress', 'starter-theme' ); ?>">
	<div class="novel-progress__bar"></div>
</div>

<main id="primary" class="novel-reader-page" role="main" data-manga-id="<?php echo esc_attr( $manga_id ); ?>" data-chapter-id="<?php echo esc_attr( $chapter->id ); ?>" data-current-page="<?php echo esc_attr( $current_page ); ?>" data-total-pages="<?php echo esc_attr( $total_pages ); ?>">

	<!-- ── Compact Header / Toolbar ─────────────────────────── -->
	<header class="reader-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Novel reader controls', 'starter-theme' ); ?>">
		<div class="reader-toolbar__inner">

			<a href="<?php echo esc_url( $manga_url ); ?>" class="reader-toolbar__title" title="<?php echo esc_attr( $manga_title ); ?>">
				<?php echo esc_html( $manga_title ); ?>
			</a>

			<!-- Chapter selector -->
			<div class="reader-toolbar__nav">
				<?php if ( $prev_url ) : ?>
					<a href="<?php echo $prev_url; ?>" class="reader-toolbar__btn reader-toolbar__btn--prev" aria-label="<?php esc_attr_e( 'Previous chapter', 'starter-theme' ); ?>">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</a>
				<?php else : ?>
					<span class="reader-toolbar__btn reader-toolbar__btn--prev reader-toolbar__btn--disabled" aria-disabled="true">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
					</span>
				<?php endif; ?>

				<label for="novel-chapter-select" class="screen-reader-text"><?php esc_html_e( 'Select chapter', 'starter-theme' ); ?></label>
				<select id="novel-chapter-select" class="reader-toolbar__select" data-chapter-select>
					<?php foreach ( $all_chs as $ch ) :
						$ch_label = sprintf(
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

			<!-- Clean reading mode toggle -->
			<div class="reader-toolbar__actions">
				<button class="reader-toolbar__btn" data-clean-mode-toggle aria-label="<?php esc_attr_e( 'Toggle clean reading mode', 'starter-theme' ); ?>" aria-pressed="false">
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
				</button>
			</div>

		</div>
	</header>

	<!-- ── Estimated reading time ───────────────────────────── -->
	<div class="novel-reader__meta">
		<span class="novel-reader__reading-time" aria-label="<?php esc_attr_e( 'Estimated reading time', 'starter-theme' ); ?>">
			<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			<?php
			printf(
				/* translators: %d: number of minutes */
				esc_html( _n( '%d min read', '%d min read', $reading_mins, 'starter-theme' ) ),
				intval( $reading_mins )
			);
			?>
		</span>
		<?php if ( $total_pages > 1 ) : ?>
			<span class="novel-reader__page-info">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'starter-theme' ),
					intval( $current_page ),
					intval( $total_pages )
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<!-- ── Ad slot: before novel text ───────────────────────── -->
	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'before_chapter' ) ); ?>

	<!-- ── Novel Content ────────────────────────────────────── -->
	<article class="novel-content" id="novel-content" role="article" style="--novel-font-family: 'Georgia', serif; --novel-font-size: 18px; --novel-line-height: 1.8; --novel-text-color: inherit; --novel-bg-color: transparent;" aria-label="<?php echo esc_attr( $chapter_label ); ?>">

		<h1 class="novel-content__title"><?php echo esc_html( $chapter_label ); ?></h1>

		<div class="novel-content__body" id="novel-content-body">
			<?php echo wp_kses_post( $page_content ); ?>
		</div>

	</article>

	<!-- ── In-chapter Pagination ────────────────────────────── -->
	<?php if ( $total_pages > 1 ) : ?>
		<nav class="novel-pagination" aria-label="<?php esc_attr_e( 'Chapter pagination', 'starter-theme' ); ?>">
			<?php if ( $current_page > 1 ) :
				$prev_page_url = add_query_arg( 'text_page', $current_page - 1, $chapter_mgr->get_chapter_url( $manga_id, $chapter ) );
				?>
				<a href="<?php echo esc_url( $prev_page_url ); ?>" class="novel-pagination__btn novel-pagination__btn--prev" rel="prev">
					<?php esc_html_e( 'Previous Page', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>

			<span class="novel-pagination__info">
				<?php
				printf(
					esc_html__( 'Page %1$d of %2$d', 'starter-theme' ),
					intval( $current_page ),
					intval( $total_pages )
				);
				?>
			</span>

			<?php if ( $current_page < $total_pages ) :
				$next_page_url = add_query_arg( 'text_page', $current_page + 1, $chapter_mgr->get_chapter_url( $manga_id, $chapter ) );
				?>
				<a href="<?php echo esc_url( $next_page_url ); ?>" class="novel-pagination__btn novel-pagination__btn--next" rel="next">
					<?php esc_html_e( 'Next Page', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

	<!-- ── Ad slot: after novel text ────────────────────────── -->
	<?php get_template_part( 'templates/parts/ad-slot', null, array( 'location' => 'after_chapter' ) ); ?>

	<!-- ── Text-to-Speech hook ──────────────────────────────── -->
	<div class="novel-reader__tts" aria-label="<?php esc_attr_e( 'Text-to-speech controls', 'starter-theme' ); ?>">
		<?php
		/**
		 * Hook for TTS plugin controls (e.g. Speaker plugin).
		 *
		 * @param int    $manga_id         Post ID.
		 * @param string $content_selector CSS selector for the text container.
		 */
		do_action( 'starter_novel_tts_controls', $manga_id, '#novel-content-body' );
		?>
		<button class="novel-reader__tts-btn" data-tts-trigger aria-label="<?php esc_attr_e( 'Read aloud', 'starter-theme' ); ?>">
			<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
			<span><?php esc_html_e( 'Read Aloud', 'starter-theme' ); ?></span>
		</button>
	</div>

	<!-- ── Chapter Navigation (prev/next chapter) ───────────── -->
	<nav class="novel-chapter-nav" aria-label="<?php esc_attr_e( 'Chapter navigation', 'starter-theme' ); ?>">
		<?php if ( $prev_url ) : ?>
			<a href="<?php echo $prev_url; ?>" class="novel-chapter-nav__btn novel-chapter-nav__btn--prev">
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				<?php esc_html_e( 'Previous Chapter', 'starter-theme' ); ?>
			</a>
		<?php endif; ?>

		<a href="<?php echo esc_url( $manga_url ); ?>" class="novel-chapter-nav__btn novel-chapter-nav__btn--back">
			<?php esc_html_e( 'Back to Novel', 'starter-theme' ); ?>
		</a>

		<?php if ( $next_url ) : ?>
			<a href="<?php echo $next_url; ?>" class="novel-chapter-nav__btn novel-chapter-nav__btn--next">
				<?php esc_html_e( 'Next Chapter', 'starter-theme' ); ?>
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</a>
		<?php endif; ?>
	</nav>

	<!-- ── Reading Tools Floating Panel ─────────────────────── -->
	<aside class="novel-tools" id="novel-tools" aria-label="<?php esc_attr_e( 'Reading tools', 'starter-theme' ); ?>" hidden>
		<div class="novel-tools__inner">

			<h3 class="novel-tools__heading"><?php esc_html_e( 'Reading Tools', 'starter-theme' ); ?></h3>

			<!-- Background presets -->
			<fieldset class="novel-tools__group">
				<legend><?php esc_html_e( 'Background', 'starter-theme' ); ?></legend>
				<div class="novel-tools__presets">
					<button class="novel-tools__preset novel-tools__preset--light" data-bg-preset="light" aria-label="<?php esc_attr_e( 'Light background', 'starter-theme' ); ?>" title="<?php esc_attr_e( 'Light', 'starter-theme' ); ?>"></button>
					<button class="novel-tools__preset novel-tools__preset--dark" data-bg-preset="dark" aria-label="<?php esc_attr_e( 'Dark background', 'starter-theme' ); ?>" title="<?php esc_attr_e( 'Dark', 'starter-theme' ); ?>"></button>
					<button class="novel-tools__preset novel-tools__preset--sepia" data-bg-preset="sepia" aria-label="<?php esc_attr_e( 'Sepia background', 'starter-theme' ); ?>" title="<?php esc_attr_e( 'Sepia', 'starter-theme' ); ?>"></button>
					<button class="novel-tools__preset novel-tools__preset--grey" data-bg-preset="grey" aria-label="<?php esc_attr_e( 'Grey background', 'starter-theme' ); ?>" title="<?php esc_attr_e( 'Grey', 'starter-theme' ); ?>"></button>
				</div>
			</fieldset>

			<!-- Text colour -->
			<div class="novel-tools__group">
				<label for="novel-text-color"><?php esc_html_e( 'Text Color', 'starter-theme' ); ?></label>
				<input type="color" id="novel-text-color" class="novel-tools__color" data-text-color value="#333333">
			</div>

			<!-- Font family -->
			<div class="novel-tools__group">
				<label for="novel-font-family"><?php esc_html_e( 'Font Family', 'starter-theme' ); ?></label>
				<select id="novel-font-family" class="novel-tools__select" data-font-family>
					<option value="'Georgia', serif"><?php esc_html_e( 'Georgia', 'starter-theme' ); ?></option>
					<option value="'Merriweather', serif"><?php esc_html_e( 'Merriweather', 'starter-theme' ); ?></option>
					<option value="'Lora', serif"><?php esc_html_e( 'Lora', 'starter-theme' ); ?></option>
					<option value="'Open Sans', sans-serif"><?php esc_html_e( 'Open Sans', 'starter-theme' ); ?></option>
					<option value="'Roboto', sans-serif"><?php esc_html_e( 'Roboto', 'starter-theme' ); ?></option>
					<option value="'Source Sans Pro', sans-serif"><?php esc_html_e( 'Source Sans Pro', 'starter-theme' ); ?></option>
					<option value="'Fira Mono', monospace"><?php esc_html_e( 'Fira Mono', 'starter-theme' ); ?></option>
				</select>
			</div>

			<!-- Font size slider -->
			<div class="novel-tools__group">
				<label for="novel-font-size"><?php esc_html_e( 'Font Size', 'starter-theme' ); ?></label>
				<input type="range" id="novel-font-size" class="novel-tools__range" data-font-size min="12" max="32" step="1" value="18">
				<output for="novel-font-size" class="novel-tools__output">18px</output>
			</div>

			<!-- Line height slider -->
			<div class="novel-tools__group">
				<label for="novel-line-height"><?php esc_html_e( 'Line Height', 'starter-theme' ); ?></label>
				<input type="range" id="novel-line-height" class="novel-tools__range" data-line-height min="1.2" max="3.0" step="0.1" value="1.8">
				<output for="novel-line-height" class="novel-tools__output">1.8</output>
			</div>

			<!-- Reset button -->
			<button class="btn btn--outline novel-tools__reset" data-reset-tools>
				<?php esc_html_e( 'Reset to Defaults', 'starter-theme' ); ?>
			</button>

		</div>
	</aside>

	<!-- Floating toggle for tools panel -->
	<button class="novel-tools-toggle" id="novel-tools-toggle" data-tools-toggle aria-expanded="false" aria-controls="novel-tools" aria-label="<?php esc_attr_e( 'Toggle reading tools', 'starter-theme' ); ?>">
		<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
	</button>

</main>

<?php
get_footer();
