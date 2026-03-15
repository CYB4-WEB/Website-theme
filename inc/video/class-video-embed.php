<?php
/**
 * Video embed parser and security layer.
 *
 * Parses video URLs, generates secure iframe embed code with a domain
 * whitelist, provides lazy-loading, a shortcode, oEmbed provider
 * registration, and CSP header integration.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Video_Embed
 */
class Starter_Video_Embed {

	/**
	 * Allowed embed domains.
	 *
	 * @var string[]
	 */
	const ALLOWED_DOMAINS = array(
		'pixeldrain.com',
		'drive.google.com',
	);

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'starter_video';

	/**
	 * Nonce action for embed AJAX requests.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_video_embed';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode_handler' ) );
		add_action( 'init', array( $this, 'register_oembed_providers' ) );
		add_action( 'send_headers', array( $this, 'add_csp_headers' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_starter_video_embed', array( $this, 'ajax_get_embed' ) );
		add_action( 'wp_ajax_nopriv_starter_video_embed', array( $this, 'ajax_get_embed' ) );
	}

	/* ------------------------------------------------------------------
	 * Domain whitelist helpers.
	 * ----------------------------------------------------------------*/

	/**
	 * Get the whitelist of allowed embed domains.
	 *
	 * @return string[]
	 */
	public function get_allowed_domains() {
		/**
		 * Filter the list of allowed video embed domains.
		 *
		 * @param string[] $domains Array of allowed domain names.
		 */
		return (array) apply_filters( 'starter_video_allowed_domains', self::ALLOWED_DOMAINS );
	}

	/**
	 * Check whether a URL's host is in the whitelist.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_allowed_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		$host    = strtolower( $host );
		$allowed = $this->get_allowed_domains();

		foreach ( $allowed as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		// Direct video URLs (self-hosted, etc.) are allowed.
		$player = Starter_Video_Player::get_instance();
		if ( 'direct' === $player->detect_source_type( $url ) ) {
			return true;
		}

		return false;
	}

	/* ------------------------------------------------------------------
	 * URL parsing and embed generation.
	 * ----------------------------------------------------------------*/

	/**
	 * Parse a video URL and return embed data.
	 *
	 * @param string $url Raw video URL.
	 * @return array|WP_Error Embed data array or WP_Error on failure.
	 */
	public function parse_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return new WP_Error( 'empty_url', __( 'Video URL is empty.', 'starter-theme' ) );
		}

		$player = Starter_Video_Player::get_instance();
		$type   = $player->detect_source_type( $url );

		if ( 'unknown' === $type ) {
			return new WP_Error( 'unknown_source', __( 'Unsupported video source.', 'starter-theme' ) );
		}

		if ( in_array( $type, array( 'pixeldrain', 'googledrive' ), true ) && ! $this->is_allowed_url( $url ) ) {
			return new WP_Error( 'domain_blocked', __( 'This video domain is not allowed.', 'starter-theme' ) );
		}

		$data = array(
			'original_url' => $url,
			'type'         => $type,
			'id'           => $player->extract_id( $url, $type ),
			'embed_url'    => '',
		);

		switch ( $type ) {
			case 'pixeldrain':
				$data['embed_url'] = 'https://pixeldrain.com/e/' . sanitize_text_field( $data['id'] );
				break;

			case 'googledrive':
				$data['embed_url'] = 'https://drive.google.com/file/d/' . sanitize_text_field( $data['id'] ) . '/preview';
				break;

			case 'direct':
				$data['embed_url'] = $url;
				break;
		}

		return $data;
	}

	/**
	 * Generate secure embed HTML for a video URL.
	 *
	 * @param string $url    Video URL.
	 * @param array  $args   Optional arguments (width, height, lazy).
	 * @return string HTML embed code, or empty string on failure.
	 */
	public function generate_embed( $url, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'width'  => '100%',
				'height' => 'auto',
				'lazy'   => true,
			)
		);

		$parsed = $this->parse_url( $url );

		if ( is_wp_error( $parsed ) ) {
			return '<!-- starter_video: ' . esc_html( $parsed->get_error_message() ) . ' -->';
		}

		// For direct video URLs, delegate to the full player.
		if ( 'direct' === $parsed['type'] ) {
			$player = Starter_Video_Player::get_instance();
			return $player->render( $url, $args );
		}

		// For iframe embeds, generate sanitized iframe.
		return $this->build_iframe( $parsed, $args );
	}

	/**
	 * Build a sanitized iframe element.
	 *
	 * @param array $parsed Parsed URL data from parse_url().
	 * @param array $args   Display arguments.
	 * @return string Sanitized iframe HTML.
	 */
	private function build_iframe( $parsed, $args ) {
		$lazy      = $args['lazy'];
		$embed_url = esc_url( $parsed['embed_url'] );
		$unique_id = 'starter-embed-' . wp_unique_id();

		$iframe_attrs = array(
			'id'              => $unique_id,
			'class'           => 'starter-video-embed__iframe',
			'allowfullscreen' => 'true',
			'allow'           => 'autoplay; encrypted-media; fullscreen',
			'sandbox'         => 'allow-scripts allow-same-origin allow-popups',
			'title'           => esc_attr__( 'Video player', 'starter-theme' ),
			'loading'         => 'lazy',
			'width'           => '100%',
			'height'          => '100%',
		);

		if ( $lazy ) {
			// Use data-src for JS lazy-loading; noscript fallback loads directly.
			$iframe_attrs['data-src'] = $embed_url;
		} else {
			$iframe_attrs['src'] = $embed_url;
		}

		$attrs_str = '';
		foreach ( $iframe_attrs as $key => $val ) {
			$attrs_str .= ' ' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		$html  = '<div class="starter-video-embed starter-video-embed--' . esc_attr( $parsed['type'] ) . '">';
		$html .= '<div class="starter-video-embed__ratio starter-video-embed__ratio--16-9">';
		$html .= '<iframe' . $attrs_str . '></iframe>';

		if ( $lazy ) {
			$html .= '<noscript>';
			$html .= '<iframe src="' . $embed_url . '" class="starter-video-embed__iframe" allowfullscreen allow="autoplay; encrypted-media; fullscreen" sandbox="allow-scripts allow-same-origin allow-popups" title="' . esc_attr__( 'Video player', 'starter-theme' ) . '" width="100%" height="100%"></iframe>';
			$html .= '</noscript>';
		}

		$html .= '</div>'; // .ratio
		$html .= '</div>'; // .embed

		return $html;
	}

	/* ------------------------------------------------------------------
	 * Shortcode.
	 * ----------------------------------------------------------------*/

	/**
	 * Handle [starter_video] shortcode.
	 *
	 * Usage: [starter_video url="https://pixeldrain.com/u/abc123" width="100%" height="auto"]
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Shortcode content (unused).
	 * @return string
	 */
	public function shortcode_handler( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'url'    => '',
				'width'  => '100%',
				'height' => 'auto',
				'lazy'   => 'true',
			),
			$atts,
			self::SHORTCODE
		);

		if ( empty( $atts['url'] ) ) {
			return '<!-- starter_video: URL required -->';
		}

		return $this->generate_embed(
			$atts['url'],
			array(
				'width'  => sanitize_text_field( $atts['width'] ),
				'height' => sanitize_text_field( $atts['height'] ),
				'lazy'   => filter_var( $atts['lazy'], FILTER_VALIDATE_BOOLEAN ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * oEmbed provider registration.
	 * ----------------------------------------------------------------*/

	/**
	 * Register oEmbed providers for supported platforms.
	 *
	 * @return void
	 */
	public function register_oembed_providers() {
		// Pixeldrain — provider URL is handled internally via the shortcode.
		wp_embed_register_handler(
			'starter-pixeldrain',
			'#https?://(?:www\.)?pixeldrain\.com/u/([a-zA-Z0-9]+)#',
			array( $this, 'oembed_pixeldrain_handler' )
		);

		wp_embed_register_handler(
			'starter-googledrive-video',
			'#https?://drive\.google\.com/file/d/([^/]+)(?:/[^/]*)?#',
			array( $this, 'oembed_googledrive_handler' )
		);
	}

	/**
	 * oEmbed handler for Pixeldrain URLs.
	 *
	 * @param array  $matches Regex matches.
	 * @param array  $attr    Embed attributes.
	 * @param string $url     Original URL.
	 * @param array  $rawattr Raw attributes.
	 * @return string
	 */
	public function oembed_pixeldrain_handler( $matches, $attr, $url, $rawattr ) {
		return $this->generate_embed( $url );
	}

	/**
	 * oEmbed handler for Google Drive video URLs.
	 *
	 * @param array  $matches Regex matches.
	 * @param array  $attr    Embed attributes.
	 * @param string $url     Original URL.
	 * @param array  $rawattr Raw attributes.
	 * @return string
	 */
	public function oembed_googledrive_handler( $matches, $attr, $url, $rawattr ) {
		return $this->generate_embed( $url );
	}

	/* ------------------------------------------------------------------
	 * CSP header integration.
	 * ----------------------------------------------------------------*/

	/**
	 * Add Content-Security-Policy frame-src directives for allowed domains.
	 *
	 * @return void
	 */
	public function add_csp_headers() {
		if ( headers_sent() ) {
			return;
		}

		$domains = $this->get_allowed_domains();

		if ( empty( $domains ) ) {
			return;
		}

		$sources = array( "'self'" );

		foreach ( $domains as $domain ) {
			$sources[] = 'https://' . sanitize_text_field( $domain );
		}

		/**
		 * Filter the CSP frame-src sources list.
		 *
		 * @param string[] $sources CSP source directives.
		 */
		$sources = (array) apply_filters( 'starter_video_csp_sources', $sources );

		$directive = 'frame-src ' . implode( ' ', $sources );

		header( 'Content-Security-Policy: ' . $directive, false );
	}

	/* ------------------------------------------------------------------
	 * LazyLoad assets.
	 * ----------------------------------------------------------------*/

	/**
	 * Register the lightweight lazy-load script.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script(
			'starter-video-embed',
			get_template_directory_uri() . '/assets/js/video-embed.js',
			array(),
			'1.0.0',
			true
		);

		// Enqueue only if the shortcode or embed is likely present.
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			wp_enqueue_script( 'starter-video-embed' );
		}
	}

	/* ------------------------------------------------------------------
	 * AJAX endpoint.
	 * ----------------------------------------------------------------*/

	/**
	 * AJAX handler to return embed HTML for a given URL.
	 *
	 * @return void
	 */
	public function ajax_get_embed() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'starter-theme' ) ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'starter-theme' ) ) );
		}

		$parsed = $this->parse_url( $url );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		$html = $this->generate_embed( $url, array( 'lazy' => false ) );

		wp_send_json_success(
			array(
				'html' => $html,
				'type' => $parsed['type'],
			)
		);
	}
}
