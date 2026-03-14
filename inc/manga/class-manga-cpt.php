<?php
/**
 * Manga Custom Post Type Registration.
 *
 * Registers the wp-manga CPT, taxonomies, meta boxes, and admin columns.
 *
 * @package suspended starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_CPT
 *
 * Handles registration of the wp-manga custom post type,
 * associated taxonomies, meta boxes, and admin list columns.
 *
 * @since 1.0.0
 */
class Starter_Manga_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'wp-manga';

	/**
	 * Meta key prefix.
	 *
	 * @var string
	 */
	const META_PREFIX = '_starter_manga_';

	/**
	 * Nonce action for meta box saving.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_manga_meta_save';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'starter_manga_meta_nonce';

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_CPT|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_CPT
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Hook into WordPress.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'custom_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'custom_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
	}

	/**
	 * Register the wp-manga custom post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => esc_html__( 'Manga', 'starter' ),
			'singular_name'         => esc_html__( 'Manga', 'starter' ),
			'add_new'               => esc_html__( 'Add New', 'starter' ),
			'add_new_item'          => esc_html__( 'Add New Manga', 'starter' ),
			'edit_item'             => esc_html__( 'Edit Manga', 'starter' ),
			'new_item'              => esc_html__( 'New Manga', 'starter' ),
			'view_item'             => esc_html__( 'View Manga', 'starter' ),
			'view_items'            => esc_html__( 'View Manga', 'starter' ),
			'search_items'          => esc_html__( 'Search Manga', 'starter' ),
			'not_found'             => esc_html__( 'No manga found.', 'starter' ),
			'not_found_in_trash'    => esc_html__( 'No manga found in Trash.', 'starter' ),
			'all_items'             => esc_html__( 'All Manga', 'starter' ),
			'archives'              => esc_html__( 'Manga Archives', 'starter' ),
			'attributes'            => esc_html__( 'Manga Attributes', 'starter' ),
			'insert_into_item'      => esc_html__( 'Insert into manga', 'starter' ),
			'uploaded_to_this_item' => esc_html__( 'Uploaded to this manga', 'starter' ),
			'featured_image'        => esc_html__( 'Cover Image', 'starter' ),
			'set_featured_image'    => esc_html__( 'Set cover image', 'starter' ),
			'remove_featured_image' => esc_html__( 'Remove cover image', 'starter' ),
			'use_featured_image'    => esc_html__( 'Use as cover image', 'starter' ),
			'menu_name'             => esc_html__( 'Manga', 'starter' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'query_var'           => true,
			'rewrite'             => array(
				'slug'       => 'manga',
				'with_front' => false,
			),
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-book-alt',
			'supports'            => array(
				'title',
				'editor',
				'thumbnail',
				'comments',
				'excerpt',
				'author',
			),
			'taxonomies'          => array(),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomies for the manga post type.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		// Genre taxonomy.
		register_taxonomy( 'wp-manga-genre', self::POST_TYPE, array(
			'labels'            => array(
				'name'              => esc_html__( 'Genres', 'starter' ),
				'singular_name'     => esc_html__( 'Genre', 'starter' ),
				'search_items'      => esc_html__( 'Search Genres', 'starter' ),
				'all_items'         => esc_html__( 'All Genres', 'starter' ),
				'parent_item'       => esc_html__( 'Parent Genre', 'starter' ),
				'parent_item_colon' => esc_html__( 'Parent Genre:', 'starter' ),
				'edit_item'         => esc_html__( 'Edit Genre', 'starter' ),
				'update_item'       => esc_html__( 'Update Genre', 'starter' ),
				'add_new_item'      => esc_html__( 'Add New Genre', 'starter' ),
				'new_item_name'     => esc_html__( 'New Genre Name', 'starter' ),
				'menu_name'         => esc_html__( 'Genres', 'starter' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'manga-genre' ),
		) );

		// Tag taxonomy.
		register_taxonomy( 'wp-manga-tag', self::POST_TYPE, array(
			'labels'            => array(
				'name'                       => esc_html__( 'Tags', 'starter' ),
				'singular_name'              => esc_html__( 'Tag', 'starter' ),
				'search_items'               => esc_html__( 'Search Tags', 'starter' ),
				'popular_items'              => esc_html__( 'Popular Tags', 'starter' ),
				'all_items'                  => esc_html__( 'All Tags', 'starter' ),
				'edit_item'                  => esc_html__( 'Edit Tag', 'starter' ),
				'update_item'                => esc_html__( 'Update Tag', 'starter' ),
				'add_new_item'               => esc_html__( 'Add New Tag', 'starter' ),
				'new_item_name'              => esc_html__( 'New Tag Name', 'starter' ),
				'separate_items_with_commas' => esc_html__( 'Separate tags with commas', 'starter' ),
				'add_or_remove_items'        => esc_html__( 'Add or remove tags', 'starter' ),
				'choose_from_most_used'      => esc_html__( 'Choose from the most used tags', 'starter' ),
				'not_found'                  => esc_html__( 'No tags found.', 'starter' ),
				'menu_name'                  => esc_html__( 'Tags', 'starter' ),
			),
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'manga-tag' ),
		) );

		// Type taxonomy (manga, novel, video, comic, drama).
		register_taxonomy( 'wp-manga-type', self::POST_TYPE, array(
			'labels'            => array(
				'name'              => esc_html__( 'Types', 'starter' ),
				'singular_name'     => esc_html__( 'Type', 'starter' ),
				'search_items'      => esc_html__( 'Search Types', 'starter' ),
				'all_items'         => esc_html__( 'All Types', 'starter' ),
				'edit_item'         => esc_html__( 'Edit Type', 'starter' ),
				'update_item'       => esc_html__( 'Update Type', 'starter' ),
				'add_new_item'      => esc_html__( 'Add New Type', 'starter' ),
				'new_item_name'     => esc_html__( 'New Type Name', 'starter' ),
				'menu_name'         => esc_html__( 'Types', 'starter' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'manga-type' ),
		) );

		// Release taxonomy.
		register_taxonomy( 'wp-manga-release', self::POST_TYPE, array(
			'labels'            => array(
				'name'              => esc_html__( 'Release Years', 'starter' ),
				'singular_name'     => esc_html__( 'Release Year', 'starter' ),
				'search_items'      => esc_html__( 'Search Release Years', 'starter' ),
				'all_items'         => esc_html__( 'All Release Years', 'starter' ),
				'edit_item'         => esc_html__( 'Edit Release Year', 'starter' ),
				'update_item'       => esc_html__( 'Update Release Year', 'starter' ),
				'add_new_item'      => esc_html__( 'Add New Release Year', 'starter' ),
				'new_item_name'     => esc_html__( 'New Release Year', 'starter' ),
				'menu_name'         => esc_html__( 'Release Years', 'starter' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'manga-release' ),
		) );

		// Insert default types if they don't exist.
		if ( ! term_exists( 'manga', 'wp-manga-type' ) ) {
			$default_types = array( 'Manga', 'Novel', 'Video', 'Comic', 'Drama' );
			foreach ( $default_types as $type ) {
				wp_insert_term( $type, 'wp-manga-type', array(
					'slug' => sanitize_title( $type ),
				) );
			}
		}
	}

	/**
	 * Add meta boxes for the manga post type.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'starter_manga_details',
			esc_html__( 'Manga Details', 'starter' ),
			array( $this, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'starter_manga_badge',
			esc_html__( 'Badge & Flags', 'starter' ),
			array( $this, 'render_badge_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the manga details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$alt_names     = get_post_meta( $post->ID, self::META_PREFIX . 'alt_names', true );
		$status        = get_post_meta( $post->ID, self::META_PREFIX . 'status', true );
		$author_name   = get_post_meta( $post->ID, self::META_PREFIX . 'author_name', true );
		$artist_name   = get_post_meta( $post->ID, self::META_PREFIX . 'artist_name', true );
		$serialization = get_post_meta( $post->ID, self::META_PREFIX . 'serialization', true );

		$statuses = array(
			''          => esc_html__( '-- Select Status --', 'starter' ),
			'ongoing'   => esc_html__( 'Ongoing', 'starter' ),
			'completed' => esc_html__( 'Completed', 'starter' ),
			'hiatus'    => esc_html__( 'Hiatus', 'starter' ),
			'cancelled' => esc_html__( 'Cancelled', 'starter' ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="starter_manga_alt_names"><?php esc_html_e( 'Alternative Names', 'starter' ); ?></label></th>
				<td>
					<textarea id="starter_manga_alt_names" name="starter_manga_alt_names" rows="3" class="large-text"><?php echo esc_textarea( $alt_names ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Separate multiple names with commas.', 'starter' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="starter_manga_status"><?php esc_html_e( 'Status', 'starter' ); ?></label></th>
				<td>
					<select id="starter_manga_status" name="starter_manga_status">
						<?php foreach ( $statuses as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="starter_manga_author_name"><?php esc_html_e( 'Author Name', 'starter' ); ?></label></th>
				<td>
					<input type="text" id="starter_manga_author_name" name="starter_manga_author_name" value="<?php echo esc_attr( $author_name ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="starter_manga_artist_name"><?php esc_html_e( 'Artist Name', 'starter' ); ?></label></th>
				<td>
					<input type="text" id="starter_manga_artist_name" name="starter_manga_artist_name" value="<?php echo esc_attr( $artist_name ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="starter_manga_serialization"><?php esc_html_e( 'Serialization', 'starter' ); ?></label></th>
				<td>
					<input type="text" id="starter_manga_serialization" name="starter_manga_serialization" value="<?php echo esc_attr( $serialization ); ?>" class="regular-text" />
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the badge and flags meta box.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_badge_meta_box( $post ) {
		$adult_content = get_post_meta( $post->ID, self::META_PREFIX . 'adult_content', true );
		$badge         = get_post_meta( $post->ID, self::META_PREFIX . 'badge', true );

		$badges = array(
			''         => esc_html__( 'None', 'starter' ),
			'hot'      => esc_html__( 'Hot', 'starter' ),
			'new'      => esc_html__( 'New', 'starter' ),
			'trending' => esc_html__( 'Trending', 'starter' ),
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="starter_manga_adult_content" value="1" <?php checked( $adult_content, '1' ); ?> />
				<?php esc_html_e( 'Adult Content (18+)', 'starter' ); ?>
			</label>
		</p>
		<p>
			<label for="starter_manga_badge"><?php esc_html_e( 'Badge:', 'starter' ); ?></label><br/>
			<select id="starter_manga_badge" name="starter_manga_badge" style="width:100%;">
				<?php foreach ( $badges as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $badge, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type.
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Sanitize and save text fields.
		$text_fields = array(
			'alt_names'     => 'starter_manga_alt_names',
			'author_name'   => 'starter_manga_author_name',
			'artist_name'   => 'starter_manga_artist_name',
			'serialization' => 'starter_manga_serialization',
		);

		foreach ( $text_fields as $meta_key => $field_name ) {
			if ( isset( $_POST[ $field_name ] ) ) {
				update_post_meta(
					$post_id,
					self::META_PREFIX . $meta_key,
					sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) )
				);
			}
		}

		// Save status.
		if ( isset( $_POST['starter_manga_status'] ) ) {
			$allowed_statuses = array( '', 'ongoing', 'completed', 'hiatus', 'cancelled' );
			$status           = sanitize_text_field( wp_unslash( $_POST['starter_manga_status'] ) );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				update_post_meta( $post_id, self::META_PREFIX . 'status', $status );
			}
		}

		// Save badge.
		if ( isset( $_POST['starter_manga_badge'] ) ) {
			$allowed_badges = array( '', 'hot', 'new', 'trending' );
			$badge          = sanitize_text_field( wp_unslash( $_POST['starter_manga_badge'] ) );
			if ( in_array( $badge, $allowed_badges, true ) ) {
				update_post_meta( $post_id, self::META_PREFIX . 'badge', $badge );
			}
		}

		// Save adult content flag.
		$adult_content = isset( $_POST['starter_manga_adult_content'] ) ? '1' : '0';
		update_post_meta( $post_id, self::META_PREFIX . 'adult_content', $adult_content );
	}

	/**
	 * Add custom columns to the admin list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function custom_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'title' === $key ) {
				$new_columns['manga_cover']  = esc_html__( 'Cover', 'starter' );
				$new_columns['manga_status'] = esc_html__( 'Status', 'starter' );
				$new_columns['manga_badge']  = esc_html__( 'Badge', 'starter' );
				$new_columns['manga_type']   = esc_html__( 'Type', 'starter' );
				$new_columns['manga_adult']  = esc_html__( '18+', 'starter' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function custom_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'manga_cover':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 50, 70 ) );
				} else {
					echo '&mdash;';
				}
				break;

			case 'manga_status':
				$status = get_post_meta( $post_id, self::META_PREFIX . 'status', true );
				if ( $status ) {
					$status_labels = array(
						'ongoing'   => esc_html__( 'Ongoing', 'starter' ),
						'completed' => esc_html__( 'Completed', 'starter' ),
						'hiatus'    => esc_html__( 'Hiatus', 'starter' ),
						'cancelled' => esc_html__( 'Cancelled', 'starter' ),
					);
					echo isset( $status_labels[ $status ] ) ? esc_html( $status_labels[ $status ] ) : '&mdash;';
				} else {
					echo '&mdash;';
				}
				break;

			case 'manga_badge':
				$badge = get_post_meta( $post_id, self::META_PREFIX . 'badge', true );
				if ( $badge ) {
					echo '<span class="starter-badge starter-badge-' . esc_attr( $badge ) . '">' . esc_html( ucfirst( $badge ) ) . '</span>';
				} else {
					echo '&mdash;';
				}
				break;

			case 'manga_type':
				$terms = get_the_terms( $post_id, 'wp-manga-type' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$type_names = wp_list_pluck( $terms, 'name' );
					echo esc_html( implode( ', ', $type_names ) );
				} else {
					echo '&mdash;';
				}
				break;

			case 'manga_adult':
				$adult = get_post_meta( $post_id, self::META_PREFIX . 'adult_content', true );
				echo '1' === $adult ? '<span style="color:red;">&#10003;</span>' : '&mdash;';
				break;
		}
	}

	/**
	 * Register sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function sortable_columns( $columns ) {
		$columns['manga_status'] = 'manga_status';
		return $columns;
	}

	/**
	 * Flush rewrite rules. Call on theme activation.
	 *
	 * @return void
	 */
	public static function flush_rewrite_rules() {
		$instance = self::get_instance();
		$instance->register_post_type();
		$instance->register_taxonomies();
		flush_rewrite_rules();
	}

	/**
	 * Get all manga meta fields for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Associative array of meta values.
	 */
	public static function get_manga_meta( $post_id ) {
		$fields = array(
			'alt_names',
			'status',
			'adult_content',
			'badge',
			'author_name',
			'artist_name',
			'serialization',
		);

		$meta = array();
		foreach ( $fields as $field ) {
			$meta[ $field ] = get_post_meta( $post_id, self::META_PREFIX . $field, true );
		}

		return $meta;
	}
}
