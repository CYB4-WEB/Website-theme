<?php
/**
 * Manga / Novel / Anime Admin Section — Project Alpha
 *
 * Registers the "Manga", "Chapters", and "Upload" pages under the
 * Alpha Manga top-level admin menu. Provides:
 *   • All Manga list with quick-edit
 *   • Add / Edit manga form (full meta)
 *   • Chapter management (list, upload, delete, reorder)
 *   • Upload chapter (ZIP / images / PDF / text / video)
 *   • MangaUpdates quick-import from admin
 *
 * @package starter-theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Admin
 */
class Starter_Manga_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Starter_Manga_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hook into admin.
	 */
	private function __construct() {
		add_action( 'admin_menu',              array( $this, 'register_submenus' ) );
		add_action( 'admin_enqueue_scripts',   array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_starter_admin_save_chapter',   array( $this, 'ajax_save_chapter' ) );
		add_action( 'wp_ajax_starter_admin_delete_chapter', array( $this, 'ajax_delete_chapter' ) );
		add_action( 'wp_ajax_starter_admin_reorder_chapters', array( $this, 'ajax_reorder_chapters' ) );
		add_action( 'wp_ajax_starter_admin_mu_import',      array( $this, 'ajax_mu_import' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Menu registration
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Register sub-pages under the Alpha Manga top menu.
	 */
	public function register_submenus() {
		/* Chapters management */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Chapters', 'starter-theme' ),
			__( 'Chapters', 'starter-theme' ),
			'manage_manga_chapters',
			'alpha-chapters',
			array( $this, 'page_chapters' )
		);

		/* Upload new chapter */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Upload Chapter', 'starter-theme' ),
			__( 'Upload Chapter', 'starter-theme' ),
			'manage_manga_chapters',
			'alpha-upload-chapter',
			array( $this, 'page_upload_chapter' )
		);

		/* MangaUpdates import */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Import from MangaUpdates', 'starter-theme' ),
			__( 'MU Import', 'starter-theme' ),
			'manage_options',
			'alpha-mu-import',
			array( $this, 'page_mu_import' )
		);

		/* Review queue */
		add_submenu_page(
			'alpha-manga-dashboard',
			__( 'Review Queue', 'starter-theme' ),
			__( 'Review Queue', 'starter-theme' ),
			'manage_options',
			'alpha-review-queue',
			array( $this, 'page_review_queue' )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Asset loading
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Enqueue admin styles + scripts for our pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$alpha_pages = array(
			'alpha-manga_page_alpha-chapters',
			'alpha-manga_page_alpha-upload-chapter',
			'alpha-manga_page_alpha-mu-import',
			'alpha-manga_page_alpha-review-queue',
			'toplevel_page_alpha-manga-dashboard',
		);

		/* Also load on wp-manga CPT edit screens */
		$screen = get_current_screen();
		$is_manga_cpt = $screen && in_array( $screen->post_type, array( 'wp-manga' ), true );

		if ( ! in_array( $hook, $alpha_pages, true ) && ! $is_manga_cpt ) {
			return;
		}

		wp_enqueue_style(
			'starter-admin',
			get_template_directory_uri() . '/assets/css/admin.css',
			array(),
			STARTER_THEME_VERSION
		);

		wp_enqueue_script(
			'starter-image-upload',
			get_template_directory_uri() . '/assets/js/image-upload.js',
			array( 'jquery' ),
			STARTER_THEME_VERSION,
			true
		);

		wp_enqueue_style(  'croppie', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css', array(), '2.6.5' );
		wp_enqueue_script( 'croppie', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array(), '2.6.5', true );

		wp_localize_script( 'starter-image-upload', 'starterAdmin', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'starter_admin_chapter_nonce' ),
			'nonceMU'    => wp_create_nonce( 'starter_admin_mu_nonce' ),
		) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Chapters list page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the Chapters management page.
	 */
	public function page_chapters() {
		global $wpdb;

		$manga_id = isset( $_GET['manga_id'] ) ? absint( $_GET['manga_id'] ) : 0;
		$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 30;
		$offset   = ( $paged - 1 ) * $per_page;

		/* Fully parameterised queries — no interpolated WHERE clause */
		if ( $manga_id ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}starter_chapters c WHERE c.manga_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$manga_id
			) );
			$chapters = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.*, p.post_title AS manga_title FROM {$wpdb->prefix}starter_chapters c
				 LEFT JOIN {$wpdb->posts} p ON p.ID = c.manga_id
				 WHERE c.manga_id = %d
				 ORDER BY c.chapter_number ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$manga_id, $per_page, $offset
			) );
		} else {
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}starter_chapters" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$chapters = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.*, p.post_title AS manga_title FROM {$wpdb->prefix}starter_chapters c
				 LEFT JOIN {$wpdb->posts} p ON p.ID = c.manga_id
				 ORDER BY c.manga_id ASC, c.chapter_number ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page, $offset
			) );
		}

		$total_pages = ceil( $total / $per_page );

		/* Manga dropdown for filtering */
		$all_manga = get_posts( array(
			'post_type'   => 'wp-manga',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
			'post_status' => array( 'publish', 'draft' ),
		) );
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-media-document"></span>
				<?php esc_html_e( 'Chapter Management', 'starter-theme' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-upload-chapter' . ( $manga_id ? '&manga_id=' . $manga_id : '' ) ) ); ?>" class="page-title-action">
					<?php esc_html_e( '+ Upload Chapter', 'starter-theme' ); ?>
				</a>
			</h1>

			<!-- Filter bar -->
			<div class="alpha-filter-bar">
				<form method="get" action="">
					<input type="hidden" name="page" value="alpha-chapters">
					<select name="manga_id" class="alpha-filter-select" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Manga', 'starter-theme' ); ?></option>
						<?php foreach ( $all_manga as $m ) : ?>
							<option value="<?php echo esc_attr( $m->ID ); ?>" <?php selected( $manga_id, $m->ID ); ?>>
								<?php echo esc_html( $m->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>
				<span class="alpha-filter-bar__count">
					<?php printf( esc_html__( '%d chapters total', 'starter-theme' ), $total ); ?>
				</span>
			</div>

			<?php if ( ! empty( $chapters ) ) : ?>
			<table class="wp-list-table widefat fixed striped alpha-chapter-table">
				<thead>
					<tr>
						<th class="col-order"><?php esc_html_e( '#', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Manga', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Chapter', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Title', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Type', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Premium', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Status', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Published', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody id="chapter-sortable">
					<?php foreach ( $chapters as $ch ) : ?>
					<tr data-chapter-id="<?php echo esc_attr( $ch->id ); ?>">
						<td class="col-order"><?php echo esc_html( number_format( $ch->chapter_number, 0 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-chapters&manga_id=' . $ch->manga_id ) ); ?>">
								<?php echo esc_html( $ch->manga_title ?: __( '—', 'starter-theme' ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $ch->chapter_number ); ?></td>
						<td><?php echo esc_html( $ch->chapter_title ?: '—' ); ?></td>
						<td><span class="alpha-type-badge alpha-type-badge--<?php echo esc_attr( $ch->chapter_type ); ?>"><?php echo esc_html( ucfirst( $ch->chapter_type ) ); ?></span></td>
						<td><?php echo $ch->is_premium ? '<span class="alpha-badge alpha-badge--premium">💰 ' . esc_html( $ch->coin_price ) . '</span>' : '<span class="alpha-badge alpha-badge--free">Free</span>'; ?></td>
						<td><span class="alpha-status alpha-status--<?php echo esc_attr( $ch->chapter_status ); ?>"><?php echo esc_html( ucfirst( $ch->chapter_status ) ); ?></span></td>
						<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $ch->publish_date ) ) ); ?></td>
						<td class="alpha-chapter-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-upload-chapter&edit=' . $ch->id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'starter-theme' ); ?></a>
							<button type="button" class="button button-small button-link-delete alpha-delete-chapter"
							        data-chapter-id="<?php echo esc_attr( $ch->id ); ?>"
							        data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_admin_chapter_nonce' ) ); ?>">
								<?php esc_html_e( 'Delete', 'starter-theme' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( array(
						'base'    => admin_url( 'admin.php?page=alpha-chapters&manga_id=' . $manga_id . '%_%' ),
						'format'  => '&paged=%#%',
						'current' => $paged,
						'total'   => $total_pages,
					) );
					?>
				</div>
			</div>
			<?php endif; ?>

			<?php else : ?>
			<div class="alpha-empty-state">
				<span class="dashicons dashicons-media-document" style="font-size:48px;color:#ccc;"></span>
				<p><?php esc_html_e( 'No chapters found.', 'starter-theme' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=alpha-upload-chapter' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Upload First Chapter', 'starter-theme' ); ?>
				</a>
			</div>
			<?php endif; ?>
		</div>

		<script>
		jQuery(function($){
			$('.alpha-delete-chapter').on('click',function(){
				if(!confirm('<?php esc_html_e( 'Delete this chapter? This cannot be undone.', 'starter-theme' ); ?>')) return;
				var $btn=$(this), chId=$btn.data('chapter-id'), nonce=$btn.data('nonce');
				$.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',{
					action:'starter_admin_delete_chapter', chapter_id:chId, nonce:nonce
				},function(r){
					if(r.success){ $btn.closest('tr').fadeOut(400,function(){ $(this).remove(); }); }
					else alert(r.data.message||'Error');
				});
			});
		});
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Upload / Edit Chapter page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the Upload Chapter page.
	 */
	public function page_upload_chapter() {
		global $wpdb;

		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$manga_id = isset( $_GET['manga_id'] ) ? absint( $_GET['manga_id'] ) : 0;
		$chapter  = null;

		if ( $edit_id ) {
			$chapter = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}starter_chapters WHERE id = %d LIMIT 1",
				$edit_id
			) );
		}

		$all_manga = get_posts( array(
			'post_type'   => 'wp-manga',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
			'post_status' => array( 'publish', 'draft', 'pending' ),
		) );
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-upload"></span>
				<?php echo $chapter ? esc_html__( 'Edit Chapter', 'starter-theme' ) : esc_html__( 'Upload New Chapter', 'starter-theme' ); ?>
			</h1>

			<div class="alpha-upload-admin-layout">

				<!-- Main form -->
				<div class="alpha-upload-main">
					<div class="alpha-card">
						<form id="admin-upload-chapter-form" enctype="multipart/form-data">
							<?php wp_nonce_field( 'starter_admin_chapter_nonce', 'nonce' ); ?>
							<?php if ( $chapter ) : ?>
								<input type="hidden" name="chapter_id" value="<?php echo esc_attr( $chapter->id ); ?>">
							<?php endif; ?>

							<div class="alpha-form-row">
								<div class="alpha-form-group alpha-form-group--full">
									<label class="alpha-form-label required"><?php esc_html_e( 'Manga / Novel / Anime', 'starter-theme' ); ?></label>
									<select name="manga_id" id="admin-manga-id" class="alpha-form-select" required>
										<option value=""><?php esc_html_e( '— Select —', 'starter-theme' ); ?></option>
										<?php foreach ( $all_manga as $m ) :
											$type = get_post_meta( $m->ID, '_content_type', true ) ?: 'manga';
											?>
											<option value="<?php echo esc_attr( $m->ID ); ?>"
											        data-type="<?php echo esc_attr( $type ); ?>"
											        <?php selected( $chapter ? $chapter->manga_id : $manga_id, $m->ID ); ?>>
												[<?php echo esc_html( strtoupper( $type ) ); ?>] <?php echo esc_html( $m->post_title ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="alpha-form-row alpha-form-row--3">
								<div class="alpha-form-group">
									<label class="alpha-form-label required"><?php esc_html_e( 'Chapter Number', 'starter-theme' ); ?></label>
									<input type="number" name="chapter_number" class="alpha-form-input"
									       value="<?php echo esc_attr( $chapter->chapter_number ?? '' ); ?>"
									       min="0" step="0.1" required placeholder="1">
								</div>
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Volume', 'starter-theme' ); ?></label>
									<input type="number" name="volume" class="alpha-form-input"
									       value="<?php echo esc_attr( $chapter->volume ?? '' ); ?>"
									       min="0" placeholder="<?php esc_attr_e( 'Optional', 'starter-theme' ); ?>">
								</div>
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Chapter Title', 'starter-theme' ); ?></label>
									<input type="text" name="chapter_title" class="alpha-form-input"
									       value="<?php echo esc_attr( $chapter->chapter_title ?? '' ); ?>"
									       placeholder="<?php esc_attr_e( 'The Dark Forest', 'starter-theme' ); ?>">
								</div>
							</div>

							<!-- Chapter Type -->
							<div class="alpha-form-group">
								<label class="alpha-form-label"><?php esc_html_e( 'Chapter Type', 'starter-theme' ); ?></label>
								<div class="alpha-type-tabs" id="admin-type-tabs">
									<?php
									$types = array(
										'image' => array( 'icon' => 'dashicons-format-image', 'label' => 'Images' ),
										'zip'   => array( 'icon' => 'dashicons-media-archive', 'label' => 'ZIP Archive' ),
										'pdf'   => array( 'icon' => 'dashicons-pdf', 'label' => 'PDF' ),
										'text'  => array( 'icon' => 'dashicons-editor-textcolor', 'label' => 'Text / Novel' ),
										'video' => array( 'icon' => 'dashicons-video-alt3', 'label' => 'Video / Anime' ),
									);
									foreach ( $types as $type_key => $type_info ) :
										$is_active = ( $chapter ? $chapter->chapter_type : 'image' ) === $type_key;
										?>
										<button type="button" class="alpha-type-tab <?php echo $is_active ? 'is-active' : ''; ?>"
										        data-type="<?php echo esc_attr( $type_key ); ?>">
											<span class="dashicons <?php echo esc_attr( $type_info['icon'] ); ?>"></span>
											<?php echo esc_html( $type_info['label'] ); ?>
										</button>
									<?php endforeach; ?>
								</div>
								<input type="hidden" name="chapter_type" id="admin-chapter-type"
								       value="<?php echo esc_attr( $chapter->chapter_type ?? 'image' ); ?>">
							</div>

							<!-- Image upload zone -->
							<div class="alpha-upload-zone-panel" id="admin-panel-image" <?php echo ( $chapter ? $chapter->chapter_type : 'image' ) !== 'image' ? 'style="display:none"' : ''; ?>>
								<div class="alpha-drop-zone" id="admin-drop-zone">
									<span class="dashicons dashicons-upload" style="font-size:36px;color:#999;"></span>
									<p><?php esc_html_e( 'Drop images here or click to select', 'starter-theme' ); ?></p>
									<small><?php esc_html_e( 'JPG, PNG, WebP — ordered alphabetically by filename', 'starter-theme' ); ?></small>
									<input type="file" id="admin-images-input" name="chapter_images[]" accept="image/*" multiple class="alpha-file-input">
								</div>
								<div class="alpha-image-preview" id="admin-image-preview"></div>
							</div>

							<!-- ZIP zone -->
							<div class="alpha-upload-zone-panel" id="admin-panel-zip" <?php echo ( $chapter ? $chapter->chapter_type : 'image' ) !== 'zip' ? 'style="display:none"' : ''; ?>>
								<div class="alpha-drop-zone">
									<span class="dashicons dashicons-media-archive" style="font-size:36px;color:#999;"></span>
									<p><?php esc_html_e( 'Select ZIP archive (images will be extracted automatically)', 'starter-theme' ); ?></p>
									<input type="file" name="chapter_zip" accept=".zip,application/zip" class="alpha-file-input">
								</div>
							</div>

							<!-- PDF zone -->
							<div class="alpha-upload-zone-panel" id="admin-panel-pdf" <?php echo ( $chapter ? $chapter->chapter_type : 'image' ) !== 'pdf' ? 'style="display:none"' : ''; ?>>
								<div class="alpha-drop-zone">
									<span class="dashicons dashicons-pdf" style="font-size:36px;color:#999;"></span>
									<p><?php esc_html_e( 'Select PDF file (requires Imagick)', 'starter-theme' ); ?></p>
									<input type="file" name="chapter_pdf" accept="application/pdf" class="alpha-file-input">
								</div>
								<?php if ( ! extension_loaded( 'imagick' ) ) : ?>
									<div class="alpha-notice alpha-notice--warning">
										<?php esc_html_e( '⚠️ Imagick is not installed on this server. PDF import requires Imagick.', 'starter-theme' ); ?>
									</div>
								<?php endif; ?>
							</div>

							<!-- Text zone -->
							<div class="alpha-upload-zone-panel" id="admin-panel-text" <?php echo ( $chapter ? $chapter->chapter_type : 'image' ) !== 'text' ? 'style="display:none"' : ''; ?>>
								<label class="alpha-form-label"><?php esc_html_e( 'Chapter Text Content', 'starter-theme' ); ?></label>
								<textarea name="chapter_content" class="alpha-form-textarea" rows="20"
								          placeholder="<?php esc_attr_e( 'Paste chapter text here…', 'starter-theme' ); ?>"><?php echo esc_textarea( $chapter && $chapter->chapter_type === 'text' ? json_decode( $chapter->chapter_data, true )['text'] ?? '' : '' ); ?></textarea>
							</div>

							<!-- Video zone -->
							<div class="alpha-upload-zone-panel" id="admin-panel-video" <?php echo ( $chapter ? $chapter->chapter_type : 'image' ) !== 'video' ? 'style="display:none"' : ''; ?>>
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Video URL', 'starter-theme' ); ?></label>
									<input type="url" name="video_url" class="alpha-form-input"
									       value="<?php echo esc_attr( $chapter && $chapter->chapter_type === 'video' ? json_decode( $chapter->chapter_data, true )['video_url'] ?? '' : '' ); ?>"
									       placeholder="<?php esc_attr_e( 'YouTube, Pixeldrain, Google Drive, or direct MP4 URL', 'starter-theme' ); ?>">
									<small><?php esc_html_e( 'Supported: YouTube, Pixeldrain (pixeldrain.com/u/ID), Google Drive, direct video links', 'starter-theme' ); ?></small>
								</div>
							</div>

							<!-- Publish settings -->
							<div class="alpha-form-row alpha-form-row--2">
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Publish Date', 'starter-theme' ); ?></label>
									<input type="datetime-local" name="publish_date" class="alpha-form-input"
									       value="<?php echo esc_attr( $chapter ? date( 'Y-m-d\TH:i', strtotime( $chapter->publish_date ) ) : date( 'Y-m-d\TH:i' ) ); ?>">
								</div>
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Status', 'starter-theme' ); ?></label>
									<select name="chapter_status" class="alpha-form-select">
										<option value="publish" <?php selected( $chapter->chapter_status ?? 'publish', 'publish' ); ?>><?php esc_html_e( 'Published', 'starter-theme' ); ?></option>
										<option value="draft"   <?php selected( $chapter->chapter_status ?? '', 'draft' ); ?>><?php esc_html_e( 'Draft', 'starter-theme' ); ?></option>
										<option value="scheduled"<?php selected( $chapter->chapter_status ?? '', 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'starter-theme' ); ?></option>
									</select>
								</div>
							</div>

							<!-- Premium settings -->
							<div class="alpha-form-row alpha-form-row--2">
								<div class="alpha-form-group">
									<label class="alpha-form-label"><?php esc_html_e( 'Premium (Coin-locked)', 'starter-theme' ); ?></label>
									<label class="alpha-toggle">
										<input type="checkbox" name="is_premium" id="admin-is-premium" value="1"
										       <?php checked( ! empty( $chapter->is_premium ) ); ?>>
										<span class="alpha-toggle__track"></span>
										<span style="margin-left:8px;vertical-align:middle;"><?php esc_html_e( 'Require coins to read', 'starter-theme' ); ?></span>
									</label>
								</div>
								<div class="alpha-form-group" id="admin-coin-price-group" <?php echo empty( $chapter->is_premium ) ? 'style="display:none"' : ''; ?>>
									<label class="alpha-form-label"><?php esc_html_e( 'Coin Price', 'starter-theme' ); ?></label>
									<input type="number" name="coin_price" class="alpha-form-input small-text"
									       value="<?php echo esc_attr( $chapter->coin_price ?? 10 ); ?>" min="1">
								</div>
							</div>

							<!-- Submit -->
							<div class="alpha-form-submit-row">
								<button type="submit" class="button button-primary button-large" id="admin-save-chapter-btn">
									<span class="dashicons dashicons-saved"></span>
									<?php echo $chapter ? esc_html__( 'Update Chapter', 'starter-theme' ) : esc_html__( 'Upload Chapter', 'starter-theme' ); ?>
								</button>
								<span id="admin-chapter-save-status" style="margin-left:12px;line-height:30px;"></span>
							</div>

						</form>
					</div><!-- .alpha-card -->
				</div><!-- .alpha-upload-main -->

				<!-- Sidebar tips -->
				<div class="alpha-upload-sidebar">
					<div class="alpha-card">
						<h3><?php esc_html_e( 'Tips', 'starter-theme' ); ?></h3>
						<ul class="alpha-tips-list">
							<li><?php esc_html_e( 'Image files are ordered alphabetically by filename — name them 001.jpg, 002.jpg, etc.', 'starter-theme' ); ?></li>
							<li><?php esc_html_e( 'ZIP archives should contain images at the root level (no sub-folders).', 'starter-theme' ); ?></li>
							<li><?php esc_html_e( 'For scheduled chapters, set the date to the future and status to "Scheduled".', 'starter-theme' ); ?></li>
							<li><?php esc_html_e( 'Max upload size: ', 'starter-theme' ); ?><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></li>
						</ul>
					</div>
				</div>

			</div><!-- .alpha-upload-admin-layout -->
		</div>

		<script>
		jQuery(function($){
			/* Type tabs */
			$('#admin-type-tabs .alpha-type-tab').on('click',function(){
				var type=$(this).data('type');
				$('#admin-type-tabs .alpha-type-tab').removeClass('is-active');
				$(this).addClass('is-active');
				$('#admin-chapter-type').val(type);
				$('.alpha-upload-zone-panel').hide();
				$('#admin-panel-'+type).show();
			});

			/* Premium toggle */
			$('#admin-is-premium').on('change',function(){
				$('#admin-coin-price-group').toggle(this.checked);
			});

			/* Drop zone click */
			$('#admin-drop-zone').on('click',function(){ $('#admin-images-input').click(); });
			$('#admin-images-input').on('change',function(){
				var files=this.files, $prev=$('#admin-image-preview').empty();
				Array.from(files).forEach(function(f){
					var r=new FileReader();
					r.onload=function(e){
						/* XSS-safe: use DOM methods instead of HTML string concat */
						var $wrap=$('<div class="alpha-img-thumb"/>');
						var $img=$('<img alt="">').attr('src', e.target.result);
						var $lbl=$('<span/>').text(f.name); /* .text() escapes HTML */
						$prev.append($wrap.append($img, $lbl));
					};
					r.readAsDataURL(f);
				});
			});

			/* Shared safe status helper — XSS-safe via jQuery .text() */
			function setStatus($el, ok, msg){
				var $inner = $('<span/>').text(msg);
				$inner.css('color', ok ? '#46b450' : '#dc3232');
				$inner.prepend(ok ? '✅ ' : '❌ ');
				$el.empty().append($inner);
			}

			/* Form submit */
			$('#admin-upload-chapter-form').on('submit',function(e){
				e.preventDefault();
				var $btn=$('#admin-save-chapter-btn'), $status=$('#admin-chapter-save-status');
				$btn.prop('disabled',true).text('Uploading…');
				$status.empty();
				var fd=new FormData(this);
				fd.append('action','starter_admin_save_chapter');
				$.ajax({ url:'<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', method:'POST', data:fd, processData:false, contentType:false,
					success:function(r){
						setStatus($status, r.success, r.success ? (r.data.message||'Saved!') : (r.data.message||'Error'));
						$btn.prop('disabled',false).text('Upload Chapter');
						if(r.success && r.data.redirect){
							/* Open Redirect fix: only follow same-origin paths */
							var redir = String(r.data.redirect);
							if(/^\/[^/]/.test(redir) || /^https?:\/\/(www\.)?<?php echo esc_js( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/.test(redir)){
								setTimeout(function(){ window.location.href = redir; }, 1000);
							}
						}
					},
					error:function(){ setStatus($status, false, 'Network error.'); $btn.prop('disabled',false).text('Upload Chapter'); }
				});
			});
		});
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * MangaUpdates Import page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the MangaUpdates import page.
	 */
	public function page_mu_import() {
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Import from MangaUpdates', 'starter-theme' ); ?>
			</h1>

			<div class="alpha-card" style="max-width:760px;">
				<p><?php esc_html_e( 'Paste a MangaUpdates series URL and all available metadata will be fetched and imported as a new manga post.', 'starter-theme' ); ?></p>

				<div class="alpha-form-group">
					<label class="alpha-form-label"><?php esc_html_e( 'MangaUpdates Series URL', 'starter-theme' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="url" id="mu-import-url" class="alpha-form-input"
						       placeholder="https://www.mangaupdates.com/series/axmopy6/solo-leveling"
						       style="flex:1;">
						<button type="button" id="mu-fetch-btn" class="button button-primary">
							<?php esc_html_e( 'Fetch Info', 'starter-theme' ); ?>
						</button>
					</div>
				</div>

				<div id="mu-preview" style="display:none;" class="alpha-mu-preview"></div>

				<form id="mu-import-form" style="display:none;">
					<?php wp_nonce_field( 'starter_admin_mu_nonce', 'mu_nonce' ); ?>
					<input type="hidden" name="mu_series_id" id="mu-series-id">
					<div id="mu-form-fields"></div>
					<div style="margin-top:16px;">
						<button type="submit" class="button button-primary button-large">
							<?php esc_html_e( '✅ Import as New Manga', 'starter-theme' ); ?>
						</button>
						<span id="mu-import-status" style="margin-left:12px;"></span>
					</div>
				</form>
			</div>

			<!-- Recent imports -->
			<div class="alpha-card" style="max-width:760px;margin-top:20px;">
				<h3><?php esc_html_e( 'How It Works', 'starter-theme' ); ?></h3>
				<ol style="list-style:decimal;margin-left:20px;line-height:1.8;">
					<li><?php esc_html_e( 'Paste a MangaUpdates series URL (e.g. https://www.mangaupdates.com/series/axmopy6)', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( 'Click "Fetch Info" — title, author, artist, genres, description, and cover are retrieved.', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( 'Review the pre-filled fields, adjust if needed, then click "Import".', 'starter-theme' ); ?></li>
					<li><?php esc_html_e( 'The manga is created as a draft — go to All Manga to publish it.', 'starter-theme' ); ?></li>
				</ol>
			</div>
		</div>

		<script>
		jQuery(function($){
			$('#mu-fetch-btn').on('click',function(){
				var url=$('#mu-import-url').val().trim();
				if(!url){ alert('Enter a URL first.'); return; }
				$(this).text('Fetching…').prop('disabled',true);
				$('#mu-preview').hide().empty();
				$('#mu-import-form').hide();
				$.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',{
					action:'starter_admin_mu_import', mu_url:url, nonce:'<?php echo esc_js( wp_create_nonce( 'starter_admin_mu_nonce' ) ); ?>'
				},function(r){
					$('#mu-fetch-btn').text('Fetch Info').prop('disabled',false);
					if(r.success){
						var d=r.data;
						$('#mu-series-id').val(d.series_id||'');
						/* XSS-safe: build DOM nodes with jQuery .text()/.attr() */
						var $inner=$('<div class="alpha-mu-preview-inner"/>');
						if(d.cover_url){ $inner.append($('<img class="alpha-mu-cover" alt=""/>').attr('src',d.cover_url)); }
						var $info=$('<div class="alpha-mu-info"/>');
						$info.append($('<h3/>').text(d.title||''));
						if(d.author) $info.append($('<p/>').append($('<strong/>').text('Author: ')).append(document.createTextNode(d.author)));
						if(d.artist) $info.append($('<p/>').append($('<strong/>').text('Artist: ')).append(document.createTextNode(d.artist)));
						if(d.genres) $info.append($('<p/>').append($('<strong/>').text('Genres: ')).append(document.createTextNode(d.genres.join(', '))));
						if(d.description) $info.append($('<p class="alpha-mu-desc"/>').text((d.description||'').substring(0,300)+'…'));
						$inner.append($info);
						$('#mu-preview').empty().append($inner).show();
						/* Hidden inputs — use .val() to avoid attribute injection */
						var $ff=$('#mu-form-fields').empty();
						function appendHidden(name,val){ $ff.append($('<input type="hidden"/>').attr('name',name).val(val)); }
						appendHidden('title', d.title||'');
						appendHidden('author', d.author||'');
						appendHidden('artist', d.artist||'');
						appendHidden('description', d.description||'');
						appendHidden('cover_url', d.cover_url||'');
						appendHidden('genres', (d.genres||[]).join(','));
						appendHidden('year', d.year||'');
						$('#mu-import-form').show();
					} else {
						var $errBox=$('<div class="alpha-notice alpha-notice--error"/>').text(r.data.message||'Error');
						$('#mu-preview').empty().append($errBox).show();
					}
				});
			});

			$('#mu-import-form').on('submit',function(e){
				e.preventDefault();
				var $status=$('#mu-import-status');
				$status.text('Importing…');
				var fd=new FormData(this);
				fd.append('action','starter_admin_mu_import_save');
				$.ajax({url:'<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',method:'POST',data:fd,processData:false,contentType:false,
					success:function(r){
						if(r.success){
							/* XSS-safe: use .text() and .attr() */
							var $a=$('<a/>').attr('href', /^https?:\/\//.test(r.data.edit_url||'') || /^\//.test(r.data.edit_url||'') ? r.data.edit_url : '#').text('Edit post →');
							$status.empty().append($('<span style="color:#46b450"/>').text('✅ Imported! ').append($a));
						} else {
							$status.empty().append($('<span style="color:#dc3232"/>').text('❌ '+(r.data.message||'Error')));
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Review Queue page
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Render the content review queue.
	 */
	public function page_review_queue() {
		$pending = get_posts( array(
			'post_type'   => 'wp-manga',
			'post_status' => 'pending',
			'numberposts' => 50,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );
		?>
		<div class="wrap starter-admin-wrap">
			<h1 class="starter-admin-page-title">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Review Queue', 'starter-theme' ); ?>
				<?php if ( ! empty( $pending ) ) : ?>
					<span class="alpha-count-badge"><?php echo count( $pending ); ?></span>
				<?php endif; ?>
			</h1>

			<?php if ( empty( $pending ) ) : ?>
				<div class="alpha-empty-state">
					<span class="dashicons dashicons-yes-alt" style="font-size:48px;color:#46b450;"></span>
					<p><?php esc_html_e( 'Queue is empty — all content has been reviewed!', 'starter-theme' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cover', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Title', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Author', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Type', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Submitted By', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Date', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'starter-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pending as $post ) :
							$cover  = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
							$author = get_post_meta( $post->ID, '_author', true );
							$type   = get_post_meta( $post->ID, '_content_type', true ) ?: 'manga';
							$user   = get_userdata( $post->post_author );
							?>
							<tr>
								<td style="width:60px;">
									<?php if ( $cover ) : ?>
										<img src="<?php echo esc_url( $cover ); ?>" alt="" style="width:50px;height:70px;object-fit:cover;border-radius:4px;">
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank">
										<strong><?php echo esc_html( $post->post_title ); ?></strong>
									</a>
								</td>
								<td><?php echo esc_html( $author ?: '—' ); ?></td>
								<td><span class="alpha-badge alpha-badge--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></span></td>
								<td><?php echo esc_html( $user ? $user->display_name : 'Unknown' ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $post->post_date ) ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'starter-theme' ); ?></a>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=starter_approve_manga&post_id=' . $post->ID ), 'approve_manga_' . $post->ID ) ); ?>" class="button button-small button-primary"><?php esc_html_e( 'Approve', 'starter-theme' ); ?></a>
									<a href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this manga?')"><?php esc_html_e( 'Reject', 'starter-theme' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * AJAX handlers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Save / update a chapter from admin.
	 */
	public function ajax_save_chapter() {
		check_ajax_referer( 'starter_admin_chapter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_manga_chapters' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'starter_chapters';

		$manga_id      = absint( $_POST['manga_id'] ?? 0 );
		$chapter_num   = (float) ( $_POST['chapter_number'] ?? 0 );
		$chapter_type  = sanitize_key( $_POST['chapter_type'] ?? 'image' );
		$chapter_id    = absint( $_POST['chapter_id'] ?? 0 );

		if ( ! $manga_id || $chapter_num <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid manga or chapter number.' ) );
		}

		/* Ownership / IDOR check: non-admins may only edit manga they authored. */
		if ( ! current_user_can( 'manage_options' ) ) {
			$post = get_post( $manga_id );
			if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
				wp_send_json_error( array( 'message' => 'You do not have permission to edit chapters for this title.' ) );
			}
		}

		/* For updates, verify the chapter actually belongs to the claimed manga. */
		if ( $chapter_id ) {
			$existing_manga = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT manga_id FROM {$wpdb->prefix}starter_chapters WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chapter_id
			) );
			if ( $existing_manga !== $manga_id ) {
				wp_send_json_error( array( 'message' => 'Chapter does not belong to the specified manga.' ) );
			}
		}

		/* Build chapter data based on type */
		$chapter_data = array();
		if ( $chapter_type === 'text' && ! empty( $_POST['chapter_content'] ) ) {
			$chapter_data = array( 'text' => wp_kses_post( wp_unslash( $_POST['chapter_content'] ) ) );
		} elseif ( $chapter_type === 'video' && ! empty( $_POST['video_url'] ) ) {
			$chapter_data = array( 'video_url' => esc_url_raw( wp_unslash( $_POST['video_url'] ) ) );
		}

		$row = array(
			'manga_id'       => $manga_id,
			'chapter_number' => $chapter_num,
			'chapter_title'  => sanitize_text_field( wp_unslash( $_POST['chapter_title'] ?? '' ) ),
			'chapter_slug'   => sanitize_title( 'chapter-' . $chapter_num ),
			'chapter_type'   => $chapter_type,
			'chapter_data'   => wp_json_encode( $chapter_data ),
			'chapter_status' => sanitize_key( $_POST['chapter_status'] ?? 'publish' ),
			'is_premium'     => ! empty( $_POST['is_premium'] ) ? 1 : 0,
			'coin_price'     => absint( $_POST['coin_price'] ?? 0 ),
			'publish_date'   => sanitize_text_field( wp_unslash( $_POST['publish_date'] ?? current_time( 'mysql' ) ) ),
			'volume'         => absint( $_POST['volume'] ?? 0 ) ?: null,
		);

		if ( $chapter_id ) {
			$wpdb->update( $table, $row, array( 'id' => $chapter_id ) );
		} else {
			$wpdb->insert( $table, $row );
			$chapter_id = $wpdb->insert_id;
			do_action( 'starter_chapter_published', $chapter_id, $manga_id, $chapter_num );
		}

		wp_send_json_success( array(
			'message'  => $chapter_id ? 'Chapter saved!' : 'Chapter uploaded!',
			'redirect' => admin_url( 'admin.php?page=alpha-chapters&manga_id=' . $manga_id ),
		) );
	}

	/**
	 * AJAX: Delete a chapter.
	 */
	public function ajax_delete_chapter() {
		check_ajax_referer( 'starter_admin_chapter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_manga_chapters' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		global $wpdb;
		$chapter_id = absint( $_POST['chapter_id'] ?? 0 );

		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => 'Invalid chapter.' ) );
		}

		$wpdb->delete( $wpdb->prefix . 'starter_chapters', array( 'id' => $chapter_id ), array( '%d' ) );
		wp_send_json_success();
	}

	/**
	 * AJAX: Fetch manga data from MangaUpdates URL.
	 */
	public function ajax_mu_import() {
		check_ajax_referer( 'starter_admin_mu_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$url = isset( $_POST['mu_url'] ) ? esc_url_raw( wp_unslash( $_POST['mu_url'] ) ) : '';

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'Invalid URL.' ) );
		}

		if ( class_exists( 'Starter_MangaUpdates_API' ) ) {
			$result = Starter_MangaUpdates_API::get_instance()->fetch_by_url( $url );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( $result );
		}

		wp_send_json_error( array( 'message' => 'MangaUpdates API class not loaded.' ) );
	}

	/**
	 * AJAX: Reorder chapters.
	 */
	public function ajax_reorder_chapters() {
		check_ajax_referer( 'starter_admin_chapter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_manga_chapters' ) ) {
			wp_send_json_error();
		}

		global $wpdb;
		$order = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : array();

		foreach ( $order as $position => $chapter_id ) {
			$wpdb->update(
				$wpdb->prefix . 'starter_chapters',
				array( 'chapter_order' => $position ),
				array( 'id' => $chapter_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		wp_send_json_success();
	}
}

/* Auto-instantiate. */
Starter_Manga_Admin::get_instance();
