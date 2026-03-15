<?php
/**
 * Template: Upload Manga (Frontend)
 *
 * Full-featured manga/novel/video submission page for registered users.
 * Supports MangaUpdates auto-fill, Croppie cover cropper, genre selection,
 * and chapter queue.
 *
 * Template Name: Upload Manga
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main upload-page" role="main">
	<div class="container">
		<div class="page-header page-header--upload">
			<div class="page-header__text">
				<h1 class="page-header__title">
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
					<?php esc_html_e( 'Upload Manga / Novel / Anime', 'starter-theme' ); ?>
				</h1>
				<p class="page-header__subtitle">
					<?php esc_html_e( 'Share your work with our community. All submissions are reviewed before publishing.', 'starter-theme' ); ?>
				</p>
			</div>

			<?php if ( is_user_logged_in() ) : ?>
				<div class="page-header__user-stats">
					<?php
					$user_posts = count_user_posts( get_current_user_id(), 'wp-manga' );
					?>
					<div class="user-stat">
						<span class="user-stat__val"><?php echo esc_html( $user_posts ); ?></span>
						<span class="user-stat__lbl"><?php esc_html_e( 'Uploaded', 'starter-theme' ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</div><!-- .page-header -->

		<div class="upload-page__layout">

			<!-- ── Main Form ─────────────────────────────────── -->
			<div class="upload-page__main">
				<?php
				if ( function_exists( 'Starter_User_Upload' ) || class_exists( 'Starter_User_Upload' ) ) {
					echo do_shortcode( '[alpha_upload_manga]' );
				} else {
					/* Inline fallback form */
					if ( ! is_user_logged_in() ) :
						?>
						<div class="auth-required-box glass-card">
							<div class="auth-required-box__icon">🔒</div>
							<h2><?php esc_html_e( 'Login Required', 'starter-theme' ); ?></h2>
							<p><?php esc_html_e( 'Please log in or create an account to upload manga.', 'starter-theme' ); ?></p>
							<div class="auth-required-box__actions">
								<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="btn btn--primary"><?php esc_html_e( 'Log In', 'starter-theme' ); ?></a>
								<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="btn btn--outline"><?php esc_html_e( 'Register', 'starter-theme' ); ?></a>
							</div>
						</div>
					<?php else : ?>
						<div class="alpha-notice alpha-notice--info">
							<?php esc_html_e( 'Upload module loading…', 'starter-theme' ); ?>
						</div>
					<?php endif;
				}
				?>
			</div><!-- .upload-page__main -->

			<!-- ── Sidebar Guidelines ───────────────────────── -->
			<aside class="upload-page__sidebar">

				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'Submission Guidelines', 'starter-theme' ); ?></h3>
					<ul class="upload-sidebar-list">
						<li>
							<span class="upload-sidebar-list__icon">✅</span>
							<?php esc_html_e( 'Original or translated content you have rights to upload.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">✅</span>
							<?php esc_html_e( 'Cover image must be at least 300×400 px.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">✅</span>
							<?php esc_html_e( 'ZIP files should contain images named 001.jpg, 002.jpg, etc.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">❌</span>
							<?php esc_html_e( 'Do not upload copyrighted content without permission.', 'starter-theme' ); ?>
						</li>
						<li>
							<span class="upload-sidebar-list__icon">❌</span>
							<?php esc_html_e( 'No spam, duplicate entries, or placeholder content.', 'starter-theme' ); ?>
						</li>
					</ul>
				</div>

				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'Supported Formats', 'starter-theme' ); ?></h3>
					<div class="format-grid">
						<div class="format-item">
							<span class="format-item__icon">🖼️</span>
							<span><?php esc_html_e( 'Images', 'starter-theme' ); ?></span>
							<small>JPG, PNG, WebP</small>
						</div>
						<div class="format-item">
							<span class="format-item__icon">📦</span>
							<span><?php esc_html_e( 'Archive', 'starter-theme' ); ?></span>
							<small>ZIP (max <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>)</small>
						</div>
						<div class="format-item">
							<span class="format-item__icon">📄</span>
							<span><?php esc_html_e( 'PDF', 'starter-theme' ); ?></span>
							<small><?php esc_html_e( 'Requires Imagick', 'starter-theme' ); ?></small>
						</div>
						<div class="format-item">
							<span class="format-item__icon">📝</span>
							<span><?php esc_html_e( 'Text', 'starter-theme' ); ?></span>
							<small><?php esc_html_e( 'For novels', 'starter-theme' ); ?></small>
						</div>
						<div class="format-item">
							<span class="format-item__icon">🎬</span>
							<span><?php esc_html_e( 'Video', 'starter-theme' ); ?></span>
							<small>URL embed</small>
						</div>
					</div>
				</div>

				<?php if ( is_user_logged_in() ) : ?>
				<div class="upload-sidebar-card">
					<h3><?php esc_html_e( 'My Submissions', 'starter-theme' ); ?></h3>
					<?php
					$my_posts = get_posts( array(
						'post_type'      => 'wp-manga',
						'posts_per_page' => 5,
						'author'         => get_current_user_id(),
						'post_status'    => array( 'publish', 'pending', 'draft' ),
						'orderby'        => 'date',
						'order'          => 'DESC',
					) );

					if ( $my_posts ) :
						foreach ( $my_posts as $p ) :
							$status_cls = array(
								'publish' => 'badge--success',
								'pending' => 'badge--warning',
								'draft'   => 'badge--muted',
							)[ $p->post_status ] ?? 'badge--muted';
							?>
							<div class="my-submission-item">
								<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="my-submission-item__title">
									<?php echo esc_html( $p->post_title ); ?>
								</a>
								<span class="badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( ucfirst( $p->post_status ) ); ?></span>
							</div>
						<?php endforeach;
					else :
						echo '<p style="font-size:13px;color:var(--color-text-muted,#777)">' . esc_html__( 'No submissions yet.', 'starter-theme' ) . '</p>';
					endif;
					?>
				</div>
				<?php endif; ?>

			</aside><!-- .upload-page__sidebar -->

		</div><!-- .upload-page__layout -->
	</div><!-- .container -->
</main>

<style>
.upload-page .page-header { display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:32px;flex-wrap:wrap; }
.page-header__title { display:flex;align-items:center;gap:10px;font-size:1.6rem;font-weight:800;margin-bottom:6px; }
.page-header__subtitle { font-size:14px;color:var(--color-text-secondary,#aaa); }
.user-stat { text-align:center; }
.user-stat__val { display:block;font-size:26px;font-weight:800; }
.user-stat__lbl { display:block;font-size:12px;color:var(--color-text-muted,#777); }
.upload-page__layout { display:grid;grid-template-columns:1fr 300px;gap:28px;align-items:start; }
@media(max-width:860px){ .upload-page__layout { grid-template-columns:1fr; } }
.upload-sidebar-card { background:var(--color-surface-1,rgba(255,255,255,.04));border:1px solid var(--color-border,rgba(255,255,255,.1));border-radius:12px;padding:20px;margin-bottom:16px; }
.upload-sidebar-card h3 { font-size:14px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--color-border,rgba(255,255,255,.1)); }
.upload-sidebar-list { display:flex;flex-direction:column;gap:8px; }
.upload-sidebar-list li { display:flex;align-items:flex-start;gap:8px;font-size:13px;line-height:1.5; }
.upload-sidebar-list__icon { flex-shrink:0; }
.format-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:8px; }
.format-item { display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 6px;background:var(--color-surface-2,rgba(255,255,255,.05));border-radius:8px;font-size:12px;font-weight:600;text-align:center; }
.format-item small { font-size:10px;color:var(--color-text-muted,#777);font-weight:400; }
.my-submission-item { display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid var(--color-border,rgba(255,255,255,.08));font-size:13px; }
.my-submission-item:last-child { border-bottom:none; }
.my-submission-item__title { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.my-submission-item__title:hover { color:var(--color-primary,#6c5ce7); }
.auth-required-box { text-align:center;padding:48px 32px;border-radius:12px; }
.auth-required-box__icon { font-size:48px;margin-bottom:12px; }
.auth-required-box h2 { font-size:1.4rem;font-weight:800;margin-bottom:8px; }
.auth-required-box p { font-size:14px;color:var(--color-text-secondary,#aaa);margin-bottom:20px; }
.auth-required-box__actions { display:flex;justify-content:center;gap:12px; }
</style>

<?php get_footer(); ?>
