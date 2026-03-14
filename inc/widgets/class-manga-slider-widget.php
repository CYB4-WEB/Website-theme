<?php
/**
 * Manga Slider Widget.
 *
 * Displays a responsive carousel/slider of manga covers using Swiper.js.
 * Supports AJAX lazy-loading of thumbnails and multiple ordering options.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Slider_Widget
 */
class Starter_Manga_Slider_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'starter_manga_slider',
			esc_html__( 'Manga Slider', 'starter-theme' ),
			array(
				'description'                 => esc_html__( 'Displays a carousel/slider of manga covers.', 'starter-theme' ),
				'customize_selective_refresh' => true,
			)
		);

		add_action( 'wp_ajax_starter_manga_slider_load', array( $this, 'ajax_load_thumbnails' ) );
		add_action( 'wp_ajax_nopriv_starter_manga_slider_load', array( $this, 'ajax_load_thumbnails' ) );
	}

	/**
	 * Default widget options.
	 *
	 * @return array
	 */
	private function get_defaults() {
		return array(
			'title'          => esc_html__( 'Manga Slider', 'starter-theme' ),
			'count'          => 10,
			'order_by'       => 'latest',
			'show_rating'    => true,
			'show_badge'     => true,
			'show_type'      => true,
			'auto_slide'     => 5000,
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
		$instance = wp_parse_args( $instance, $this->get_defaults() );

		$title = ! empty( $instance['title'] )
			? apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base )
			: '';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$widget_id  = esc_attr( $this->id );
		$auto_slide = absint( $instance['auto_slide'] );

		?>
		<div class="starter-manga-slider"
			id="manga-slider-<?php echo $widget_id; ?>"
			data-widget-id="<?php echo $widget_id; ?>"
			data-count="<?php echo absint( $instance['count'] ); ?>"
			data-order="<?php echo esc_attr( $instance['order_by'] ); ?>"
			data-show-rating="<?php echo $instance['show_rating'] ? '1' : '0'; ?>"
			data-show-badge="<?php echo $instance['show_badge'] ? '1' : '0'; ?>"
			data-show-type="<?php echo $instance['show_type'] ? '1' : '0'; ?>"
			data-auto-slide="<?php echo $auto_slide; ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_manga_slider_nonce' ) ); ?>">

			<div class="swiper">
				<div class="swiper-wrapper">
					<?php $this->render_slides( $instance ); ?>
				</div>
				<div class="swiper-button-prev"></div>
				<div class="swiper-button-next"></div>
				<div class="swiper-pagination"></div>
			</div>
		</div>

		<style>
			#manga-slider-<?php echo $widget_id; ?> .swiper-slide {
				width: 180px;
				flex-shrink: 0;
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-item {
				position: relative;
				border-radius: 8px;
				overflow: hidden;
				background: var(--starter-card-bg, #1a1a2e);
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-thumb {
				width: 100%;
				aspect-ratio: 2/3;
				object-fit: cover;
				display: block;
				transition: transform 0.3s ease;
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-item:hover .manga-slide-thumb {
				transform: scale(1.05);
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-info {
				padding: 8px;
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-title {
				font-size: 0.85rem;
				font-weight: 600;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				color: var(--starter-text, #fff);
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-badge {
				position: absolute;
				top: 6px;
				left: 6px;
				font-size: 0.7rem;
				font-weight: 700;
				padding: 2px 8px;
				border-radius: 4px;
				text-transform: uppercase;
				color: #fff;
			}
			#manga-slider-<?php echo $widget_id; ?> .badge-new { background: #e74c3c; }
			#manga-slider-<?php echo $widget_id; ?> .badge-hot { background: #f39c12; }
			#manga-slider-<?php echo $widget_id; ?> .badge-completed { background: #27ae60; }
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-type {
				position: absolute;
				top: 6px;
				right: 6px;
				font-size: 0.65rem;
				padding: 2px 6px;
				border-radius: 3px;
				background: rgba(0,0,0,0.7);
				color: #fff;
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-rating {
				display: flex;
				align-items: center;
				gap: 4px;
				font-size: 0.75rem;
				color: #f1c40f;
				margin-top: 4px;
			}
			#manga-slider-<?php echo $widget_id; ?> .manga-slide-placeholder {
				width: 100%;
				aspect-ratio: 2/3;
				background: linear-gradient(135deg, #2a2a3e 25%, #33334d 50%, #2a2a3e 75%);
				background-size: 200% 200%;
				animation: starter-shimmer 1.5s infinite;
			}
			@keyframes starter-shimmer {
				0% { background-position: 200% 0; }
				100% { background-position: -200% 0; }
			}
			@media (max-width: 480px) {
				#manga-slider-<?php echo $widget_id; ?> .swiper-slide { width: 140px; }
			}
		</style>

		<script>
		(function() {
			document.addEventListener('DOMContentLoaded', function() {
				var container = document.getElementById('manga-slider-<?php echo $widget_id; ?>');
				if (!container) return;

				var autoSlide = parseInt(container.dataset.autoSlide, 10);
				var swiperEl  = container.querySelector('.swiper');

				var swiperConfig = {
					slidesPerView: 2,
					spaceBetween: 12,
					navigation: {
						nextEl: container.querySelector('.swiper-button-next'),
						prevEl: container.querySelector('.swiper-button-prev'),
					},
					pagination: {
						el: container.querySelector('.swiper-pagination'),
						clickable: true,
					},
					breakpoints: {
						480:  { slidesPerView: 3, spaceBetween: 12 },
						768:  { slidesPerView: 4, spaceBetween: 16 },
						1024: { slidesPerView: 5, spaceBetween: 16 },
					},
				};

				if (autoSlide > 0) {
					swiperConfig.autoplay = { delay: autoSlide, disableOnInteraction: false };
				}

				if (typeof Swiper !== 'undefined') {
					new Swiper(swiperEl, swiperConfig);
				}

				/* AJAX lazy-load thumbnails */
				var lazyImages = container.querySelectorAll('[data-lazy-src]');
				if (lazyImages.length > 0) {
					var observer = new IntersectionObserver(function(entries) {
						entries.forEach(function(entry) {
							if (entry.isIntersecting) {
								var img = entry.target;
								img.src = img.dataset.lazySrc;
								img.removeAttribute('data-lazy-src');
								img.classList.add('loaded');
								observer.unobserve(img);
							}
						});
					}, { rootMargin: '100px' });

					lazyImages.forEach(function(img) {
						observer.observe(img);
					});
				}
			});
		})();
		</script>
		<?php

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render individual slides.
	 *
	 * @param array $instance Widget instance settings.
	 *
	 * @return void
	 */
	private function render_slides( $instance ) {
		$query_args = $this->build_query_args( $instance );
		$manga_query = new WP_Query( $query_args );

		if ( ! $manga_query->have_posts() ) {
			echo '<div class="swiper-slide"><p>' . esc_html__( 'No manga found.', 'starter-theme' ) . '</p></div>';
			return;
		}

		while ( $manga_query->have_posts() ) {
			$manga_query->the_post();

			$post_id   = get_the_ID();
			$permalink = esc_url( get_permalink( $post_id ) );
			$title_text = esc_attr( get_the_title( $post_id ) );
			$thumb_url = get_the_post_thumbnail_url( $post_id, 'medium' );
			$rating    = (float) get_post_meta( $post_id, '_starter_rating', true );
			$badge     = sanitize_text_field( get_post_meta( $post_id, '_starter_badge', true ) );
			$type      = sanitize_text_field( get_post_meta( $post_id, '_starter_type', true ) );

			?>
			<div class="swiper-slide">
				<a href="<?php echo $permalink; ?>" class="manga-slide-item" title="<?php echo $title_text; ?>">
					<?php if ( $thumb_url ) : ?>
						<img class="manga-slide-thumb"
							data-lazy-src="<?php echo esc_url( $thumb_url ); ?>"
							src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='270'%3E%3C/svg%3E"
							alt="<?php echo $title_text; ?>"
							loading="lazy" />
					<?php else : ?>
						<div class="manga-slide-placeholder"></div>
					<?php endif; ?>

					<?php if ( $instance['show_badge'] && $badge ) : ?>
						<span class="manga-slide-badge badge-<?php echo esc_attr( $badge ); ?>">
							<?php echo esc_html( $badge ); ?>
						</span>
					<?php endif; ?>

					<?php if ( $instance['show_type'] && $type ) : ?>
						<span class="manga-slide-type"><?php echo esc_html( ucfirst( $type ) ); ?></span>
					<?php endif; ?>

					<div class="manga-slide-info">
						<div class="manga-slide-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></div>
						<?php if ( $instance['show_rating'] && $rating > 0 ) : ?>
							<div class="manga-slide-rating">
								<span>&#9733;</span>
								<span><?php echo esc_html( number_format( $rating, 1 ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</a>
			</div>
			<?php
		}

		wp_reset_postdata();
	}

	/**
	 * Build WP_Query args based on widget settings.
	 *
	 * @param array $instance Widget instance settings.
	 *
	 * @return array
	 */
	private function build_query_args( $instance ) {
		$args = array(
			'post_type'      => 'manga',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $instance['count'] ),
			'no_found_rows'  => true,
		);

		switch ( $instance['order_by'] ) {
			case 'popular':
				$args['meta_key'] = '_starter_views';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;

			case 'rating':
				$args['meta_key'] = '_starter_rating';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;

			case 'random':
				$args['orderby'] = 'rand';
				break;

			case 'latest':
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
		}

		return $args;
	}

	/**
	 * AJAX handler for lazy-loading thumbnails.
	 *
	 * @return void
	 */
	public function ajax_load_thumbnails() {
		check_ajax_referer( 'starter_manga_slider_nonce', 'nonce' );

		$count    = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 10;
		$order_by = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'latest';
		$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$count = max( 3, min( 20, $count ) );

		$instance = array(
			'count'       => $count,
			'order_by'    => $order_by,
			'show_rating' => ! empty( $_POST['show_rating'] ),
			'show_badge'  => ! empty( $_POST['show_badge'] ),
			'show_type'   => ! empty( $_POST['show_type'] ),
		);

		$query_args           = $this->build_query_args( $instance );
		$query_args['offset'] = $offset;

		$manga_query = new WP_Query( $query_args );
		$slides      = array();

		if ( $manga_query->have_posts() ) {
			while ( $manga_query->have_posts() ) {
				$manga_query->the_post();
				$post_id = get_the_ID();

				$slides[] = array(
					'id'        => $post_id,
					'title'     => esc_html( get_the_title( $post_id ) ),
					'permalink' => esc_url( get_permalink( $post_id ) ),
					'thumbnail' => esc_url( get_the_post_thumbnail_url( $post_id, 'medium' ) ),
					'rating'    => (float) get_post_meta( $post_id, '_starter_rating', true ),
					'badge'     => sanitize_text_field( get_post_meta( $post_id, '_starter_badge', true ) ),
					'type'      => sanitize_text_field( get_post_meta( $post_id, '_starter_type', true ) ),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( $slides );
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Previously saved values from database.
	 *
	 * @return void
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( $instance, $this->get_defaults() );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'starter-theme' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>">
				<?php esc_html_e( 'Number of items (3-20):', 'starter-theme' ); ?>
			</label>
			<input class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>"
				type="number"
				min="3" max="20" step="1"
				value="<?php echo absint( $instance['count'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'order_by' ) ); ?>">
				<?php esc_html_e( 'Order by:', 'starter-theme' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'order_by' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'order_by' ) ); ?>">
				<option value="latest" <?php selected( $instance['order_by'], 'latest' ); ?>>
					<?php esc_html_e( 'Latest', 'starter-theme' ); ?>
				</option>
				<option value="popular" <?php selected( $instance['order_by'], 'popular' ); ?>>
					<?php esc_html_e( 'Popular', 'starter-theme' ); ?>
				</option>
				<option value="rating" <?php selected( $instance['order_by'], 'rating' ); ?>>
					<?php esc_html_e( 'Rating', 'starter-theme' ); ?>
				</option>
				<option value="random" <?php selected( $instance['order_by'], 'random' ); ?>>
					<?php esc_html_e( 'Random', 'starter-theme' ); ?>
				</option>
			</select>
		</p>
		<p>
			<input class="checkbox"
				type="checkbox"
				<?php checked( $instance['show_rating'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_rating' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_rating' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_rating' ) ); ?>">
				<?php esc_html_e( 'Show rating', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox"
				type="checkbox"
				<?php checked( $instance['show_badge'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_badge' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_badge' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_badge' ) ); ?>">
				<?php esc_html_e( 'Show badge', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox"
				type="checkbox"
				<?php checked( $instance['show_type'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_type' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_type' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_type' ) ); ?>">
				<?php esc_html_e( 'Show type', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'auto_slide' ) ); ?>">
				<?php esc_html_e( 'Auto-slide interval (ms, 0 = off):', 'starter-theme' ); ?>
			</label>
			<input class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'auto_slide' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'auto_slide' ) ); ?>"
				type="number"
				min="0" max="30000" step="500"
				value="<?php echo absint( $instance['auto_slide'] ); ?>" />
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

		$instance['title']       = sanitize_text_field( $new_instance['title'] );
		$instance['count']       = max( 3, min( 20, absint( $new_instance['count'] ) ) );
		$instance['order_by']    = in_array( $new_instance['order_by'], array( 'latest', 'popular', 'rating', 'random' ), true )
			? $new_instance['order_by']
			: 'latest';
		$instance['show_rating'] = ! empty( $new_instance['show_rating'] );
		$instance['show_badge']  = ! empty( $new_instance['show_badge'] );
		$instance['show_type']   = ! empty( $new_instance['show_type'] );
		$instance['auto_slide']  = absint( $new_instance['auto_slide'] );

		return $instance;
	}
}
