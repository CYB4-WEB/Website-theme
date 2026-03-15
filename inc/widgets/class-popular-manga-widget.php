<?php
/**
 * Popular Manga Widget.
 *
 * Displays a ranked list of popular manga filtered by time period
 * with support for list and grid layouts.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Popular_Manga_Widget
 */
class Starter_Popular_Manga_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'starter_popular_manga',
			esc_html__( 'Popular Manga', 'starter-theme' ),
			array(
				'description'                 => esc_html__( 'Displays popular manga ranked by views.', 'starter-theme' ),
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
			'title'              => esc_html__( 'Popular Manga', 'starter-theme' ),
			'count'              => 10,
			'time_period'        => 'weekly',
			'show_thumbnail'     => true,
			'show_chapter_count' => true,
			'show_rating'        => true,
			'show_views'         => true,
			'layout'             => 'list',
		);
	}

	/**
	 * Valid time period values.
	 *
	 * @return array
	 */
	private function get_time_periods() {
		return array(
			'daily'    => __( 'Daily', 'starter-theme' ),
			'weekly'   => __( 'Weekly', 'starter-theme' ),
			'monthly'  => __( 'Monthly', 'starter-theme' ),
			'all_time' => __( 'All Time', 'starter-theme' ),
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

		$widget_id = esc_attr( $this->id );
		$layout    = esc_attr( $instance['layout'] );

		/* Time period tabs */
		$periods    = $this->get_time_periods();
		$active_tab = $instance['time_period'];
		?>
		<div class="starter-popular-manga"
			id="popular-manga-<?php echo $widget_id; ?>"
			data-layout="<?php echo $layout; ?>">

			<div class="popular-manga-tabs" role="tablist">
				<?php foreach ( $periods as $key => $label ) : ?>
					<button class="popular-manga-tab <?php echo $key === $active_tab ? 'active' : ''; ?>"
						role="tab"
						data-period="<?php echo esc_attr( $key ); ?>"
						aria-selected="<?php echo $key === $active_tab ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="popular-manga-<?php echo $layout; ?>">
				<?php $this->render_manga_list( $instance ); ?>
			</div>
		</div>

		<style>
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-tabs {
				display: flex;
				gap: 4px;
				margin-bottom: 16px;
				overflow-x: auto;
				scrollbar-width: none;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-tabs::-webkit-scrollbar {
				display: none;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-tab {
				padding: 6px 14px;
				border: none;
				border-radius: 20px;
				background: var(--starter-card-bg, #1a1a2e);
				color: var(--starter-text-muted, #8a8a9e);
				font-size: 0.8rem;
				font-weight: 600;
				cursor: pointer;
				white-space: nowrap;
				transition: all 0.2s;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-tab.active,
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-tab:hover {
				background: var(--starter-primary, #6c5ce7);
				color: #fff;
			}

			/* List layout */
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-list {
				display: flex;
				flex-direction: column;
				gap: 12px;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-item {
				display: flex;
				align-items: center;
				gap: 12px;
				text-decoration: none;
				color: inherit;
				padding: 8px;
				border-radius: 8px;
				transition: background 0.2s;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-item:hover {
				background: var(--starter-card-bg, rgba(255,255,255,0.05));
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-rank {
				flex-shrink: 0;
				width: 28px;
				height: 28px;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 0.85rem;
				font-weight: 700;
				border-radius: 6px;
				background: var(--starter-card-bg, #2a2a3e);
				color: var(--starter-text-muted, #8a8a9e);
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-item:nth-child(1) .popular-manga-rank {
				background: #f1c40f;
				color: #000;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-item:nth-child(2) .popular-manga-rank {
				background: #bdc3c7;
				color: #000;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-item:nth-child(3) .popular-manga-rank {
				background: #cd6133;
				color: #fff;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-thumb {
				flex-shrink: 0;
				width: 48px;
				height: 64px;
				border-radius: 6px;
				object-fit: cover;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-info {
				flex: 1;
				min-width: 0;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-name {
				font-size: 0.9rem;
				font-weight: 600;
				color: var(--starter-text, #fff);
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-meta {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				font-size: 0.75rem;
				color: var(--starter-text-muted, #8a8a9e);
				margin-top: 4px;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-meta span {
				display: flex;
				align-items: center;
				gap: 3px;
			}
			#popular-manga-<?php echo $widget_id; ?> .meta-rating { color: #f1c40f; }

			/* Grid layout */
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-grid {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 12px;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-grid .popular-manga-item {
				flex-direction: column;
				text-align: center;
				padding: 12px 8px;
				background: var(--starter-card-bg, #1a1a2e);
				border-radius: 8px;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-grid .popular-manga-rank {
				position: absolute;
				top: 8px;
				left: 8px;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-grid .popular-manga-item {
				position: relative;
			}
			#popular-manga-<?php echo $widget_id; ?> .popular-manga-grid .popular-manga-thumb {
				width: 100%;
				height: auto;
				aspect-ratio: 2/3;
			}
		</style>

		<script>
		(function() {
			document.addEventListener('DOMContentLoaded', function() {
				var container = document.getElementById('popular-manga-<?php echo $widget_id; ?>');
				if (!container) return;

				var tabs = container.querySelectorAll('.popular-manga-tab');
				tabs.forEach(function(tab) {
					tab.addEventListener('click', function() {
						tabs.forEach(function(t) {
							t.classList.remove('active');
							t.setAttribute('aria-selected', 'false');
						});
						this.classList.add('active');
						this.setAttribute('aria-selected', 'true');

						/* AJAX reload for tab switching */
						var period = this.dataset.period;
						var listEl = container.querySelector('[class^="popular-manga-list"], [class^="popular-manga-grid"]');
						if (!listEl) return;

						listEl.style.opacity = '0.5';

						var formData = new FormData();
						formData.append('action', 'starter_popular_manga_tab');
						formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'starter_popular_tab_nonce' ) ); ?>');
						formData.append('period', period);
						formData.append('widget_id', '<?php echo $widget_id; ?>');
						formData.append('count', '<?php echo absint( $instance['count'] ); ?>');
						formData.append('show_thumbnail', '<?php echo $instance['show_thumbnail'] ? '1' : '0'; ?>');
						formData.append('show_chapter_count', '<?php echo $instance['show_chapter_count'] ? '1' : '0'; ?>');
						formData.append('show_rating', '<?php echo $instance['show_rating'] ? '1' : '0'; ?>');
						formData.append('show_views', '<?php echo $instance['show_views'] ? '1' : '0'; ?>');
						formData.append('layout', '<?php echo esc_js( $layout ); ?>');

						fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
							method: 'POST',
							body: formData,
						})
						.then(function(res) { return res.json(); })
						.then(function(data) {
							if (data.success) {
								listEl.innerHTML = data.data;
							}
							listEl.style.opacity = '1';
						})
						.catch(function() {
							listEl.style.opacity = '1';
						});
					});
				});
			});
		})();
		</script>
		<?php

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the manga list/grid items.
	 *
	 * @param array $instance Widget instance settings.
	 *
	 * @return void
	 */
	private function render_manga_list( $instance ) {
		$meta_key = $this->get_views_meta_key( $instance['time_period'] );

		$query_args = array(
			'post_type'      => 'manga',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $instance['count'] ),
			'meta_key'       => $meta_key,
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $query_args );
		$rank  = 1;

		if ( ! $query->have_posts() ) {
			echo '<p class="popular-manga-empty">' . esc_html__( 'No manga found.', 'starter-theme' ) . '</p>';
			return;
		}

		while ( $query->have_posts() ) {
			$query->the_post();

			$post_id       = get_the_ID();
			$permalink     = esc_url( get_permalink( $post_id ) );
			$title_text    = esc_html( get_the_title( $post_id ) );
			$thumb_url     = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
			$rating        = (float) get_post_meta( $post_id, '_starter_rating', true );
			$views         = absint( get_post_meta( $post_id, $meta_key, true ) );
			$chapter_count = absint( get_post_meta( $post_id, '_starter_chapter_count', true ) );

			?>
			<a href="<?php echo $permalink; ?>" class="popular-manga-item">
				<span class="popular-manga-rank"><?php echo absint( $rank ); ?></span>

				<?php if ( $instance['show_thumbnail'] && $thumb_url ) : ?>
					<img class="popular-manga-thumb"
						src="<?php echo esc_url( $thumb_url ); ?>"
						alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>"
						loading="lazy" />
				<?php endif; ?>

				<div class="popular-manga-info">
					<div class="popular-manga-name"><?php echo $title_text; ?></div>
					<div class="popular-manga-meta">
						<?php if ( $instance['show_rating'] && $rating > 0 ) : ?>
							<span class="meta-rating">&#9733; <?php echo esc_html( number_format( $rating, 1 ) ); ?></span>
						<?php endif; ?>
						<?php if ( $instance['show_chapter_count'] && $chapter_count > 0 ) : ?>
							<span>
								<?php
								/* translators: %d: chapter count */
								printf( esc_html__( '%d Ch.', 'starter-theme' ), $chapter_count );
								?>
							</span>
						<?php endif; ?>
						<?php if ( $instance['show_views'] ) : ?>
							<span><?php echo esc_html( $this->format_views( $views ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</a>
			<?php
			++$rank;
		}

		wp_reset_postdata();
	}

	/**
	 * Get the meta key for views based on time period.
	 *
	 * @param string $period Time period key.
	 *
	 * @return string
	 */
	private function get_views_meta_key( $period ) {
		$keys = array(
			'daily'    => '_starter_views_daily',
			'weekly'   => '_starter_views_weekly',
			'monthly'  => '_starter_views_monthly',
			'all_time' => '_starter_views',
		);

		return isset( $keys[ $period ] ) ? $keys[ $period ] : '_starter_views';
	}

	/**
	 * Format view count for display.
	 *
	 * @param int $views View count.
	 *
	 * @return string
	 */
	private function format_views( $views ) {
		if ( $views >= 1000000 ) {
			return number_format( $views / 1000000, 1 ) . 'M';
		}
		if ( $views >= 1000 ) {
			return number_format( $views / 1000, 1 ) . 'K';
		}
		return number_format( $views );
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
		$periods  = $this->get_time_periods();
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
				<?php esc_html_e( 'Number of items:', 'starter-theme' ); ?>
			</label>
			<input class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>"
				type="number" min="1" max="50" step="1"
				value="<?php echo absint( $instance['count'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'time_period' ) ); ?>">
				<?php esc_html_e( 'Default time period:', 'starter-theme' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'time_period' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'time_period' ) ); ?>">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"
						<?php selected( $instance['time_period'], $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>">
				<?php esc_html_e( 'Layout:', 'starter-theme' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>">
				<option value="list" <?php selected( $instance['layout'], 'list' ); ?>>
					<?php esc_html_e( 'List', 'starter-theme' ); ?>
				</option>
				<option value="grid" <?php selected( $instance['layout'], 'grid' ); ?>>
					<?php esc_html_e( 'Grid', 'starter-theme' ); ?>
				</option>
			</select>
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_thumbnail'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_thumbnail' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_thumbnail' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_thumbnail' ) ); ?>">
				<?php esc_html_e( 'Show thumbnail', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_chapter_count'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_chapter_count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_chapter_count' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_chapter_count' ) ); ?>">
				<?php esc_html_e( 'Show chapter count', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_rating'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_rating' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_rating' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_rating' ) ); ?>">
				<?php esc_html_e( 'Show rating', 'starter-theme' ); ?>
			</label>
		</p>
		<p>
			<input class="checkbox" type="checkbox"
				<?php checked( $instance['show_views'] ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_views' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_views' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_views' ) ); ?>">
				<?php esc_html_e( 'Show views', 'starter-theme' ); ?>
			</label>
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

		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['count'] = max( 1, min( 50, absint( $new_instance['count'] ) ) );

		$valid_periods = array_keys( $this->get_time_periods() );
		$instance['time_period'] = in_array( $new_instance['time_period'], $valid_periods, true )
			? $new_instance['time_period']
			: 'weekly';

		$instance['layout'] = in_array( $new_instance['layout'], array( 'list', 'grid' ), true )
			? $new_instance['layout']
			: 'list';

		$instance['show_thumbnail']     = ! empty( $new_instance['show_thumbnail'] );
		$instance['show_chapter_count'] = ! empty( $new_instance['show_chapter_count'] );
		$instance['show_rating']        = ! empty( $new_instance['show_rating'] );
		$instance['show_views']         = ! empty( $new_instance['show_views'] );

		return $instance;
	}
}
