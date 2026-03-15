<?php
/**
 * Video player with VideoJS integration and embed support.
 *
 * Handles Pixeldrain and Google Drive iframe embeds as well as direct
 * video URLs via VideoJS.  Provides theater mode, keyboard shortcuts,
 * quality selection, and responsive 16:9 layout.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Video_Player
 */
class Starter_Video_Player {

	/**
	 * VideoJS CDN version.
	 *
	 * @var string
	 */
	const VIDEOJS_VERSION = '8.10.0';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Auto-incrementing player counter for unique IDs.
	 *
	 * @var int
	 */
	private $player_index = 0;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_inline_script' ) );
	}

	/**
	 * Register (but don't enqueue) VideoJS assets.
	 *
	 * Assets are enqueued only when a player is actually rendered.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'video-js',
			'https://vjs.zencdn.net/' . self::VIDEOJS_VERSION . '/video-js.min.css',
			array(),
			self::VIDEOJS_VERSION
		);

		wp_register_script(
			'video-js',
			'https://vjs.zencdn.net/' . self::VIDEOJS_VERSION . '/video.min.js',
			array(),
			self::VIDEOJS_VERSION,
			true
		);

		wp_register_style(
			'starter-video-player',
			get_template_directory_uri() . '/assets/css/video-player.css',
			array( 'video-js' ),
			'1.0.0'
		);

		wp_register_script(
			'starter-video-player',
			get_template_directory_uri() . '/assets/js/video-player.js',
			array( 'video-js' ),
			'1.0.0',
			true
		);
	}

	/**
	 * Detect video source type from a URL.
	 *
	 * @param string $url Video URL.
	 * @return string One of: pixeldrain, googledrive, direct, unknown.
	 */
	public function detect_source_type( $url ) {
		$url = esc_url_raw( $url );

		if ( preg_match( '#^https?://(?:www\.)?pixeldrain\.com/[ue]/([a-zA-Z0-9]+)#', $url ) ) {
			return 'pixeldrain';
		}

		if ( preg_match( '#^https?://drive\.google\.com/file/d/([^/]+)#', $url ) ) {
			return 'googledrive';
		}

		// Check for direct video file extensions.
		$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$video_ext = array( 'mp4', 'webm', 'ogg', 'ogv', 'm3u8', 'mpd' );

		if ( in_array( $extension, $video_ext, true ) ) {
			return 'direct';
		}

		return 'unknown';
	}

	/**
	 * Extract the resource ID from a supported URL.
	 *
	 * @param string $url   Video URL.
	 * @param string $type  Source type.
	 * @return string Resource ID or empty string.
	 */
	public function extract_id( $url, $type = '' ) {
		if ( ! $type ) {
			$type = $this->detect_source_type( $url );
		}

		switch ( $type ) {
			case 'pixeldrain':
				if ( preg_match( '#pixeldrain\.com/[ue]/([a-zA-Z0-9]+)#', $url, $m ) ) {
					return $m[1];
				}
				break;

			case 'googledrive':
				if ( preg_match( '#drive\.google\.com/file/d/([^/]+)#', $url, $m ) ) {
					return $m[1];
				}
				break;
		}

		return '';
	}

	/**
	 * Get the MIME type for a direct video URL.
	 *
	 * @param string $url Direct video URL.
	 * @return string MIME type string.
	 */
	public function get_mime_type( $url ) {
		$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		$map = array(
			'mp4'  => 'video/mp4',
			'webm' => 'video/webm',
			'ogg'  => 'video/ogg',
			'ogv'  => 'video/ogg',
			'm3u8' => 'application/x-mpegURL',
			'mpd'  => 'application/dash+xml',
		);

		return isset( $map[ $extension ] ) ? $map[ $extension ] : 'video/mp4';
	}

	/**
	 * Render a video player for the given URL.
	 *
	 * @param string $url    Video URL.
	 * @param array  $args   Optional arguments (width, height, autoplay, poster).
	 * @return string HTML output.
	 */
	public function render( $url, $args = array() ) {
		$url  = esc_url( $url );
		$type = $this->detect_source_type( $url );
		$args = wp_parse_args(
			$args,
			array(
				'width'    => '100%',
				'height'   => 'auto',
				'autoplay' => false,
				'poster'   => '',
			)
		);

		$this->player_index++;
		$player_id = 'starter-video-player-' . $this->player_index;

		// Enqueue assets when a player is rendered.
		if ( 'direct' === $type ) {
			wp_enqueue_style( 'starter-video-player' );
			wp_enqueue_script( 'starter-video-player' );
		} else {
			wp_enqueue_style( 'starter-video-player' );
		}

		ob_start();

		echo '<div class="starter-video-player-wrap" id="' . esc_attr( $player_id . '-wrap' ) . '" data-type="' . esc_attr( $type ) . '">';
		echo '<div class="starter-video-player__container starter-video-player__ratio-16-9">';

		switch ( $type ) {
			case 'pixeldrain':
				$this->render_pixeldrain( $url, $player_id, $args );
				break;

			case 'googledrive':
				$this->render_googledrive( $url, $player_id, $args );
				break;

			case 'direct':
				$this->render_videojs( $url, $player_id, $args );
				break;

			default:
				$this->render_videojs( $url, $player_id, $args );
				break;
		}

		echo '</div>'; // .container

		// Theater mode toggle.
		$this->render_theater_toggle( $player_id );

		echo '</div>'; // .wrap

		return ob_get_clean();
	}

	/**
	 * Render Pixeldrain embed iframe.
	 *
	 * @param string $url       Original URL.
	 * @param string $player_id Unique player element ID.
	 * @param array  $args      Player arguments.
	 * @return void
	 */
	private function render_pixeldrain( $url, $player_id, $args ) {
		$id        = $this->extract_id( $url, 'pixeldrain' );
		$embed_url = 'https://pixeldrain.com/e/' . sanitize_text_field( $id );

		printf(
			'<iframe id="%s" src="%s" class="starter-video-player__iframe" allowfullscreen allow="autoplay; encrypted-media" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups" title="%s"></iframe>',
			esc_attr( $player_id ),
			esc_url( $embed_url ),
			esc_attr__( 'Video player', 'starter-theme' )
		);
	}

	/**
	 * Render Google Drive embed iframe.
	 *
	 * @param string $url       Original URL.
	 * @param string $player_id Unique player element ID.
	 * @param array  $args      Player arguments.
	 * @return void
	 */
	private function render_googledrive( $url, $player_id, $args ) {
		$id        = $this->extract_id( $url, 'googledrive' );
		$embed_url = 'https://drive.google.com/file/d/' . sanitize_text_field( $id ) . '/preview';

		printf(
			'<iframe id="%s" src="%s" class="starter-video-player__iframe" allowfullscreen allow="autoplay; encrypted-media" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups" title="%s"></iframe>',
			esc_attr( $player_id ),
			esc_url( $embed_url ),
			esc_attr__( 'Video player', 'starter-theme' )
		);
	}

	/**
	 * Render VideoJS player for direct video URLs.
	 *
	 * @param string $url       Direct video URL.
	 * @param string $player_id Unique player element ID.
	 * @param array  $args      Player arguments.
	 * @return void
	 */
	private function render_videojs( $url, $player_id, $args ) {
		$mime     = $this->get_mime_type( $url );
		$autoplay = $args['autoplay'] ? 'autoplay' : '';
		$poster   = $args['poster'] ? 'poster="' . esc_url( $args['poster'] ) . '"' : '';

		printf(
			'<video id="%s" class="video-js vjs-big-play-centered starter-video-player__video" controls preload="auto" %s %s data-setup=\'%s\'>',
			esc_attr( $player_id ),
			$autoplay, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$poster, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr(
				wp_json_encode(
					array(
						'fluid'          => true,
						'playbackRates'  => array( 0.5, 0.75, 1, 1.25, 1.5, 2 ),
						'controlBar'     => array(
							'volumePanel'        => array( 'inline' => false ),
							'playbackRateMenuButton' => true,
						),
					)
				)
			)
		);

		printf(
			'<source src="%s" type="%s">',
			esc_url( $url ),
			esc_attr( $mime )
		);

		printf(
			'<p class="vjs-no-js">%s</p>',
			esc_html__( 'To view this video please enable JavaScript or upgrade to a browser that supports HTML5 video.', 'starter-theme' )
		);

		echo '</video>';
	}

	/**
	 * Render theater mode toggle button.
	 *
	 * @param string $player_id Player element ID.
	 * @return void
	 */
	private function render_theater_toggle( $player_id ) {
		printf(
			'<button type="button" class="starter-video-player__theater-toggle" data-target="%s-wrap" aria-label="%s" title="%s">
				<span class="dashicons dashicons-editor-expand"></span>
			</button>',
			esc_attr( $player_id ),
			esc_attr__( 'Toggle theater mode', 'starter-theme' ),
			esc_attr__( 'Theater Mode', 'starter-theme' )
		);
	}

	/**
	 * Render inline keyboard-shortcut and theater-mode script in footer.
	 *
	 * Only outputs if at least one player was rendered on the page.
	 *
	 * @return void
	 */
	public function render_inline_script() {
		if ( 0 === $this->player_index ) {
			return;
		}
		?>
		<script>
		(function(){
			"use strict";

			/* Theater mode toggle. */
			document.addEventListener("click",function(e){
				var btn = e.target.closest(".starter-video-player__theater-toggle");
				if(!btn) return;
				var wrap = document.getElementById(btn.dataset.target);
				if(!wrap) return;
				wrap.classList.toggle("starter-video-player--theater");
				btn.setAttribute("aria-pressed", wrap.classList.contains("starter-video-player--theater"));
			});

			/* Keyboard shortcuts – only when a player wrapper is focused/hovered. */
			document.addEventListener("keydown",function(e){
				var wrap = document.querySelector(".starter-video-player-wrap:hover, .starter-video-player-wrap:focus-within");
				if(!wrap) return;

				var video = wrap.querySelector("video");
				if(!video) return;

				switch(e.key){
					case " ":
					case "k":
						e.preventDefault();
						video.paused ? video.play() : video.pause();
						break;
					case "f":
						e.preventDefault();
						if(document.fullscreenElement){
							document.exitFullscreen();
						} else {
							wrap.requestFullscreen();
						}
						break;
					case "ArrowRight":
						e.preventDefault();
						video.currentTime = Math.min(video.duration, video.currentTime + 5);
						break;
					case "ArrowLeft":
						e.preventDefault();
						video.currentTime = Math.max(0, video.currentTime - 5);
						break;
					case "ArrowUp":
						e.preventDefault();
						video.volume = Math.min(1, video.volume + 0.1);
						break;
					case "ArrowDown":
						e.preventDefault();
						video.volume = Math.max(0, video.volume - 0.1);
						break;
					case "m":
						e.preventDefault();
						video.muted = !video.muted;
						break;
				}
			});
		})();
		</script>
		<?php
	}
}
