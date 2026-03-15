<?php
/**
 * Template: Upload Chapter (Frontend)
 *
 * Allows manga uploaders to add new chapters to their manga.
 * Supports image multi-upload, ZIP extraction, text input, video URLs,
 * and scheduled publishing.
 *
 * Template Name: Upload Chapter
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

/* Derive manga context from query param */
$manga_id   = isset( $_GET['manga_id'] ) ? absint( $_GET['manga_id'] ) : 0;
$manga_post = $manga_id ? get_post( $manga_id ) : null;
?>

<main id="primary" class="site-main upload-page" role="main">
	<div class="container">

		<!-- Breadcrumb -->
		<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'starter-theme' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'starter-theme' ); ?></a>
			<span aria-hidden="true">›</span>
			<?php if ( $manga_post ) : ?>
				<a href="<?php echo esc_url( get_permalink( $manga_post ) ); ?>"><?php echo esc_html( $manga_post->post_title ); ?></a>
				<span aria-hidden="true">›</span>
			<?php endif; ?>
			<span><?php esc_html_e( 'Upload Chapter', 'starter-theme' ); ?></span>
		</nav>

		<div class="page-header">
			<div class="page-header__text">
				<h1 class="page-header__title">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
					<?php esc_html_e( 'Upload New Chapter', 'starter-theme' ); ?>
					<?php if ( $manga_post ) : ?>
						<span class="chapter-for">— <?php echo esc_html( $manga_post->post_title ); ?></span>
					<?php endif; ?>
				</h1>
			</div>
		</div>

		<div class="upload-page__layout">
			<div class="upload-page__main">
				<?php
				$shortcode = '[alpha_upload_chapter' . ( $manga_id ? ' manga_id="' . $manga_id . '"' : '' ) . ']';
				echo do_shortcode( $shortcode );
				?>
			</div>

			<aside class="upload-page__sidebar">
				<?php if ( $manga_post ) : ?>
				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'Uploading to', 'starter-theme' ); ?></h3>
					<div class="chapter-context-card">
						<?php
						$cover = get_the_post_thumbnail_url( $manga_post->ID, 'thumbnail' );
						?>
						<?php if ( $cover ) : ?>
							<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $manga_post->post_title ); ?>" class="chapter-context-card__cover">
						<?php endif; ?>
						<div class="chapter-context-card__info">
							<strong><?php echo esc_html( $manga_post->post_title ); ?></strong>
							<?php
							global $wpdb;
							$ch_count = $wpdb->get_var( $wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}starter_chapters WHERE manga_id = %d",
								$manga_post->ID
							) );
							?>
							<span><?php printf( esc_html__( '%d chapters', 'starter-theme' ), $ch_count ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $manga_post->ID ) ); ?>" class="chapter-context-card__link">
								<?php esc_html_e( 'View Manga →', 'starter-theme' ); ?>
							</a>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'Chapter Naming Tips', 'starter-theme' ); ?></h3>
					<ul class="upload-sidebar-list">
						<li>
							<span class="upload-sidebar-list__icon">💡</span>
							<?php esc_html_e( 'Name image files 001.jpg, 002.jpg … for correct order.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">💡</span>
							<?php esc_html_e( 'ZIP contents should be at the root — no sub-folders.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">💡</span>
							<?php esc_html_e( 'Chapter 0 = prologue, decimal chapters (1.5) for extras.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">💡</span>
							<?php esc_html_e( 'Supported video hosts: YouTube, Pixeldrain, Google Drive.', 'starter-theme' ); ?>
						</li>
					</ul>
				</div>

				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'Max Upload Size', 'starter-theme' ); ?></h3>
					<p style="font-size:14px;font-weight:700;color:var(--color-primary,#6c5ce7);">
						<?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>
					</p>
					<p style="font-size:12px;color:var(--color-text-muted,#777);">
						<?php esc_html_e( 'Set by your server (php.ini). Ask your host if you need more.', 'starter-theme' ); ?>
					</p>
				</div>
			</aside>
		</div>

	</div><!-- .container -->
</main>

<style>
.chapter-for { font-size:1rem;font-weight:500;color:var(--color-text-secondary,#aaa);margin-left:4px; }
.chapter-context-card { display:flex;align-items:center;gap:12px; }
.chapter-context-card__cover { width:50px;height:70px;object-fit:cover;border-radius:6px;flex-shrink:0; }
.chapter-context-card__info { display:flex;flex-direction:column;gap:4px;font-size:13px; }
.chapter-context-card__link { font-size:12px;color:var(--color-primary,#6c5ce7); }
.chapter-context-card__link:hover { text-decoration:underline; }
</style>

<?php get_footer(); ?>
