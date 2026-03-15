<?php
/**
 * Hero Slider Widget.
 *
 * Full-width hero banner slider for homepage featuring selected manga
 * with glassmorphism overlay and navigation controls.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Hero_Slider_Widget
 */
class Starter_Hero_Slider_Widget extends WP_Widget {

	/**
	 * Maximum number of featured manga.
	 *
	 * @var int
	 */
	const MAX_SLIDES = 10;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'starter_hero_slider',
			esc_html__( 'Hero Slider', 'starter-theme' ),
			array(
				'description'                 => esc_html__( 'Full-width hero banner slider for homepage.', 'starter-theme' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Default widget options.
	 *
	 * @return array
	 */
	private function get_defaults() {
		return array(
			'manga_ids'        => array(),
			'auto_slide'       => 5000,
			'show_title'       => true,
			'show_description' => true,
			'overlay_position' => 'bottom-left',
		);
	}

	/**
	 * Front-end display of the widget.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved widget values.
	 *
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$instance  = wp_parse_args( $instance, $this->get_defaults() );
		$manga_ids = array_filter( array_map( 'absint', (array) $instance['manga_ids'] ) );

		if ( empty( $manga_ids ) ) {
			return;
		}

		$auto_slide = absint( $instance['auto_slide'] );
		$widget_id  = esc_attr( $this->id );
		$overlay_pos = esc_attr( $instance['overlay_position'] );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		?>
		<div class="starter-hero-slider"
			id="hero-slider-<?php echo $widget_id; ?>"
			data-auto-slide="<?php echo $auto_slide; ?>">

			<div class="swiper hero-swiper">
				<div class="swiper-wrapper">
					<?php
					$query = new WP_Query( array(
						'post_type'      => 'manga',
						'post__in'       => $manga_ids,
						'orderby'        => 'post__in',
						'posts_per_page' => self::MAX_SLIDES,
						'post_status'    => 'publish',
						'no_found_rows'  => true,
					) );

					if ( $query->have_posts() ) :
						while ( $query->have_posts() ) :
							$query->the_post();
							$post_id     = get_the_ID();
							$banner_url  = get_post_meta( $post_id, '_starter_banner_image', true );
							$permalink   = esc_url( get_permalink( $post_id ) );
							$title_text  = esc_html( get_the_title( $post_id ) );
							$description = esc_html( wp_trim_words( get_the_excerpt( $post_id ), 25, '&hellip;' ) );
							$rating      = (float) get_post_meta( $post_id, '_starter_rating', true );
							$genres      = wp_get_post_terms( $post_id, 'genre', array( 'fields' => 'names' ) );

							if ( ! $banner_url ) {
								$banner_url = get_the_post_thumbnail_url( $post_id, 'full' );
							}
							?>
							<div class="swiper-slide hero-slide">
								<?php if ( $banner_url ) : ?>
									<div class="hero-slide-bg"
										style="background-image: url('<?php echo esc_url( $banner_url ); ?>');">
									</div>
								<?php else : ?>
									<div class="hero-slide-bg hero-slide-bg--placeholder"></div>
								<?php endif; ?>

								<?php if ( $instance['show_title'] || $instance['show_description'] ) : ?>
									<div class="hero-slide-overlay hero-slide-overlay--<?php echo $overlay_pos; ?>">
										<div class="hero-slide-overlay-inner">
											<?php if ( ! empty( $genres ) && ! is_wp_error( $genres ) ) : ?>
												<div class="hero-slide-genres">
													<?php foreach ( array_slice( $genres, 0, 3 ) as $genre_name ) : ?>
														<span class="hero-slide-genre"><?php echo esc_html( $genre_name ); ?></span>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>

											<?php if ( $instance['show_title'] ) : ?>
												<h2 class="hero-slide-title">
													<a href="<?php echo $permalink; ?>"><?php echo $title_text; ?></a>
												</h2>
											<?php endif; ?>

											<?php if ( $instance['show_description'] && $description ) : ?>
												<p class="hero-slide-desc"><?php echo $description; ?></p>
											<?php endif; ?>

											<?php if ( $rating > 0 ) : ?>
												<div class="hero-slide-rating">
													<span class="hero-slide-stars">&#9733;</span>
													<span><?php echo esc_html( number_format( $rating, 1 ) ); ?></span>
												</div>
											<?php endif; ?>

											<a href="<?php echo $permalink; ?>" class="hero-slide-btn">
												<?php esc_html_e( 'Read Now', 'starter-theme' ); ?>
											</a>
										</div>
									</div>
								<?php endif; ?>
							</div>
							<?php
						endwhile;
						wp_reset_postdata();
					endif;
					?>
				</div>

				<div class="swiper-button-prev hero-prev"></div>
				<div class="swiper-button-next hero-next"></div>
				<div class="swiper-pagination hero-pagination"></div>
			</div>
		</div>

		<style>
			#hero-slider-<?php echo $widget_id; ?> {
				position: relative;
				width: 100%;
				overflow: hidden;
				border-radius: 12px;
				margin-bottom: 24px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide {
				position: relative;
				height: 400px;
				overflow: hidden;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-bg {
				position: absolute;
				inset: 0;
				background-size: cover;
				background-position: center;
				transition: transform 6s ease;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-bg--placeholder {
				background: linear-gradient(135deg, #1a1a2e, #16213e);
			}
			#hero-slider-<?php echo $widget_id; ?> .swiper-slide-active .hero-slide-bg {
				transform: scale(1.05);
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay {
				position: absolute;
				z-index: 2;
				max-width: 500px;
				padding: 20px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay--bottom-left {
				bottom: 30px;
				left: 30px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay--bottom-right {
				bottom: 30px;
				right: 30px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay--center {
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				text-align: center;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay--top-left {
				top: 30px;
				left: 30px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay-inner {
				background: rgba(15, 15, 35, 0.45);
				backdrop-filter: blur(16px);
				-webkit-backdrop-filter: blur(16px);
				border: 1px solid rgba(255, 255, 255, 0.1);
				border-radius: 12px;
				padding: 24px;
				color: #fff;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-genres {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin-bottom: 10px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-genre {
				font-size: 0.7rem;
				padding: 3px 10px;
				background: rgba(255,255,255,0.15);
				border-radius: 20px;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-title {
				font-size: 1.6rem;
				font-weight: 700;
				margin: 0 0 8px;
				line-height: 1.3;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-title a {
				color: #fff;
				text-decoration: none;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-desc {
				font-size: 0.9rem;
				line-height: 1.5;
				opacity: 0.85;
				margin: 0 0 12px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-rating {
				font-size: 0.9rem;
				margin-bottom: 12px;
				display: flex;
				align-items: center;
				gap: 4px;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-stars {
				color: #f1c40f;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-btn {
				display: inline-block;
				padding: 10px 24px;
				background: var(--starter-primary, #6c5ce7);
				color: #fff;
				border-radius: 8px;
				text-decoration: none;
				font-weight: 600;
				font-size: 0.9rem;
				transition: background 0.3s;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-slide-btn:hover {
				background: var(--starter-primary-dark, #5a4bd1);
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-pagination .swiper-pagination-bullet {
				background: rgba(255,255,255,0.5);
				opacity: 1;
			}
			#hero-slider-<?php echo $widget_id; ?> .hero-pagination .swiper-pagination-bullet-active {
				background: var(--starter-primary, #6c5ce7);
				width: 24px;
				border-radius: 4px;
			}
			@media (max-width: 768px) {
				#hero-slider-<?php echo $widget_id; ?> .hero-slide { height: 280px; }
				#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay { max-width: 100%; left: 10px; right: 10px; bottom: 10px; }
				#hero-slider-<?php echo $widget_id; ?> .hero-slide-overlay-inner { padding: 16px; }
				#hero-slider-<?php echo $widget_id; ?> .hero-slide-title { font-size: 1.2rem; }
				#hero-slider-<?php echo $widget_id; ?> .hero-slide-desc { display: none; }
				#hero-slider-<?php echo $widget_id; ?> .swiper-button-prev,
				#hero-slider-<?php echo $widget_id; ?> .swiper-button-next { display: none; }
			}
		</style>

		<script>
		(function() {
			document.addEventListener('DOMContentLoaded', function() {
				var container = document.getElementById('hero-slider-<?php echo $widget_id; ?>');
				if (!container || typeof Swiper === 'undefined') return;

				var autoSlide = parseInt(container.dataset.autoSlide, 10);
				var config = {
					slidesPerView: 1,
					spaceBetween: 0,
					loop: true,
					effect: 'fade',
					fadeEffect: { crossFade: true },
					navigation: {
						nextEl: container.querySelector('.hero-next'),
						prevEl: container.querySelector('.hero-prev'),
					},
					pagination: {
						el: container.querySelector('.hero-pagination'),
						clickable: true,
					},
				};

				if (autoSlide > 0) {
					config.autoplay = { delay: autoSlide, disableOnInteraction: false };
				}

				new Swiper(container.querySelector('.hero-swiper'), config);
			});
		})();
		</script>
		<?php

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Previously saved values from database.
	 *
	 * @return void
	 */
	public function form( $instance ) {
		$instance  = wp_parse_args( $instance, $this->get_defaults() );
		$manga_ids = array_filter( array_map( 'absint', (array) $instance['manga_ids'] ) );

		$all_manga = get_posts( array(
			'post_type'      => 'manga',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$overlay_positions = array(
			'bottom-left'  => __( 'Bottom Left', 'starter-theme' ),
			'bottom-right' => __( 'Bottom Right', 'starter-theme' ),
			'center'       => __( 'Center', 'starter-theme' ),
			'top-left'     => __( 'Top Left', 'starter-theme' ),
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'manga_ids' ) ); ?>">
				<?php esc_html_e( 'Select manga to feature (up to 10):', 'starter-theme' ); ?>
			</label>
			<select multiple="multiple" class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'manga_ids' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'manga_ids' ) ); ?>[]"
				style="height: 180px;">
				<?php foreach ( $all_manga as $manga_post ) : ?>
					<option value="<?php echo absint( $manga_post->ID ); ?>"
						<?php echo in_array( $manga_post->ID, $manga_ids, true ) ? 'selected' : ''; ?>>
						<?php echo esc_html( $manga_post->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<small><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple.', 'starter-theme' ); ?></small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'auto_slide' ) ); ?>">
				<?php esc_html_e( 'Auto-slide interval (ms, 0 = off):', 'starter-theme' ); ?>
			</label>
			<input class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'auto_slide' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'auto_slide' ) ); ?>"
				type="number" min="0" max="30000" step="500"
				value="<?php echo absint( $instance['auto_slide'] ); ?>" />
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_title'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_title' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>">
				<?php esc_html_e( 'Show title overlay', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_description'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_description' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_description' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_description' ) ); ?>">
				<?php esc_html_e( 'Show description overlay', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'overlay_position' ) ); ?>">
				<?php esc_html_e( 'Overlay position:', 'starter-theme' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'overlay_position' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'overlay_position' ) ); ?>">
				<?php foreach ( $overlay_positions as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $instance['overlay_position'], $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Sanitized values.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();

		$manga_ids = isset( $new_instance['manga_ids'] ) ? (array) $new_instance['manga_ids'] : array();
		$instance['manga_ids'] = array_slice( array_filter( array_map( 'absint', $manga_ids ) ), 0, self::MAX_SLIDES );

		$instance['auto_slide']       = absint( $new_instance['auto_slide'] );
		$instance['show_title']       = ! empty( $new_instance['show_title'] );
		$instance['show_description'] = ! empty( $new_instance['show_description'] );

		$valid_positions = array( 'bottom-left', 'bottom-right', 'center', 'top-left' );
		$instance['overlay_position'] = in_array( $new_instance['overlay_position'], $valid_positions, true )
			? $new_instance['overlay_position']
			: 'bottom-left';

		return $instance;
	}
}
