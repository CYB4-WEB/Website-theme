<?php
/**
 * Frontend User Upload System.
 *
 * Handles front-end manga submission, chapter uploads (ZIP/PDF/images),
 * cover cropping (via Croppie), MangaUpdates pre-fill, and status management
 * for the [alpha_upload_manga] and [alpha_upload_chapter] shortcodes.
 *
 * @package starter-theme
 * @subpackage User
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_User_Upload
 */
class Starter_User_Upload {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_User_Upload|null
	 */
	private static $instance = null;

	/**
	 * Nonce action for manga submission.
	 *
	 * @var string
	 */
	const NONCE_MANGA   = 'starter_upload_manga_nonce';

	/**
	 * Nonce action for chapter upload.
	 *
	 * @var string
	 */
	const NONCE_CHAPTER = 'starter_upload_chapter_nonce';

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_User_Upload
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		/* Shortcodes */
		add_shortcode( 'alpha_upload_manga',   array( $this, 'shortcode_upload_manga' ) );
		add_shortcode( 'alpha_upload_chapter', array( $this, 'shortcode_upload_chapter' ) );

		/* AJAX handlers */
		add_action( 'wp_ajax_starter_submit_manga',          array( $this, 'ajax_submit_manga' ) );
		add_action( 'wp_ajax_starter_submit_chapter',        array( $this, 'ajax_submit_chapter' ) );
		add_action( 'wp_ajax_starter_upload_cover',          array( $this, 'ajax_upload_cover' ) );
		add_action( 'wp_ajax_starter_prefill_mangaupdates',  array( $this, 'ajax_prefill_mangaupdates' ) );
		add_action( 'wp_ajax_starter_get_user_manga_list',   array( $this, 'ajax_get_user_manga_list' ) );
		add_action( 'wp_ajax_starter_delete_user_manga',     array( $this, 'ajax_delete_user_manga' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Shortcodes
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * [alpha_upload_manga] — Full manga submission form.
	 *
	 * @return string HTML.
	 */
	public function shortcode_upload_manga() {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_prompt();
		}

		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_manga_chapters' ) ) {
			return '<div class="alpha-notice alpha-notice--error">' . esc_html__( 'You do not have permission to upload manga.', 'starter-theme' ) . '</div>';
		}

		$genres = get_terms( array( 'taxonomy' => 'genre', 'hide_empty' => false, 'number' => 50 ) );

		wp_enqueue_style( 'croppie', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css', array(), '2.6.5' );
		wp_enqueue_script( 'croppie', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array(), '2.6.5', true );
		wp_enqueue_script( 'starter-image-upload', get_template_directory_uri() . '/assets/js/image-upload.js', array( 'jquery' ), STARTER_THEME_VERSION, true );
		wp_enqueue_script( 'starter-thumbnail-cropper', get_template_directory_uri() . '/assets/js/thumbnail-cropper.js', array( 'jquery', 'croppie' ), STARTER_THEME_VERSION, true );

		wp_localize_script( 'starter-image-upload', 'starterUpload', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonceManga'       => wp_create_nonce( self::NONCE_MANGA ),
			'nonceChapter'     => wp_create_nonce( self::NONCE_CHAPTER ),
			'nonceCover'       => wp_create_nonce( 'starter_upload_cover_nonce' ),
			'nonceMUPrefill'   => wp_create_nonce( 'starter_mangaupdates_prefill' ),
			'i18n'             => array(
				'uploading'    => __( 'Uploading…', 'starter-theme' ),
				'success'      => __( 'Manga submitted successfully!', 'starter-theme' ),
				'error'        => __( 'An error occurred. Please try again.', 'starter-theme' ),
			),
		) );

		ob_start();
		?>
		<div class="upload-form-wrap" id="upload-manga-wrap">

			<!-- MangaUpdates Quick-Fill Banner -->
			<div class="mu-import-bar">
				<label for="mu-url-input" class="mu-import-bar__label">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<?php esc_html_e( 'Import from MangaUpdates:', 'starter-theme' ); ?>
				</label>
				<input type="url" id="mu-url-input" class="mu-import-bar__input"
				       placeholder="https://www.mangaupdates.com/series/axmopy6"
				       autocomplete="off">
				<button type="button" id="mu-import-btn" class="btn btn--accent btn--sm">
					<?php esc_html_e( 'Fill Fields', 'starter-theme' ); ?>
				</button>
				<span class="mu-import-bar__status" id="mu-status" aria-live="polite"></span>
			</div>

			<form id="alpha-upload-manga-form" class="upload-form" enctype="multipart/form-data" novalidate>
				<?php wp_nonce_field( self::NONCE_MANGA, 'starter_manga_nonce' ); ?>

				<div class="upload-form__grid">

					<!-- LEFT: Cover art -->
					<div class="upload-form__cover-col">
						<div class="cover-uploader">
							<div class="cover-uploader__preview" id="cover-preview">
								<svg class="cover-uploader__placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
								<span><?php esc_html_e( 'Click to upload cover', 'starter-theme' ); ?></span>
							</div>
							<input type="file" id="cover-file-input" accept="image/jpeg,image/png,image/webp" class="sr-only">
							<input type="hidden" id="cover-attachment-id" name="cover_attachment_id" value="">
							<button type="button" id="cover-upload-btn" class="btn btn--outline btn--sm btn--block">
								<?php esc_html_e( 'Upload Cover', 'starter-theme' ); ?>
							</button>
							<!-- Croppie modal -->
							<div class="croppie-modal" id="croppie-modal" hidden>
								<div class="croppie-modal__inner">
									<h3><?php esc_html_e( 'Crop Cover Image', 'starter-theme' ); ?></h3>
									<div id="croppie-container"></div>
									<div class="croppie-modal__actions">
										<button type="button" id="croppie-confirm" class="btn btn--primary"><?php esc_html_e( 'Confirm', 'starter-theme' ); ?></button>
										<button type="button" id="croppie-cancel"  class="btn btn--outline"><?php esc_html_e( 'Cancel', 'starter-theme' ); ?></button>
									</div>
								</div>
							</div>
						</div>
					</div><!-- cover col -->

					<!-- RIGHT: Info fields -->
					<div class="upload-form__info-col">

						<div class="form-group">
							<label for="manga-title" class="form-label form-label--required"><?php esc_html_e( 'Title', 'starter-theme' ); ?></label>
							<input type="text" id="manga-title" name="title" class="form-control" required maxlength="255" autocomplete="off">
						</div>

						<div class="form-row">
							<div class="form-group">
								<label for="manga-author" class="form-label"><?php esc_html_e( 'Author', 'starter-theme' ); ?></label>
								<input type="text" id="manga-author" name="author" class="form-control" autocomplete="off">
							</div>
							<div class="form-group">
								<label for="manga-artist" class="form-label"><?php esc_html_e( 'Artist', 'starter-theme' ); ?></label>
								<input type="text" id="manga-artist" name="artist" class="form-control" autocomplete="off">
							</div>
						</div>

						<div class="form-row">
							<div class="form-group">
								<label for="manga-type" class="form-label"><?php esc_html_e( 'Type', 'starter-theme' ); ?></label>
								<select id="manga-type" name="content_type" class="form-select">
									<option value="manga"><?php esc_html_e( 'Manga', 'starter-theme' ); ?></option>
									<option value="novel"><?php esc_html_e( 'Novel', 'starter-theme' ); ?></option>
									<option value="manhwa"><?php esc_html_e( 'Manhwa', 'starter-theme' ); ?></option>
									<option value="manhua"><?php esc_html_e( 'Manhua', 'starter-theme' ); ?></option>
									<option value="video"><?php esc_html_e( 'Video / Anime', 'starter-theme' ); ?></option>
								</select>
							</div>
							<div class="form-group">
								<label for="manga-status" class="form-label"><?php esc_html_e( 'Status', 'starter-theme' ); ?></label>
								<select id="manga-status" name="status" class="form-select">
									<option value="Ongoing"><?php esc_html_e( 'Ongoing', 'starter-theme' ); ?></option>
									<option value="Completed"><?php esc_html_e( 'Completed', 'starter-theme' ); ?></option>
									<option value="Hiatus"><?php esc_html_e( 'Hiatus', 'starter-theme' ); ?></option>
									<option value="Cancelled"><?php esc_html_e( 'Cancelled', 'starter-theme' ); ?></option>
								</select>
							</div>
							<div class="form-group">
								<label for="manga-year" class="form-label"><?php esc_html_e( 'Year', 'starter-theme' ); ?></label>
								<input type="number" id="manga-year" name="release_year" class="form-control" min="1900" max="<?php echo date( 'Y' ) + 2; ?>" placeholder="<?php echo date( 'Y' ); ?>">
							</div>
						</div>

						<div class="form-group">
							<label class="form-label"><?php esc_html_e( 'Genres', 'starter-theme' ); ?></label>
							<div class="genre-checkboxes">
								<?php if ( ! is_wp_error( $genres ) ) : ?>
									<?php foreach ( $genres as $g ) : ?>
										<label class="genre-checkbox-label">
											<input type="checkbox" name="genres[]" value="<?php echo esc_attr( $g->term_id ); ?>">
											<?php echo esc_html( $g->name ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</div>

						<div class="form-group">
							<label for="manga-description" class="form-label"><?php esc_html_e( 'Description', 'starter-theme' ); ?></label>
							<textarea id="manga-description" name="description" class="form-control form-control--textarea" rows="5" maxlength="5000"></textarea>
						</div>

						<div class="form-group">
							<label for="manga-alt-names" class="form-label"><?php esc_html_e( 'Alternative Names', 'starter-theme' ); ?></label>
							<input type="text" id="manga-alt-names" name="alternative_names" class="form-control"
							       placeholder="<?php esc_attr_e( 'Comma-separated', 'starter-theme' ); ?>">
						</div>

					</div><!-- info col -->
				</div><!-- grid -->

				<div class="upload-form__footer">
					<div id="upload-manga-progress" class="upload-progress" hidden>
						<div class="upload-progress__bar"><div class="upload-progress__fill" id="manga-progress-fill"></div></div>
						<span class="upload-progress__label" id="manga-progress-label"></span>
					</div>
					<div class="upload-form__actions">
						<button type="submit" id="submit-manga-btn" class="btn btn--primary btn--lg">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
							<?php esc_html_e( 'Submit Manga', 'starter-theme' ); ?>
						</button>
					</div>
					<div id="upload-manga-result" class="upload-form__result" aria-live="polite"></div>
				</div>

			</form><!-- #alpha-upload-manga-form -->
		</div><!-- #upload-manga-wrap -->
		<?php
		return ob_get_clean();
	}

	/**
	 * [alpha_upload_chapter] — Chapter upload form.
	 *
	 * @param array $atts Shortcode attributes (manga_id).
	 * @return string HTML.
	 */
	public function shortcode_upload_chapter( $atts ) {
		$atts = shortcode_atts( array( 'manga_id' => 0 ), $atts );

		if ( ! is_user_logged_in() ) {
			return $this->render_login_prompt();
		}

		if ( ! current_user_can( 'manage_manga_chapters' ) && ! current_user_can( 'manage_options' ) ) {
			return '<div class="alpha-notice alpha-notice--error">' . esc_html__( 'You do not have permission to upload chapters.', 'starter-theme' ) . '</div>';
		}

		/* Build list of manga this user can manage */
		$manga_list = get_posts( array(
			'post_type'   => 'wp-manga',
			'numberposts' => -1,
			'author'      => current_user_can( 'manage_options' ) ? null : get_current_user_id(),
			'post_status' => array( 'publish', 'draft', 'pending' ),
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );

		wp_enqueue_script( 'starter-image-upload', get_template_directory_uri() . '/assets/js/image-upload.js', array( 'jquery' ), STARTER_THEME_VERSION, true );
		wp_localize_script( 'starter-image-upload', 'starterUpload', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonceChapter' => wp_create_nonce( self::NONCE_CHAPTER ),
		) );

		ob_start();
		?>
		<div class="upload-form-wrap" id="upload-chapter-wrap">
			<form id="alpha-upload-chapter-form" class="upload-form" enctype="multipart/form-data" novalidate>
				<?php wp_nonce_field( self::NONCE_CHAPTER, 'starter_chapter_nonce' ); ?>

				<div class="form-group">
					<label for="chapter-manga-id" class="form-label form-label--required"><?php esc_html_e( 'Manga', 'starter-theme' ); ?></label>
					<select id="chapter-manga-id" name="manga_id" class="form-select" required>
						<option value=""><?php esc_html_e( '— Select Manga —', 'starter-theme' ); ?></option>
						<?php foreach ( $manga_list as $m ) : ?>
							<option value="<?php echo esc_attr( $m->ID ); ?>" <?php selected( $atts['manga_id'], $m->ID ); ?>>
								<?php echo esc_html( $m->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="chapter-number" class="form-label form-label--required"><?php esc_html_e( 'Chapter Number', 'starter-theme' ); ?></label>
						<input type="number" id="chapter-number" name="chapter_number" class="form-control" min="0" step="0.1" required>
					</div>
					<div class="form-group">
						<label for="chapter-title" class="form-label"><?php esc_html_e( 'Chapter Title', 'starter-theme' ); ?></label>
						<input type="text" id="chapter-title" name="chapter_title" class="form-control" autocomplete="off">
					</div>
					<div class="form-group">
						<label for="chapter-volume" class="form-label"><?php esc_html_e( 'Volume', 'starter-theme' ); ?></label>
						<input type="number" id="chapter-volume" name="volume" class="form-control" min="1" placeholder="<?php esc_attr_e( 'Optional', 'starter-theme' ); ?>">
					</div>
				</div>

				<div class="form-group">
					<label for="chapter-type" class="form-label"><?php esc_html_e( 'Chapter Type', 'starter-theme' ); ?></label>
					<div class="type-radio-group">
						<label class="type-radio"><input type="radio" name="chapter_type" value="image" checked> <?php esc_html_e( 'Images', 'starter-theme' ); ?></label>
						<label class="type-radio"><input type="radio" name="chapter_type" value="zip"> <?php esc_html_e( 'ZIP Archive', 'starter-theme' ); ?></label>
						<label class="type-radio"><input type="radio" name="chapter_type" value="pdf"> <?php esc_html_e( 'PDF', 'starter-theme' ); ?></label>
						<label class="type-radio"><input type="radio" name="chapter_type" value="text"> <?php esc_html_e( 'Text / Novel', 'starter-theme' ); ?></label>
						<label class="type-radio"><input type="radio" name="chapter_type" value="video"> <?php esc_html_e( 'Video', 'starter-theme' ); ?></label>
					</div>
				</div>

				<!-- Image upload zone -->
				<div class="upload-zone" id="chapter-image-zone" data-type="image">
					<div class="upload-zone__inner" id="chapter-drop-zone">
						<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
						<p><?php esc_html_e( 'Drop images here or click to select', 'starter-theme' ); ?></p>
						<span><?php esc_html_e( 'JPG, PNG, WebP — multiple allowed', 'starter-theme' ); ?></span>
					</div>
					<input type="file" id="chapter-images-input" name="chapter_images[]" accept="image/*" multiple class="sr-only">
					<div class="upload-zone__preview" id="chapter-images-preview"></div>
				</div>

				<!-- ZIP upload zone -->
				<div class="upload-zone" id="chapter-zip-zone" data-type="zip" hidden>
					<div class="upload-zone__inner">
						<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="10" y1="12" x2="10" y2="18"/><line x1="13" y1="12" x2="13" y2="18"/></svg>
						<p><?php esc_html_e( 'Select ZIP archive', 'starter-theme' ); ?></p>
					</div>
					<input type="file" id="chapter-zip-input" name="chapter_zip" accept=".zip,application/zip" class="sr-only">
				</div>

				<!-- Text zone -->
				<div class="upload-zone" id="chapter-text-zone" data-type="text" hidden>
					<textarea name="chapter_content" class="form-control form-control--textarea" rows="15"
					          placeholder="<?php esc_attr_e( 'Paste chapter text here…', 'starter-theme' ); ?>"></textarea>
				</div>

				<!-- Video zone -->
				<div class="upload-zone" id="chapter-video-zone" data-type="video" hidden>
					<div class="form-group">
						<label for="chapter-video-url" class="form-label"><?php esc_html_e( 'Video URL', 'starter-theme' ); ?></label>
						<input type="url" id="chapter-video-url" name="video_url" class="form-control"
						       placeholder="<?php esc_attr_e( 'YouTube, Pixeldrain, Google Drive, or direct URL', 'starter-theme' ); ?>">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label class="form-label"><?php esc_html_e( 'Premium?', 'starter-theme' ); ?></label>
						<label class="toggle-switch">
							<input type="checkbox" name="is_premium" id="chapter-is-premium" value="1">
							<span class="toggle-switch__track"></span>
							<span class="toggle-switch__label"><?php esc_html_e( 'Lock with coins', 'starter-theme' ); ?></span>
						</label>
					</div>
					<div class="form-group" id="coin-price-group" hidden>
						<label for="chapter-coin-price" class="form-label"><?php esc_html_e( 'Coin Price', 'starter-theme' ); ?></label>
						<input type="number" id="chapter-coin-price" name="coin_price" class="form-control" min="1" value="10">
					</div>
				</div>

				<div class="form-group">
					<label for="chapter-publish-date" class="form-label"><?php esc_html_e( 'Publish Date', 'starter-theme' ); ?></label>
					<input type="datetime-local" id="chapter-publish-date" name="publish_date" class="form-control"
					       value="<?php echo esc_attr( date( 'Y-m-d\TH:i' ) ); ?>">
					<small><?php esc_html_e( 'Leave in the future to schedule.', 'starter-theme' ); ?></small>
				</div>

				<div class="upload-form__footer">
					<div id="upload-chapter-progress" class="upload-progress" hidden>
						<div class="upload-progress__bar"><div class="upload-progress__fill" id="chapter-progress-fill"></div></div>
						<span class="upload-progress__label" id="chapter-progress-label"></span>
					</div>
					<button type="submit" id="submit-chapter-btn" class="btn btn--primary btn--lg">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
						<?php esc_html_e( 'Upload Chapter', 'starter-theme' ); ?>
					</button>
					<div id="upload-chapter-result" class="upload-form__result" aria-live="polite"></div>
				</div>

			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ──────────────────────────────────────────────────────────────
	 * AJAX handlers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Submit a new manga post.
	 */
	public function ajax_submit_manga() {
		check_ajax_referer( self::NONCE_MANGA, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'starter-theme' ) ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( ! $title ) {
			wp_send_json_error( array( 'message' => __( 'Title is required.', 'starter-theme' ) ) );
		}

		$post_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'post_excerpt' => wp_trim_words( sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), 25, '…' ),
			'post_type'    => 'wp-manga',
			'post_status'  => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
			'post_author'  => get_current_user_id(),
		), true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		/* Meta */
		$meta_map = array(
			'author'            => '_author',
			'artist'            => '_artist',
			'status'            => '_status',
			'release_year'      => '_release_year',
			'content_type'      => '_content_type',
			'alternative_names' => '_alternative_names',
		);
		foreach ( $meta_map as $key => $meta_key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		/* Genres */
		if ( ! empty( $_POST['genres'] ) && is_array( $_POST['genres'] ) ) {
			$genre_ids = array_map( 'absint', $_POST['genres'] );
			wp_set_object_terms( $post_id, $genre_ids, 'genre' );
		}

		/* Cover image */
		if ( ! empty( $_POST['cover_attachment_id'] ) ) {
			$att_id = absint( $_POST['cover_attachment_id'] );
			set_post_thumbnail( $post_id, $att_id );
		}

		wp_send_json_success( array(
			'message'  => __( 'Manga submitted successfully!', 'starter-theme' ),
			'post_id'  => $post_id,
			'post_url' => get_permalink( $post_id ),
		) );
	}

	/**
	 * AJAX: Upload a chapter with images/ZIP/text.
	 */
	public function ajax_submit_chapter() {
		check_ajax_referer( self::NONCE_CHAPTER, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'starter-theme' ) ) );
		}

		$manga_id      = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$chapter_num   = isset( $_POST['chapter_number'] ) ? (float) $_POST['chapter_number'] : 0;
		$chapter_type  = isset( $_POST['chapter_type'] ) ? sanitize_key( wp_unslash( $_POST['chapter_type'] ) ) : 'image';

		if ( ! $manga_id || $chapter_num <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga or chapter number.', 'starter-theme' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'starter_chapters';

		$chapter_title = isset( $_POST['chapter_title'] )
			? sanitize_text_field( wp_unslash( $_POST['chapter_title'] ) )
			: 'Chapter ' . $chapter_num;

		$publish_date = isset( $_POST['publish_date'] )
			? sanitize_text_field( wp_unslash( $_POST['publish_date'] ) )
			: current_time( 'mysql' );

		$is_premium = ! empty( $_POST['is_premium'] ) ? 1 : 0;
		$coin_price = $is_premium ? absint( $_POST['coin_price'] ?? 10 ) : 0;

		/* Handle chapter content based on type */
		$chapter_data = array();

		if ( $chapter_type === 'text' && ! empty( $_POST['chapter_content'] ) ) {
			$chapter_data = array( 'text' => wp_kses_post( wp_unslash( $_POST['chapter_content'] ) ) );
		} elseif ( $chapter_type === 'video' && ! empty( $_POST['video_url'] ) ) {
			$chapter_data = array( 'video_url' => esc_url_raw( wp_unslash( $_POST['video_url'] ) ) );
		} elseif ( $chapter_type === 'zip' && ! empty( $_FILES['chapter_zip']['tmp_name'] ) ) {
			/* Process ZIP via import class */
			if ( class_exists( 'Starter_Manga_Import' ) ) {
				$result = Starter_Manga_Import::get_instance()->process_zip(
					$_FILES['chapter_zip'],
					$manga_id,
					$chapter_num
				);
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
				$chapter_data = $result;
			}
		} elseif ( ! empty( $_FILES['chapter_images']['name'][0] ) ) {
			/* Multiple image upload */
			$images = array();
			$files  = $_FILES['chapter_images'];
			$count  = count( $files['name'] );

			for ( $i = 0; $i < $count; $i++ ) {
				$single = array(
					'name'     => $files['name'][ $i ],
					'type'     => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);

				$attachment_id = $this->upload_image( $single, $manga_id );
				if ( ! is_wp_error( $attachment_id ) ) {
					$images[] = array(
						'attachment_id' => $attachment_id,
						'url'           => wp_get_attachment_url( $attachment_id ),
					);
				}
			}
			$chapter_data = array( 'images' => $images );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'manga_id'       => $manga_id,
				'chapter_number' => $chapter_num,
				'chapter_title'  => $chapter_title,
				'chapter_slug'   => sanitize_title( $chapter_title . '-' . $chapter_num ),
				'chapter_type'   => $chapter_type,
				'chapter_data'   => wp_json_encode( $chapter_data ),
				'chapter_status' => 'publish',
				'is_premium'     => $is_premium,
				'coin_price'     => $coin_price,
				'publish_date'   => $publish_date,
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save chapter. Please try again.', 'starter-theme' ) ) );
		}

		$chapter_id = $wpdb->insert_id;

		/* Fire action for webhook / RSS / notifications */
		do_action( 'starter_chapter_published', $chapter_id, $manga_id, $chapter_num );

		wp_send_json_success( array(
			'message'    => __( 'Chapter uploaded successfully!', 'starter-theme' ),
			'chapter_id' => $chapter_id,
		) );
	}

	/**
	 * AJAX: Upload cover image (returns attachment ID + URL).
	 */
	public function ajax_upload_cover() {
		check_ajax_referer( 'starter_upload_cover_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'starter-theme' ) ) );
		}

		if ( empty( $_FILES['cover_image']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'starter-theme' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'cover_image', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		wp_send_json_success( array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_image_url( $attachment_id, 'medium_large' ),
			'full_url'      => wp_get_attachment_url( $attachment_id ),
		) );
	}

	/**
	 * AJAX: Pre-fill manga fields from a MangaUpdates URL.
	 */
	public function ajax_prefill_mangaupdates() {
		check_ajax_referer( 'starter_mangaupdates_prefill', 'nonce' );

		$url = isset( $_POST['mu_url'] ) ? esc_url_raw( wp_unslash( $_POST['mu_url'] ) ) : '';

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid URL.', 'starter-theme' ) ) );
		}

		if ( class_exists( 'Starter_MangaUpdates_API' ) ) {
			$api    = Starter_MangaUpdates_API::get_instance();
			$result = $api->fetch_by_url( $url );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}

		wp_send_json_error( array( 'message' => __( 'MangaUpdates integration not available.', 'starter-theme' ) ) );
	}

	/**
	 * AJAX: Get the current user's submitted manga list.
	 */
	public function ajax_get_user_manga_list() {
		check_ajax_referer( self::NONCE_MANGA, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error();
		}

		$posts = get_posts( array(
			'post_type'      => 'wp-manga',
			'posts_per_page' => 50,
			'author'         => get_current_user_id(),
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$list = array_map( function( $p ) {
			return array(
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'url'    => get_permalink( $p->ID ),
			);
		}, $posts );

		wp_send_json_success( $list );
	}

	/**
	 * AJAX: Delete a user's own manga (pending review only).
	 */
	public function ajax_delete_user_manga() {
		check_ajax_referer( self::NONCE_MANGA, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error();
		}

		$post = get_post( $post_id );

		if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter-theme' ) ) );
		}

		wp_trash_post( $post_id );
		wp_send_json_success();
	}

	/* ──────────────────────────────────────────────────────────────
	 * Private helpers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Upload a single image to the media library.
	 *
	 * @param array $file_array Single file from $_FILES.
	 * @param int   $post_id    Attachment parent post ID.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	private function upload_image( $file_array, $post_id = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = array( 'test_form' => false );
		$file      = wp_handle_upload( $file_array, $overrides );

		if ( isset( $file['error'] ) ) {
			return new WP_Error( 'upload_error', $file['error'] );
		}

		$attachment = array(
			'guid'           => $file['url'],
			'post_mime_type' => $file['type'],
			'post_title'     => sanitize_file_name( $file_array['name'] ),
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $file['file'], $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			wp_update_attachment_metadata(
				$attachment_id,
				wp_generate_attachment_metadata( $attachment_id, $file['file'] )
			);
		}

		return $attachment_id;
	}

	/**
	 * Render a "please log in" prompt.
	 *
	 * @return string HTML.
	 */
	private function render_login_prompt() {
		return sprintf(
			'<div class="alpha-notice alpha-notice--info"><p>%s <a href="%s">%s</a> %s <a href="%s">%s</a>.</p></div>',
			esc_html__( 'Please', 'starter-theme' ),
			esc_url( wp_login_url( get_permalink() ) ),
			esc_html__( 'log in', 'starter-theme' ),
			esc_html__( 'or', 'starter-theme' ),
			esc_url( wp_registration_url() ),
			esc_html__( 'register', 'starter-theme' )
		);
	}
}

/* Auto-instantiate. */
Starter_User_Upload::get_instance();
