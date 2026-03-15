<?php
/**
 * Automatic keyword generation for manga, novel, and video pages.
 *
 * Extracts keywords from post metadata, generates long-tail variants,
 * stores results in post meta, and integrates with Yoast SEO focus
 * keyword when available.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Auto_Keywords
 */
class Starter_Auto_Keywords {

	/**
	 * Post meta key where generated keywords are stored.
	 *
	 * @var string
	 */
	const META_KEY = '_starter_auto_keywords';

	/**
	 * Option key for the admin keyword template.
	 *
	 * @var string
	 */
	const TEMPLATE_OPTION = 'starter_keyword_template';

	/**
	 * Supported post types.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'manga', 'novel', 'video' );

	/**
	 * Default long-tail keyword templates.
	 *
	 * Placeholders: {title}, {genre}, {type}, {author}, {artist},
	 * {status}, {alt_name}, {num}.
	 *
	 * @var string[]
	 */
	private $default_templates = array(
		'{title} chapter {num}',
		'read {title} online',
		'{title} {type} {genre}',
		'{title} {status}',
		'{title} by {author}',
		'{title} {alt_name}',
		'download {title} {type}',
		'{title} latest chapter',
		'{genre} {type} recommendations',
	);

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Output meta keywords in <head>.
		add_action( 'wp_head', array( $this, 'output_meta_keywords' ), 5 );

		// Auto-update keywords when manga meta changes.
		add_action( 'save_post', array( $this, 'regenerate_on_save' ), 20 );
		add_action( 'updated_post_meta', array( $this, 'regenerate_on_meta_update' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'regenerate_on_term_change' ), 10, 4 );

		// Yoast integration: suggest focus keyword.
		add_filter( 'wpseo_metabox_entries_general', array( $this, 'yoast_suggest_focus_keyword' ) );

		// Admin settings for keyword template.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
	}

	/*--------------------------------------------------------------------------
	 * Keyword Generation from Metadata
	 *------------------------------------------------------------------------*/

	/**
	 * Generate all keywords for a given post.
	 *
	 * Combines base keywords from metadata with long-tail variants.
	 *
	 * @param int $post_id The post ID.
	 * @return string[] Array of unique keywords.
	 */
	public function generate_keywords( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return array();
		}

		$base      = $this->extract_base_keywords( $post_id );
		$long_tail = $this->generate_long_tail_keywords( $post_id );
		$all       = array_merge( $base, $long_tail );

		// Normalize: lowercase, trim, unique.
		$all = array_map( 'mb_strtolower', $all );
		$all = array_map( 'trim', $all );
		$all = array_unique( array_filter( $all ) );
		$all = array_values( $all );

		/**
		 * Filter the full list of generated keywords.
		 *
		 * @param string[] $all     Generated keywords.
		 * @param int      $post_id Post ID.
		 */
		return apply_filters( 'starter_auto_keywords', $all, $post_id );
	}

	/**
	 * Extract base keywords from manga/novel/video metadata.
	 *
	 * Sources: title, alternative names, genres, author, artist,
	 * tags, type, and status.
	 *
	 * @param int $post_id The post ID.
	 * @return string[]
	 */
	public function extract_base_keywords( $post_id ) {
		$keywords = array();
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return $keywords;
		}

		// Title.
		$title = get_the_title( $post );
		if ( $title ) {
			$keywords[] = $title;
		}

		// Alternative names (stored as comma-separated or serialised array).
		$alt_names = get_post_meta( $post_id, '_starter_alternative_names', true );
		if ( is_string( $alt_names ) && ! empty( $alt_names ) ) {
			$alt_names = array_map( 'trim', explode( ',', $alt_names ) );
		}
		if ( is_array( $alt_names ) ) {
			$keywords = array_merge( $keywords, $alt_names );
		}

		// Genres (taxonomy).
		$genres = wp_get_object_terms( $post_id, 'genre', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) ) {
			$keywords = array_merge( $keywords, $genres );
		}

		// Author.
		$author = get_post_meta( $post_id, '_starter_author', true );
		if ( $author ) {
			$keywords[] = $author;
		}

		// Artist.
		$artist = get_post_meta( $post_id, '_starter_artist', true );
		if ( $artist ) {
			$keywords[] = $artist;
		}

		// Tags (taxonomy: post_tag or manga_tag).
		foreach ( array( 'post_tag', 'manga_tag' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$tags = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $tags ) ) {
					$keywords = array_merge( $keywords, $tags );
				}
			}
		}

		// Type (manga, manhwa, manhua, novel, comic, video, etc.).
		$type = get_post_meta( $post_id, '_starter_type', true );
		if ( $type ) {
			$keywords[] = $type;
		}

		// Status (ongoing, completed, hiatus, etc.).
		$status = get_post_meta( $post_id, '_starter_status', true );
		if ( $status ) {
			$keywords[] = $status;
		}

		/**
		 * Filter base keywords extracted from metadata.
		 *
		 * @param string[] $keywords Base keywords.
		 * @param int      $post_id  Post ID.
		 */
		return apply_filters( 'starter_auto_keywords_base', $keywords, $post_id );
	}

	/*--------------------------------------------------------------------------
	 * Long-Tail Keyword Generation
	 *------------------------------------------------------------------------*/

	/**
	 * Generate long-tail keywords from templates.
	 *
	 * Templates come from admin settings, with a fallback to defaults.
	 *
	 * @param int $post_id The post ID.
	 * @return string[]
	 */
	public function generate_long_tail_keywords( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$templates = $this->get_keyword_templates();
		$vars      = $this->get_template_variables( $post_id );
		$keywords  = array();

		foreach ( $templates as $template ) {
			$keyword = $this->fill_template( $template, $vars );

			// Only keep if at least one placeholder was filled.
			if ( $keyword !== $template && ! empty( trim( $keyword ) ) ) {
				$keywords[] = $keyword;
			}
		}

		// Chapter-specific long-tail: "{title} chapter {n}" for latest chapters.
		$latest_chapter = get_post_meta( $post_id, '_starter_latest_chapter', true );
		if ( $latest_chapter ) {
			$title      = get_the_title( $post );
			$keywords[] = sprintf( '%s chapter %s', $title, $latest_chapter );
			$keywords[] = sprintf( '%s chapter %s read online', $title, $latest_chapter );
		}

		/**
		 * Filter long-tail keywords.
		 *
		 * @param string[] $keywords Long-tail keywords.
		 * @param int      $post_id  Post ID.
		 */
		return apply_filters( 'starter_auto_keywords_long_tail', $keywords, $post_id );
	}

	/**
	 * Retrieve the keyword templates configured in admin.
	 *
	 * Falls back to built-in defaults.
	 *
	 * @return string[]
	 */
	public function get_keyword_templates() {
		$templates = get_option( self::TEMPLATE_OPTION, '' );

		if ( ! empty( $templates ) && is_string( $templates ) ) {
			$templates = array_filter( array_map( 'trim', explode( "\n", $templates ) ) );
		}

		if ( empty( $templates ) || ! is_array( $templates ) ) {
			$templates = $this->default_templates;
		}

		/**
		 * Filter keyword templates.
		 *
		 * @param string[] $templates Keyword templates.
		 */
		return apply_filters( 'starter_auto_keywords_templates', $templates );
	}

	/**
	 * Build the variable map for template replacement.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string,string>
	 */
	private function get_template_variables( $post_id ) {
		$post = get_post( $post_id );

		$genres = wp_get_object_terms( $post_id, 'genre', array( 'fields' => 'names' ) );
		$genre  = ( ! is_wp_error( $genres ) && ! empty( $genres ) ) ? $genres[0] : '';

		$alt_names = get_post_meta( $post_id, '_starter_alternative_names', true );
		if ( is_string( $alt_names ) ) {
			$parts    = array_map( 'trim', explode( ',', $alt_names ) );
			$alt_name = ! empty( $parts[0] ) ? $parts[0] : '';
		} elseif ( is_array( $alt_names ) && ! empty( $alt_names ) ) {
			$alt_name = $alt_names[0];
		} else {
			$alt_name = '';
		}

		$latest_chapter = get_post_meta( $post_id, '_starter_latest_chapter', true );

		return array(
			'{title}'    => $post ? get_the_title( $post ) : '',
			'{genre}'    => $genre,
			'{type}'     => get_post_meta( $post_id, '_starter_type', true ),
			'{author}'   => get_post_meta( $post_id, '_starter_author', true ),
			'{artist}'   => get_post_meta( $post_id, '_starter_artist', true ),
			'{status}'   => get_post_meta( $post_id, '_starter_status', true ),
			'{alt_name}' => $alt_name,
			'{num}'      => $latest_chapter ? $latest_chapter : '1',
		);
	}

	/**
	 * Replace placeholders in a template string.
	 *
	 * Removes placeholders that have no value and collapses extra spaces.
	 *
	 * @param string              $template Template string.
	 * @param array<string,string> $vars     Variable map.
	 * @return string
	 */
	private function fill_template( $template, $vars ) {
		$result = str_replace( array_keys( $vars ), array_values( $vars ), $template );

		// Remove leftover unfilled placeholders.
		$result = preg_replace( '/\{[a-z_]+\}/', '', $result );

		// Collapse multiple spaces.
		$result = preg_replace( '/\s+/', ' ', trim( $result ) );

		return $result;
	}

	/*--------------------------------------------------------------------------
	 * Meta Keywords Output
	 *------------------------------------------------------------------------*/

	/**
	 * Output the meta keywords tag in wp_head.
	 *
	 * While deprecated by major engines, some smaller engines still use it.
	 *
	 * @return void
	 */
	public function output_meta_keywords() {
		if ( ! is_singular( $this->post_types ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$keywords = $this->get_stored_keywords( $post->ID );

		if ( empty( $keywords ) ) {
			$keywords = $this->generate_keywords( $post->ID );
			$this->store_keywords( $post->ID, $keywords );
		}

		if ( ! empty( $keywords ) ) {
			printf(
				'<meta name="keywords" content="%s" />' . "\n",
				esc_attr( implode( ', ', $keywords ) )
			);
		}
	}

	/*--------------------------------------------------------------------------
	 * Keyword Storage
	 *------------------------------------------------------------------------*/

	/**
	 * Store generated keywords in post meta.
	 *
	 * @param int      $post_id  The post ID.
	 * @param string[] $keywords Keywords array.
	 * @return void
	 */
	public function store_keywords( $post_id, $keywords ) {
		update_post_meta( $post_id, self::META_KEY, $keywords );
	}

	/**
	 * Retrieve stored keywords from post meta.
	 *
	 * @param int $post_id The post ID.
	 * @return string[]
	 */
	public function get_stored_keywords( $post_id ) {
		$keywords = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $keywords ) ) {
			return array();
		}

		return $keywords;
	}

	/*--------------------------------------------------------------------------
	 * Auto-Update on Metadata Changes
	 *------------------------------------------------------------------------*/

	/**
	 * Regenerate keywords when a post is saved.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function regenerate_on_save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}

		$keywords = $this->generate_keywords( $post_id );
		$this->store_keywords( $post_id, $keywords );

		// Push focus keyword to Yoast if active.
		$this->sync_yoast_focus_keyword( $post_id, $keywords );
	}

	/**
	 * Regenerate keywords when relevant post meta is updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function regenerate_on_meta_update( $meta_id, $post_id, $meta_key, $meta_value ) {
		$watched_keys = array(
			'_starter_author',
			'_starter_artist',
			'_starter_type',
			'_starter_status',
			'_starter_alternative_names',
			'_starter_latest_chapter',
			'_starter_description',
		);

		if ( ! in_array( $meta_key, $watched_keys, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}

		$keywords = $this->generate_keywords( $post_id );
		$this->store_keywords( $post_id, $keywords );
	}

	/**
	 * Regenerate keywords when taxonomy terms change.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $terms    Array of term IDs.
	 * @param array  $tt_ids   Array of term taxonomy IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function regenerate_on_term_change( $post_id, $terms, $tt_ids, $taxonomy ) {
		$watched_taxonomies = array( 'genre', 'post_tag', 'manga_tag' );

		if ( ! in_array( $taxonomy, $watched_taxonomies, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}

		$keywords = $this->generate_keywords( $post_id );
		$this->store_keywords( $post_id, $keywords );
	}

	/*--------------------------------------------------------------------------
	 * Yoast SEO Integration
	 *------------------------------------------------------------------------*/

	/**
	 * Sync the primary keyword to Yoast's focus keyword field.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string[] $keywords Generated keywords.
	 * @return void
	 */
	private function sync_yoast_focus_keyword( $post_id, $keywords ) {
		if ( ! defined( 'WPSEO_VERSION' ) || empty( $keywords ) ) {
			return;
		}

		// Only set if Yoast focus keyword is currently empty.
		$existing = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$focus = $this->suggest_focus_keyword( $post_id, $keywords );

		if ( $focus ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $focus ) );
		}
	}

	/**
	 * Suggest the best focus keyword from the generated set.
	 *
	 * Prefers the manga title combined with its primary genre.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string[] $keywords Generated keywords (optional, will load stored if empty).
	 * @return string
	 */
	public function suggest_focus_keyword( $post_id, $keywords = array() ) {
		if ( empty( $keywords ) ) {
			$keywords = $this->get_stored_keywords( $post_id );
		}

		if ( empty( $keywords ) ) {
			return '';
		}

		$post  = get_post( $post_id );
		$title = $post ? mb_strtolower( get_the_title( $post ) ) : '';
		$type  = get_post_meta( $post_id, '_starter_type', true );

		$genres = wp_get_object_terms( $post_id, 'genre', array( 'fields' => 'names' ) );
		$genre  = ( ! is_wp_error( $genres ) && ! empty( $genres ) ) ? mb_strtolower( $genres[0] ) : '';

		// Best focus keyword: "{title} {type}" or "{title} {genre}".
		if ( $title && $type ) {
			return trim( $title . ' ' . mb_strtolower( $type ) );
		}

		if ( $title && $genre ) {
			return trim( $title . ' ' . $genre );
		}

		return $title ? $title : $keywords[0];
	}

	/**
	 * Hook into Yoast metabox to suggest focus keyword.
	 *
	 * @param array $entries Metabox entries.
	 * @return array
	 */
	public function yoast_suggest_focus_keyword( $entries ) {
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return $entries;
		}

		$suggestion = $this->suggest_focus_keyword( $post->ID );

		if ( $suggestion ) {
			$entries['starter_suggested_keyword'] = array(
				'content' => sprintf(
					/* translators: %s: suggested keyword */
					'<p class="starter-keyword-suggestion">' . esc_html__( 'Suggested focus keyword: %s', 'starter-theme' ) . '</p>',
					'<strong>' . esc_html( $suggestion ) . '</strong>'
				),
			);
		}

		return $entries;
	}

	/*--------------------------------------------------------------------------
	 * Keyword Density Check
	 *------------------------------------------------------------------------*/

	/**
	 * Calculate the keyword density for a given keyword in post content.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $keyword The keyword to check.
	 * @return float Keyword density as a percentage (0-100).
	 */
	public function check_keyword_density( $post_id, $keyword ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0.0;
		}

		$content    = mb_strtolower( wp_strip_all_tags( $post->post_content ) );
		$keyword    = mb_strtolower( trim( $keyword ) );
		$word_count = str_word_count( $content );

		if ( 0 === $word_count || empty( $keyword ) ) {
			return 0.0;
		}

		$keyword_count = mb_substr_count( $content, $keyword );
		$keyword_words = str_word_count( $keyword );

		if ( 0 === $keyword_words ) {
			return 0.0;
		}

		$density = ( $keyword_count * $keyword_words / $word_count ) * 100;

		return round( $density, 2 );
	}

	/**
	 * Get keyword density for all stored keywords.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string,float> Keyword => density pairs.
	 */
	public function get_all_keyword_densities( $post_id ) {
		$keywords  = $this->get_stored_keywords( $post_id );
		$densities = array();

		foreach ( $keywords as $keyword ) {
			$densities[ $keyword ] = $this->check_keyword_density( $post_id, $keyword );
		}

		arsort( $densities );

		return $densities;
	}

	/*--------------------------------------------------------------------------
	 * Admin Settings: Configurable Keyword Template
	 *------------------------------------------------------------------------*/

	/**
	 * Register the keyword template option in WordPress settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'starter_seo_settings',
			self::TEMPLATE_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'starter_seo_keywords_section',
			esc_html__( 'Auto Keywords Settings', 'starter-theme' ),
			array( $this, 'render_settings_section' ),
			'starter-seo-settings'
		);

		add_settings_field(
			'starter_keyword_template_field',
			esc_html__( 'Keyword Templates', 'starter-theme' ),
			array( $this, 'render_template_field' ),
			'starter-seo-settings',
			'starter_seo_keywords_section'
		);
	}

	/**
	 * Render the settings section description.
	 *
	 * @return void
	 */
	public function render_settings_section() {
		echo '<p>' . esc_html__( 'Configure keyword generation templates. One template per line.', 'starter-theme' ) . '</p>';
		echo '<p>' . esc_html__( 'Available placeholders: {title}, {genre}, {type}, {author}, {artist}, {status}, {alt_name}, {num}', 'starter-theme' ) . '</p>';
	}

	/**
	 * Render the template textarea field.
	 *
	 * @return void
	 */
	public function render_template_field() {
		$value = get_option( self::TEMPLATE_OPTION, '' );

		if ( empty( $value ) ) {
			$value = implode( "\n", $this->default_templates );
		}

		printf(
			'<textarea name="%s" rows="10" cols="60" class="large-text code">%s</textarea>',
			esc_attr( self::TEMPLATE_OPTION ),
			esc_textarea( $value )
		);
	}

	/*--------------------------------------------------------------------------
	 * Admin Meta Box: Per-Post Keywords
	 *------------------------------------------------------------------------*/

	/**
	 * Register the keywords meta box on supported post types.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		foreach ( $this->post_types as $type ) {
			add_meta_box(
				'starter_auto_keywords',
				esc_html__( 'Auto Keywords', 'starter-theme' ),
				array( $this, 'render_meta_box' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render the keywords meta box content.
	 *
	 * @param WP_Post $post The current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'starter_auto_keywords_nonce', 'starter_auto_keywords_nonce_field' );

		$keywords = $this->get_stored_keywords( $post->ID );

		if ( empty( $keywords ) ) {
			$keywords = $this->generate_keywords( $post->ID );
		}

		$custom_keywords = get_post_meta( $post->ID, '_starter_custom_keywords', true );
		?>
		<p>
			<label for="starter_custom_keywords"><strong><?php esc_html_e( 'Custom Keywords (comma-separated):', 'starter-theme' ); ?></strong></label><br />
			<input type="text" id="starter_custom_keywords" name="starter_custom_keywords"
				   value="<?php echo esc_attr( $custom_keywords ); ?>" class="widefat" />
		</p>

		<?php if ( ! empty( $keywords ) ) : ?>
			<p><strong><?php esc_html_e( 'Auto-generated Keywords:', 'starter-theme' ); ?></strong></p>
			<div class="starter-keyword-list" style="max-height:150px;overflow-y:auto;background:#f9f9f9;padding:8px;border:1px solid #ddd;">
				<?php echo esc_html( implode( ', ', $keywords ) ); ?>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %d: keyword count */
					esc_html__( '%d keywords generated from metadata.', 'starter-theme' ),
					count( $keywords )
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		$focus = $this->suggest_focus_keyword( $post->ID, $keywords );
		if ( $focus ) :
			?>
			<p>
				<strong><?php esc_html_e( 'Suggested Focus Keyword:', 'starter-theme' ); ?></strong>
				<?php echo esc_html( $focus ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the keywords meta box data.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['starter_auto_keywords_nonce_field'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['starter_auto_keywords_nonce_field'] ) ), 'starter_auto_keywords_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['starter_custom_keywords'] ) ) {
			$custom = sanitize_text_field( wp_unslash( $_POST['starter_custom_keywords'] ) );
			update_post_meta( $post_id, '_starter_custom_keywords', $custom );

			// Merge custom keywords with auto-generated ones.
			if ( ! empty( $custom ) ) {
				$custom_arr = array_map( 'trim', explode( ',', $custom ) );
				$auto       = $this->generate_keywords( $post_id );
				$merged     = array_unique( array_merge( $custom_arr, $auto ) );
				$this->store_keywords( $post_id, $merged );
			}
		}
	}
}
