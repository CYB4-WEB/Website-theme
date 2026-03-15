<?php
/**
 * Homepage Template — Project Alpha
 *
 * Displays hero slider, latest releases, popular manga,
 * genre quick-nav, and a "recently added" section.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

/* ── Query helpers ───────────────────────────────────────────── */
$latest_manga = new WP_Query( array(
	'post_type'      => 'wp-manga',
	'posts_per_page' => 12,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'post_status'    => 'publish',
) );

$popular_manga = new WP_Query( array(
	'post_type'      => 'wp-manga',
	'posts_per_page' => 8,
	'orderby'        => 'meta_value_num',
	'meta_key'       => '_views',
	'order'          => 'DESC',
	'post_status'    => 'publish',
) );

$featured_manga = new WP_Query( array(
	'post_type'      => 'wp-manga',
	'posts_per_page' => 5,
	'meta_query'     => array(
		array(
			'key'   => '_featured',
			'value' => '1',
		),
	),
	'post_status'    => 'publish',
) );

/* Fall back to latest if no featured found */
if ( ! $featured_manga->have_posts() ) {
	$featured_manga = new WP_Query( array(
		'post_type'      => 'wp-manga',
		'posts_per_page' => 5,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => 'publish',
	) );
}

$genres = get_terms( array(
	'taxonomy'   => 'genre',
	'hide_empty' => true,
	'number'     => 20,
	'orderby'    => 'count',
	'order'      => 'DESC',
) );
?>

<main id="primary" class="site-main home-page" role="main">

	<!-- ══════════════════════════════════════════════════════════
	     HERO SLIDER
	     ══════════════════════════════════════════════════════════ -->
	<section class="hero-slider" aria-label="<?php esc_attr_e( 'Featured Manga', 'starter-theme' ); ?>">
		<div class="hero-slider__track" id="hero-track">

			<?php
			$slide_index = 0;
			if ( $featured_manga->have_posts() ) :
				while ( $featured_manga->have_posts() ) :
					$featured_manga->the_post();
					$mid       = get_the_ID();
					$cover     = get_the_post_thumbnail_url( $mid, 'full' );
					$genres_list = get_the_terms( $mid, 'genre' );
					$desc      = wp_trim_words( get_the_excerpt() ?: get_the_content(), 25, '…' );
					$first_ch  = function_exists( 'starter_get_first_chapter_url' ) ? starter_get_first_chapter_url( $mid ) : get_permalink();
					$rating    = function_exists( 'starter_get_manga_rating' ) ? starter_get_manga_rating( $mid ) : 0;
					$status    = get_post_meta( $mid, '_status', true );
					?>
					<div class="hero-slide <?php echo $slide_index === 0 ? 'is-active' : ''; ?>"
					     data-slide="<?php echo esc_attr( $slide_index ); ?>"
					     style="<?php echo $cover ? 'background-image:url(' . esc_url( $cover ) . ')' : ''; ?>">

						<div class="hero-slide__overlay"></div>

						<div class="hero-slide__content container">
							<div class="hero-slide__meta">
								<?php if ( $status ) : ?>
									<span class="badge badge--<?php echo esc_attr( strtolower( $status ) ); ?>"><?php echo esc_html( $status ); ?></span>
								<?php endif; ?>
								<?php if ( is_array( $genres_list ) && ! is_wp_error( $genres_list ) ) : ?>
									<?php foreach ( array_slice( $genres_list, 0, 3 ) as $g ) : ?>
										<a href="<?php echo esc_url( get_term_link( $g ) ); ?>" class="hero-slide__genre"><?php echo esc_html( $g->name ); ?></a>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<h2 class="hero-slide__title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>

							<?php if ( $rating > 0 ) : ?>
								<div class="hero-slide__rating">
									<span class="star-icon">★</span>
									<span><?php echo number_format( $rating, 1 ); ?></span>
								</div>
							<?php endif; ?>

							<?php if ( $desc ) : ?>
								<p class="hero-slide__desc"><?php echo esc_html( $desc ); ?></p>
							<?php endif; ?>

							<div class="hero-slide__actions">
								<a href="<?php echo esc_url( $first_ch ); ?>" class="btn btn--primary btn--lg">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
									<?php esc_html_e( 'Start Reading', 'starter-theme' ); ?>
								</a>
								<a href="<?php the_permalink(); ?>" class="btn btn--outline btn--lg">
									<?php esc_html_e( 'Details', 'starter-theme' ); ?>
								</a>
							</div>
						</div><!-- .hero-slide__content -->

						<?php if ( $cover ) : ?>
							<div class="hero-slide__cover">
								<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
							</div>
						<?php endif; ?>
					</div><!-- .hero-slide -->
					<?php
					$slide_index++;
				endwhile;
				wp_reset_postdata();
			else :
				/* ── Placeholder slide when no manga yet ── */
				?>
				<div class="hero-slide is-active hero-slide--placeholder">
					<div class="hero-slide__overlay"></div>
					<div class="hero-slide__content container">
						<div class="hero-slide__meta">
							<span class="badge badge--new"><?php esc_html_e( 'New', 'starter-theme' ); ?></span>
						</div>
						<h2 class="hero-slide__title"><?php esc_html_e( 'Welcome to Project Alpha', 'starter-theme' ); ?></h2>
						<p class="hero-slide__desc"><?php esc_html_e( 'Your ultimate manga reading platform. Start by adding manga from the admin dashboard or import from MangaUpdates.', 'starter-theme' ); ?></p>
						<div class="hero-slide__actions">
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wp-manga' ) ); ?>" class="btn btn--primary btn--lg">
								<?php esc_html_e( 'Add Manga', 'starter-theme' ); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endif; ?>

		</div><!-- .hero-slider__track -->

		<!-- Slider Controls -->
		<?php if ( $slide_index > 1 ) : ?>
			<button class="hero-slider__btn hero-slider__btn--prev" aria-label="<?php esc_attr_e( 'Previous slide', 'starter-theme' ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
			</button>
			<button class="hero-slider__btn hero-slider__btn--next" aria-label="<?php esc_attr_e( 'Next slide', 'starter-theme' ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</button>
			<div class="hero-slider__dots" role="tablist">
				<?php for ( $i = 0; $i < $slide_index; $i++ ) : ?>
					<button class="hero-slider__dot <?php echo $i === 0 ? 'is-active' : ''; ?>"
					        role="tab"
					        aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
					        aria-label="<?php printf( esc_attr__( 'Go to slide %d', 'starter-theme' ), $i + 1 ); ?>"
					        data-slide="<?php echo esc_attr( $i ); ?>"></button>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</section><!-- .hero-slider -->


	<!-- ══════════════════════════════════════════════════════════
	     GENRE QUICK NAV
	     ══════════════════════════════════════════════════════════ -->
	<?php if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) : ?>
	<section class="genre-nav container" aria-label="<?php esc_attr_e( 'Browse by Genre', 'starter-theme' ); ?>">
		<div class="genre-nav__track">
			<?php foreach ( $genres as $genre ) : ?>
				<a href="<?php echo esc_url( get_term_link( $genre ) ); ?>" class="genre-chip">
					<?php echo esc_html( $genre->name ); ?>
					<span class="genre-chip__count"><?php echo esc_html( $genre->count ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>


	<!-- ══════════════════════════════════════════════════════════
	     LATEST RELEASES
	     ══════════════════════════════════════════════════════════ -->
	<section class="home-section container" aria-labelledby="latest-heading">

		<div class="home-section__header">
			<h2 class="home-section__title" id="latest-heading">
				<span class="accent-bar"></span>
				<?php esc_html_e( 'Latest Releases', 'starter-theme' ); ?>
			</h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'wp-manga' ) ); ?>" class="home-section__more">
				<?php esc_html_e( 'View All', 'starter-theme' ); ?>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
			</a>
		</div>

		<div class="manga-grid manga-grid--6">
			<?php
			if ( $latest_manga->have_posts() ) :
				while ( $latest_manga->have_posts() ) :
					$latest_manga->the_post();
					get_template_part( 'templates/parts/manga-card' );
				endwhile;
				wp_reset_postdata();
			else :
				/* Placeholder cards when no manga exists */
				for ( $i = 0; $i < 6; $i++ ) :
					?>
					<div class="manga-card manga-card--placeholder">
						<div class="manga-card__cover manga-card__cover--skeleton"></div>
						<div class="manga-card__body">
							<div class="skeleton-line"></div>
							<div class="skeleton-line skeleton-line--short"></div>
						</div>
					</div>
				<?php endfor;
			endif;
			?>
		</div>

	</section><!-- latest releases -->


	<!-- ══════════════════════════════════════════════════════════
	     POPULAR MANGA
	     ══════════════════════════════════════════════════════════ -->
	<section class="home-section home-section--alt" aria-labelledby="popular-heading">
		<div class="container">

			<div class="home-section__header">
				<h2 class="home-section__title" id="popular-heading">
					<span class="accent-bar"></span>
					<?php esc_html_e( 'Most Popular', 'starter-theme' ); ?>
				</h2>
				<a href="<?php echo esc_url( add_query_arg( 'sort', 'views', get_post_type_archive_link( 'wp-manga' ) ) ); ?>" class="home-section__more">
					<?php esc_html_e( 'View All', 'starter-theme' ); ?>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
				</a>
			</div>

			<div class="popular-list">
				<?php
				$rank = 1;
				if ( $popular_manga->have_posts() ) :
					while ( $popular_manga->have_posts() ) :
						$popular_manga->the_post();
						$pid      = get_the_ID();
						$cover    = get_the_post_thumbnail_url( $pid, 'starter-thumb' );
						$views    = get_post_meta( $pid, '_views', true );
						$rating   = function_exists( 'starter_get_manga_rating' ) ? starter_get_manga_rating( $pid ) : 0;
						$latest_ch = function_exists( 'starter_get_latest_chapter' ) ? starter_get_latest_chapter( $pid ) : null;
						?>
						<article class="popular-item">
							<span class="popular-item__rank rank-<?php echo $rank <= 3 ? $rank : 'normal'; ?>"><?php echo esc_html( $rank ); ?></span>
							<a href="<?php the_permalink(); ?>" class="popular-item__cover-link">
								<?php if ( $cover ) : ?>
									<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy" class="popular-item__cover">
								<?php else : ?>
									<div class="popular-item__cover popular-item__cover--empty"></div>
								<?php endif; ?>
							</a>
							<div class="popular-item__info">
								<h3 class="popular-item__title">
									<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
								</h3>
								<div class="popular-item__meta">
									<?php if ( $latest_ch ) : ?>
										<a href="<?php echo esc_url( function_exists( 'starter_get_chapter_url' ) ? starter_get_chapter_url( $pid, $latest_ch ) : '#' ); ?>" class="popular-item__ch">
											Ch. <?php echo esc_html( $latest_ch->chapter_number ?? '' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( $rating > 0 ) : ?>
										<span class="popular-item__rating">★ <?php echo number_format( $rating, 1 ); ?></span>
									<?php endif; ?>
									<?php if ( $views ) : ?>
										<span class="popular-item__views">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
											<?php echo esc_html( number_format( (int) $views ) ); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</article>
						<?php
						$rank++;
					endwhile;
					wp_reset_postdata();
				else :
					for ( $i = 1; $i <= 5; $i++ ) :
						?>
						<article class="popular-item popular-item--placeholder">
							<span class="popular-item__rank"><?php echo $i; ?></span>
							<div class="popular-item__cover popular-item__cover--skeleton"></div>
							<div class="popular-item__info">
								<div class="skeleton-line"></div>
								<div class="skeleton-line skeleton-line--short"></div>
							</div>
						</article>
					<?php endfor;
				endif;
				?>
			</div>

		</div>
	</section><!-- popular manga -->


	<!-- ══════════════════════════════════════════════════════════
	     CONTENT TYPE TABS (Manga / Novel / Video)
	     ══════════════════════════════════════════════════════════ -->
	<section class="home-section container" aria-labelledby="explore-heading">

		<div class="home-section__header">
			<h2 class="home-section__title" id="explore-heading">
				<span class="accent-bar"></span>
				<?php esc_html_e( 'Explore', 'starter-theme' ); ?>
			</h2>
			<div class="tab-nav" role="tablist">
				<button class="tab-btn is-active" role="tab" aria-selected="true" data-tab="manga"><?php esc_html_e( 'Manga', 'starter-theme' ); ?></button>
				<button class="tab-btn" role="tab" aria-selected="false" data-tab="novel"><?php esc_html_e( 'Novel', 'starter-theme' ); ?></button>
				<button class="tab-btn" role="tab" aria-selected="false" data-tab="video"><?php esc_html_e( 'Video', 'starter-theme' ); ?></button>
			</div>
		</div>

		<?php
		$content_types = array( 'manga', 'novel', 'video' );
		foreach ( $content_types as $ctype ) :
			$tab_query = new WP_Query( array(
				'post_type'      => 'wp-manga',
				'posts_per_page' => 6,
				'meta_query'     => array(
					array(
						'key'     => '_content_type',
						'value'   => $ctype,
						'compare' => '=',
					),
				),
				'post_status'    => 'publish',
			) );
			?>
			<div class="tab-panel <?php echo $ctype === 'manga' ? 'is-active' : ''; ?>" data-tab="<?php echo esc_attr( $ctype ); ?>" role="tabpanel">
				<?php if ( $tab_query->have_posts() ) : ?>
					<div class="manga-grid manga-grid--6">
						<?php
						while ( $tab_query->have_posts() ) :
							$tab_query->the_post();
							get_template_part( 'templates/parts/manga-card' );
						endwhile;
						wp_reset_postdata();
						?>
					</div>
				<?php else : ?>
					<div class="empty-state">
						<div class="empty-state__icon">
							<?php if ( $ctype === 'manga' ) : ?>
								<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
							<?php elseif ( $ctype === 'novel' ) : ?>
								<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
							<?php else : ?>
								<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
							<?php endif; ?>
						</div>
						<p class="empty-state__text">
							<?php printf( esc_html__( 'No %s content yet.', 'starter-theme' ), esc_html( $ctype ) ); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

	</section><!-- explore tabs -->


	<!-- ══════════════════════════════════════════════════════════
	     CALL TO ACTION — UPLOAD / JOIN
	     ══════════════════════════════════════════════════════════ -->
	<?php if ( ! is_user_logged_in() ) : ?>
	<section class="home-cta" aria-labelledby="cta-heading">
		<div class="container">
			<div class="home-cta__inner">
				<div class="home-cta__text">
					<h2 id="cta-heading"><?php esc_html_e( 'Join the Community', 'starter-theme' ); ?></h2>
					<p><?php esc_html_e( 'Create an account to bookmark manga, track reading history, unlock premium chapters, and more.', 'starter-theme' ); ?></p>
				</div>
				<div class="home-cta__actions">
					<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="btn btn--primary btn--lg">
						<?php esc_html_e( 'Create Account', 'starter-theme' ); ?>
					</a>
					<a href="<?php echo esc_url( wp_login_url() ); ?>" class="btn btn--outline btn--lg">
						<?php esc_html_e( 'Sign In', 'starter-theme' ); ?>
					</a>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>

</main><!-- #primary -->

<?php
/* ── Hero slider JS ─────────────────────────────────────────── */
?>
<script>
(function(){
	var track  = document.getElementById('hero-track');
	if(!track) return;
	var slides = track.querySelectorAll('.hero-slide');
	var dots   = document.querySelectorAll('.hero-slider__dot');
	var current= 0;
	var timer;

	function goTo(n){
		slides[current].classList.remove('is-active');
		if(dots[current]) dots[current].classList.remove('is-active');
		current = (n + slides.length) % slides.length;
		slides[current].classList.add('is-active');
		if(dots[current]){ dots[current].classList.add('is-active'); dots[current].setAttribute('aria-selected','true'); }
		if(dots[current ? current-1 : slides.length-1]) dots[current ? current-1 : slides.length-1].setAttribute('aria-selected','false');
		resetTimer();
	}

	function resetTimer(){
		clearInterval(timer);
		if(slides.length > 1) timer = setInterval(function(){ goTo(current+1); }, 6000);
	}

	document.querySelector('.hero-slider__btn--next') && document.querySelector('.hero-slider__btn--next').addEventListener('click', function(){ goTo(current+1); });
	document.querySelector('.hero-slider__btn--prev') && document.querySelector('.hero-slider__btn--prev').addEventListener('click', function(){ goTo(current-1); });
	dots.forEach(function(d){ d.addEventListener('click', function(){ goTo(parseInt(d.dataset.slide,10)); }); });

	/* Tab switching for Explore section */
	var tabBtns  = document.querySelectorAll('.tab-btn');
	var tabPanels= document.querySelectorAll('.tab-panel');
	tabBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			tabBtns.forEach(function(b){ b.classList.remove('is-active'); b.setAttribute('aria-selected','false'); });
			tabPanels.forEach(function(p){ p.classList.remove('is-active'); });
			btn.classList.add('is-active');
			btn.setAttribute('aria-selected','true');
			var target = btn.dataset.tab;
			var panel  = document.querySelector('.tab-panel[data-tab="'+target+'"]');
			if(panel) panel.classList.add('is-active');
		});
	});

	resetTimer();
})();
</script>

<?php get_footer(); ?>
