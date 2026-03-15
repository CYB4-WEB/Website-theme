<?php
/**
 * Custom RSS and Atom feeds for manga chapter releases.
 *
 * Provides bot-friendly feeds (Telegram RSS, Discord, etc.) with
 * customizable message templates, media enclosures, and proper
 * caching headers for conditional GET support.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Feed
 *
 * Registers custom RSS 2.0 and Atom 1.0 feeds for manga chapter updates
 * with full template customization and rich media support for RSS bots.
 *
 * @since 1.0.0
 */
class Starter_Manga_Feed {

	/**
	 * Option key prefix for feed settings.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'starter_manga_feed_';

	/**
	 * Default number of feed items.
	 *
	 * @var int
	 */
	const DEFAULT_ITEMS = 50;

	/**
	 * Transient key for feed last-modified timestamp.
	 *
	 * @var string
	 */
	const TRANSIENT_LAST_MODIFIED = 'starter_manga_feed_last_modified';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $defaults = array();

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Initialize the feed system.
	 *
	 * @return void
	 */
	public function init() {
		$this->defaults = array(
			'feed_title'            => '{site_name} - Chapter Updates',
			'item_title'            => "\xD8\xA7\xD9\x84\xD9\x81\xD8\xB5\xD9\x84 {chapter_number} {chapter_name} \xD9\x85\xD9\x86 \xD9\x85\xD8\xA7\xD9\x86\xD8\xAC\xD8\xA7 {manga_name}",
			'item_description'      => "{item_title}\n{link}\n{thumbnail_html}",
			'item_link'             => '{link}',
			'bot_message'           => "{item_title}\n\xD8\xA7\xD9\x84\xD8\xB1\xD8\xA7\xD8\xA8\xD8\xB7: {link}\n\xD8\xA7\xD9\x84\xD9\x85\xD8\xA7\xD9\x86\xD8\xAC\xD8\xA7: {manga_name}\n\xD8\xA7\xD9\x84\xD9\x86\xD9\x88\xD8\xB9: {type}\n\xD8\xA7\xD9\x84\xD8\xAA\xD8\xB5\xD9\x86\xD9\x8A\xD9\x81\xD8\xA7\xD8\xAA: {genres}",
			'items_count'           => self::DEFAULT_ITEMS,
			'include_thumbnail'     => 'yes',
			'feed_language'         => '',
		);

		add_action( 'init', array( $this, 'register_feeds' ), 20 );
		add_action( 'wp_head', array( $this, 'output_autodiscovery_links' ), 2 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Invalidate cache when chapters change.
		add_action( 'starter_chapter_added', array( $this, 'invalidate_feed_cache' ) );
		add_action( 'starter_chapter_updated', array( $this, 'invalidate_feed_cache' ) );
		add_action( 'save_post_wp-manga', array( $this, 'invalidate_feed_cache' ) );
	}

	/*--------------------------------------------------------------------------
	 * Feed Registration
	 *------------------------------------------------------------------------*/

	/**
	 * Register custom feed endpoints.
	 *
	 * @return void
	 */
	public function register_feeds() {
		add_feed( 'manga-chapters', array( $this, 'render_rss_feed' ) );
		add_feed( 'manga-chapters-atom', array( $this, 'render_atom_feed' ) );

		// Clean URL rewrite rules.
		add_rewrite_rule(
			'^feed/manga-chapters/?$',
			'index.php?feed=manga-chapters',
			'top'
		);
		add_rewrite_rule(
			'^feed/manga-chapters-atom/?$',
			'index.php?feed=manga-chapters-atom',
			'top'
		);
	}

	/**
	 * Output feed autodiscovery links in the HTML head.
	 *
	 * @return void
	 */
	public function output_autodiscovery_links() {
		$settings = $this->get_settings();
		$title    = $this->process_template( $settings['feed_title'], array() );

		printf(
			'<link rel="alternate" type="application/rss+xml" title="%s (RSS)" href="%s" />' . "\n",
			esc_attr( $title ),
			esc_url( home_url( '/feed/manga-chapters/' ) )
		);

		printf(
			'<link rel="alternate" type="application/atom+xml" title="%s (Atom)" href="%s" />' . "\n",
			esc_attr( $title ),
			esc_url( home_url( '/feed/manga-chapters-atom/' ) )
		);
	}

	/*--------------------------------------------------------------------------
	 * Settings Management
	 *------------------------------------------------------------------------*/

	/**
	 * Get all feed settings with defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$this->settings = array();
		foreach ( $this->defaults as $key => $default ) {
			$this->settings[ $key ] = get_option( self::OPTION_PREFIX . $key, $default );
		}

		// Ensure language fallback.
		if ( empty( $this->settings['feed_language'] ) ) {
			$this->settings['feed_language'] = get_bloginfo( 'language' );
		}

		return $this->settings;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/*--------------------------------------------------------------------------
	 * Data Retrieval
	 *------------------------------------------------------------------------*/

	/**
	 * Query latest chapters from the database.
	 *
	 * Joins starter_manga_chapters table with wp_posts to get manga metadata.
	 *
	 * @param int $limit Number of chapters to retrieve.
	 * @return array Array of chapter data objects.
	 */
	private function get_latest_chapters( $limit = 50 ) {
		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';
		$limit          = absint( $limit );

		if ( $limit < 1 ) {
			$limit = self::DEFAULT_ITEMS;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, p.post_title AS manga_title, p.post_name AS manga_slug,
						p.post_date AS manga_date, p.post_modified AS manga_modified,
						p.ID AS manga_post_id
				FROM {$chapters_table} AS c
				INNER JOIN {$wpdb->posts} AS p ON c.manga_id = p.ID
				WHERE c.chapter_status = 'publish'
				AND p.post_status = 'publish'
				ORDER BY c.date_created DESC
				LIMIT %d",
				$limit
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Build placeholder data for a chapter row.
	 *
	 * @param object $chapter Chapter database row with joined manga data.
	 * @return array Associative array of placeholder => value.
	 */
	private function build_placeholder_data( $chapter ) {
		$manga_id   = absint( $chapter->manga_post_id );
		$manga_slug = $chapter->manga_slug;

		// Chapter URL.
		$chapter_slug = 'chapter-' . $chapter->chapter_number;
		$chapter_link = home_url( '/manga/' . $manga_slug . '/' . $chapter_slug . '/' );

		// Manga URL.
		$manga_link = get_permalink( $manga_id );
		if ( ! $manga_link ) {
			$manga_link = home_url( '/manga/' . $manga_slug . '/' );
		}

		// Thumbnail.
		$thumbnail_url  = '';
		$thumbnail_html = '';
		$thumbnail_id   = get_post_thumbnail_id( $manga_id );

		if ( $thumbnail_id ) {
			$thumbnail_url = get_the_post_thumbnail_url( $manga_id, 'medium' );
			if ( $thumbnail_url ) {
				$thumbnail_html = '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $chapter->manga_title ) . '" />';
			}
		}

		// If chapter has its own thumbnail, prefer it.
		if ( ! empty( $chapter->chapter_thumbnail ) ) {
			$thumbnail_url  = $chapter->chapter_thumbnail;
			$thumbnail_html = '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $chapter->manga_title ) . '" />';
		}

		// Meta fields.
		$meta_prefix = '_starter_manga_';
		$alt_names   = get_post_meta( $manga_id, $meta_prefix . 'alt_names', true );
		$author      = get_post_meta( $manga_id, $meta_prefix . 'author_name', true );
		$artist      = get_post_meta( $manga_id, $meta_prefix . 'artist_name', true );
		$status      = get_post_meta( $manga_id, $meta_prefix . 'status', true );

		// Genres.
		$genre_names = array();
		$genres      = wp_get_object_terms( $manga_id, 'wp-manga-genre', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) ) {
			$genre_names = $genres;
		}

		// Type.
		$type_name = '';
		$types     = wp_get_object_terms( $manga_id, 'wp-manga-type', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_name = $types[0];
		}

		// Date formatting.
		$date_created = $chapter->date_created;
		$date_obj     = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_created );
		$date_rss     = $date_obj ? $date_obj->format( DATE_RSS ) : gmdate( DATE_RSS );
		$date_iso     = $date_obj ? $date_obj->format( 'c' ) : gmdate( 'c' );
		$date_display = $date_obj ? $date_obj->format( get_option( 'date_format' ) ) : '';

		return array(
			'chapter_number' => $chapter->chapter_number,
			'chapter_name'   => $chapter->chapter_name ? $chapter->chapter_name : '',
			'manga_name'     => $chapter->manga_title,
			'manga_name_alt' => $alt_names ? $alt_names : '',
			'link'           => $chapter_link,
			'manga_link'     => $manga_link,
			'thumbnail'      => $thumbnail_url,
			'thumbnail_html' => $thumbnail_html,
			'author'         => $author ? $author : '',
			'artist'         => $artist ? $artist : '',
			'genres'         => implode( ', ', $genre_names ),
			'type'           => $type_name,
			'status'         => $status ? ucfirst( $status ) : '',
			'date'           => $date_display,
			'date_iso'       => $date_iso,
			'date_rss'       => $date_rss,
			'volume'         => $chapter->volume ? $chapter->volume : '',
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url( '/' ),
			'genre_list'     => $genre_names,
			'manga_id'       => $manga_id,
		);
	}

	/**
	 * Replace placeholders in a template string.
	 *
	 * @param string $template Template with {placeholder} tokens.
	 * @param array  $data     Placeholder data from build_placeholder_data().
	 * @return string Processed string.
	 */
	private function process_template( $template, $data ) {
		// Site-level placeholders always available.
		if ( empty( $data ) ) {
			$data = array(
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => home_url( '/' ),
			);
		}

		$search  = array();
		$replace = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}

		// Also support {item_title} as a self-referencing placeholder.
		if ( false !== strpos( $template, '{item_title}' ) && ! isset( $data['item_title'] ) ) {
			$settings     = $this->get_settings();
			$item_title   = str_replace( $search, $replace, $settings['item_title'] );
			$search[]     = '{item_title}';
			$replace[]    = $item_title;
		}

		return str_replace( $search, $replace, $template );
	}

	/*--------------------------------------------------------------------------
	 * Caching and Conditional GET
	 *------------------------------------------------------------------------*/

	/**
	 * Get the last-modified timestamp for feed content.
	 *
	 * @return int Unix timestamp.
	 */
	private function get_last_modified_time() {
		$cached = get_transient( self::TRANSIENT_LAST_MODIFIED );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$chapters_table = $wpdb->prefix . 'manga_chapters';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$latest = $wpdb->get_var(
			"SELECT MAX(date_created) FROM {$chapters_table} WHERE chapter_status = 'publish'"
		);

		$timestamp = $latest ? strtotime( $latest ) : time();
		set_transient( self::TRANSIENT_LAST_MODIFIED, $timestamp, HOUR_IN_SECONDS );

		return $timestamp;
	}

	/**
	 * Generate an ETag for the feed.
	 *
	 * @param int $last_modified Last-modified timestamp.
	 * @return string ETag value.
	 */
	private function generate_etag( $last_modified ) {
		$settings = $this->get_settings();
		return '"' . md5( $last_modified . wp_json_encode( $settings ) ) . '"';
	}

	/**
	 * Send caching headers and handle conditional GET requests.
	 *
	 * Sends 304 Not Modified when content has not changed.
	 *
	 * @param int $last_modified Unix timestamp.
	 * @return bool True if 304 was sent and execution should stop.
	 */
	private function handle_conditional_get( $last_modified ) {
		$etag          = $this->generate_etag( $last_modified );
		$last_modified_gmt = gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT';

		header( 'Last-Modified: ' . $last_modified_gmt );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=300' );

		// Check If-None-Match.
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) )
			: '';

		if ( $if_none_match && trim( $if_none_match ) === $etag ) {
			status_header( 304 );
			return true;
		}

		// Check If-Modified-Since.
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) )
			: '';

		if ( $if_modified_since && strtotime( $if_modified_since ) >= $last_modified ) {
			status_header( 304 );
			return true;
		}

		return false;
	}

	/**
	 * Invalidate the feed cache.
	 *
	 * @return void
	 */
	public function invalidate_feed_cache() {
		delete_transient( self::TRANSIENT_LAST_MODIFIED );
		$this->settings = null;
	}

	/*--------------------------------------------------------------------------
	 * RSS 2.0 Feed Output
	 *------------------------------------------------------------------------*/

	/**
	 * Render the RSS 2.0 feed.
	 *
	 * @return void
	 */
	public function render_rss_feed() {
		$last_modified = $this->get_last_modified_time();

		if ( $this->handle_conditional_get( $last_modified ) ) {
			exit;
		}

		$settings = $this->get_settings();
		$chapters = $this->get_latest_chapters( absint( $settings['items_count'] ) );

		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Content-Type-Options: nosniff' );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<rss version="2.0"
	xmlns:atom="http://www.w3.org/2005/Atom"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:media="http://search.yahoo.com/mrss/"
	xmlns:slash="http://purl.org/rss/1.0/modules/slash/"
>
<channel>
	<title><![CDATA[<?php echo esc_html( $this->process_template( $settings['feed_title'], array() ) ); ?>]]></title>
	<link><?php echo esc_url( home_url( '/' ) ); ?></link>
	<description><![CDATA[<?php echo esc_html( get_bloginfo( 'description' ) ); ?>]]></description>
	<language><?php echo esc_attr( $settings['feed_language'] ); ?></language>
	<lastBuildDate><?php echo esc_html( gmdate( DATE_RSS, $last_modified ) ); ?></lastBuildDate>
	<generator>starter-theme/1.0.0</generator>
	<docs>https://www.rssboard.org/rss-specification</docs>
	<atom:link href="<?php echo esc_url( home_url( '/feed/manga-chapters/' ) ); ?>" rel="self" type="application/rss+xml" />
	<image>
		<url><?php echo esc_url( get_site_icon_url( 32, '' ) ); ?></url>
		<title><![CDATA[<?php echo esc_html( get_bloginfo( 'name' ) ); ?>]]></title>
		<link><?php echo esc_url( home_url( '/' ) ); ?></link>
	</image>
<?php
		foreach ( $chapters as $chapter ) {
			$data = $this->build_placeholder_data( $chapter );
			$this->render_rss_item( $data, $settings );
		}
?>
</channel>
</rss>
<?php
		exit;
	}

	/**
	 * Render a single RSS item.
	 *
	 * @param array $data     Placeholder data for the chapter.
	 * @param array $settings Feed settings.
	 * @return void
	 */
	private function render_rss_item( $data, $settings ) {
		$title       = $this->process_template( $settings['item_title'], $data );
		$link        = $this->process_template( $settings['item_link'], $data );
		$description = $this->process_template( $settings['item_description'], $data );
		$bot_message = $this->process_template( $settings['bot_message'], $data );

		// Build full description with bot message appended as content:encoded.
		$content_encoded = $description;
		if ( 'yes' === $settings['include_thumbnail'] && ! empty( $data['thumbnail_html'] ) ) {
			if ( false === strpos( $content_encoded, '<img' ) ) {
				$content_encoded .= "\n" . $data['thumbnail_html'];
			}
		}
		?>
	<item>
		<title><![CDATA[<?php echo esc_html( $title ); ?>]]></title>
		<link><?php echo esc_url( $link ); ?></link>
		<description><![CDATA[<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CDATA block, sanitized below.
			echo wp_kses(
				nl2br( $description ),
				array(
					'br'  => array(),
					'img' => array( 'src' => array(), 'alt' => array() ),
					'a'   => array( 'href' => array() ),
					'p'   => array(),
				)
			);
		?>]]></description>
		<content:encoded><![CDATA[<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_kses(
				nl2br( $bot_message ),
				array(
					'br'  => array(),
					'img' => array( 'src' => array(), 'alt' => array() ),
					'a'   => array( 'href' => array() ),
					'p'   => array(),
				)
			);
		?>]]></content:encoded>
		<pubDate><?php echo esc_html( $data['date_rss'] ); ?></pubDate>
		<guid isPermaLink="true"><?php echo esc_url( $link ); ?></guid>
<?php if ( ! empty( $data['thumbnail'] ) && 'yes' === $settings['include_thumbnail'] ) : ?>
		<enclosure url="<?php echo esc_url( $data['thumbnail'] ); ?>" type="<?php echo esc_attr( $this->get_image_mime_type( $data['thumbnail'] ) ); ?>" length="0" />
		<media:content url="<?php echo esc_url( $data['thumbnail'] ); ?>" medium="image" />
		<media:thumbnail url="<?php echo esc_url( $data['thumbnail'] ); ?>" />
<?php endif; ?>
<?php if ( ! empty( $data['author'] ) ) : ?>
		<dc:creator><![CDATA[<?php echo esc_html( $data['author'] ); ?>]]></dc:creator>
<?php endif; ?>
<?php
		// Output genre categories.
		if ( ! empty( $data['genre_list'] ) && is_array( $data['genre_list'] ) ) {
			foreach ( $data['genre_list'] as $genre ) {
				echo "\t\t" . '<category><![CDATA[' . esc_html( $genre ) . ']]></category>' . "\n";
			}
		}
?>
	</item>
<?php
	}

	/*--------------------------------------------------------------------------
	 * Atom 1.0 Feed Output
	 *------------------------------------------------------------------------*/

	/**
	 * Render the Atom 1.0 feed.
	 *
	 * @return void
	 */
	public function render_atom_feed() {
		$last_modified = $this->get_last_modified_time();

		if ( $this->handle_conditional_get( $last_modified ) ) {
			exit;
		}

		$settings = $this->get_settings();
		$chapters = $this->get_latest_chapters( absint( $settings['items_count'] ) );

		header( 'Content-Type: application/atom+xml; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Content-Type-Options: nosniff' );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
	<title><![CDATA[<?php echo esc_html( $this->process_template( $settings['feed_title'], array() ) ); ?>]]></title>
	<subtitle><![CDATA[<?php echo esc_html( get_bloginfo( 'description' ) ); ?>]]></subtitle>
	<link href="<?php echo esc_url( home_url( '/feed/manga-chapters-atom/' ) ); ?>" rel="self" type="application/atom+xml" />
	<link href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="alternate" type="text/html" />
	<id><?php echo esc_url( home_url( '/feed/manga-chapters-atom/' ) ); ?></id>
	<updated><?php echo esc_html( gmdate( 'c', $last_modified ) ); ?></updated>
	<generator uri="https://developer.starter-theme.com/" version="1.0.0">starter-theme</generator>
	<icon><?php echo esc_url( get_site_icon_url( 32, '' ) ); ?></icon>
<?php
		foreach ( $chapters as $chapter ) {
			$data = $this->build_placeholder_data( $chapter );
			$this->render_atom_entry( $data, $settings );
		}
?>
</feed>
<?php
		exit;
	}

	/**
	 * Render a single Atom entry.
	 *
	 * @param array $data     Placeholder data for the chapter.
	 * @param array $settings Feed settings.
	 * @return void
	 */
	private function render_atom_entry( $data, $settings ) {
		$title       = $this->process_template( $settings['item_title'], $data );
		$link        = $this->process_template( $settings['item_link'], $data );
		$description = $this->process_template( $settings['item_description'], $data );
		$bot_message = $this->process_template( $settings['bot_message'], $data );

		$content = $description;
		if ( 'yes' === $settings['include_thumbnail'] && ! empty( $data['thumbnail_html'] ) ) {
			if ( false === strpos( $content, '<img' ) ) {
				$content .= "\n" . $data['thumbnail_html'];
			}
		}
		?>
	<entry>
		<title type="html"><![CDATA[<?php echo esc_html( $title ); ?>]]></title>
		<link href="<?php echo esc_url( $link ); ?>" rel="alternate" type="text/html" />
		<id><?php echo esc_url( $link ); ?></id>
		<published><?php echo esc_html( $data['date_iso'] ); ?></published>
		<updated><?php echo esc_html( $data['date_iso'] ); ?></updated>
		<summary type="html"><![CDATA[<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_kses(
				nl2br( $description ),
				array(
					'br'  => array(),
					'img' => array( 'src' => array(), 'alt' => array() ),
					'a'   => array( 'href' => array() ),
					'p'   => array(),
				)
			);
		?>]]></summary>
		<content type="html"><![CDATA[<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_kses(
				nl2br( $bot_message ),
				array(
					'br'  => array(),
					'img' => array( 'src' => array(), 'alt' => array() ),
					'a'   => array( 'href' => array() ),
					'p'   => array(),
				)
			);
		?>]]></content>
<?php if ( ! empty( $data['author'] ) ) : ?>
		<author>
			<name><?php echo esc_html( $data['author'] ); ?></name>
		</author>
<?php endif; ?>
<?php
		if ( ! empty( $data['genre_list'] ) && is_array( $data['genre_list'] ) ) {
			foreach ( $data['genre_list'] as $genre ) {
				echo "\t\t" . '<category term="' . esc_attr( $genre ) . '" />' . "\n";
			}
		}

		if ( ! empty( $data['thumbnail'] ) && 'yes' === $settings['include_thumbnail'] ) {
			echo "\t\t" . '<media:content url="' . esc_url( $data['thumbnail'] ) . '" medium="image" />' . "\n";
			echo "\t\t" . '<media:thumbnail url="' . esc_url( $data['thumbnail'] ) . '" />' . "\n";
			echo "\t\t" . '<link rel="enclosure" href="' . esc_url( $data['thumbnail'] ) . '" type="' . esc_attr( $this->get_image_mime_type( $data['thumbnail'] ) ) . '" />' . "\n";
		}
?>
	</entry>
<?php
	}

	/*--------------------------------------------------------------------------
	 * Admin Settings Page
	 *------------------------------------------------------------------------*/

	/**
	 * Register the admin menu under the Manga parent.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=wp-manga',
			__( 'Feed Settings', 'starter-theme' ),
			__( 'Feed Settings', 'starter-theme' ),
			'manage_options',
			'starter-manga-feed',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register settings with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Section.
		add_settings_section(
			'starter_manga_feed_main',
			__( 'Feed Template Settings', 'starter-theme' ),
			array( $this, 'render_section_description' ),
			'starter-manga-feed'
		);

		// Feed title template.
		$this->add_text_field(
			'feed_title',
			__( 'Feed Title Template', 'starter-theme' ),
			__( 'Title shown in RSS readers. Use {site_name} and {site_url} placeholders.', 'starter-theme' )
		);

		// Item title template.
		$this->add_text_field(
			'item_title',
			__( 'Item Title Template', 'starter-theme' ),
			__( 'Title for each feed item. Supports all placeholders listed below.', 'starter-theme' )
		);

		// Item description template.
		$this->add_textarea_field(
			'item_description',
			__( 'Item Description Template', 'starter-theme' ),
			__( 'Description/body for each feed item. HTML allowed. Supports all placeholders.', 'starter-theme' )
		);

		// Item link template.
		$this->add_text_field(
			'item_link',
			__( 'Item Link Template', 'starter-theme' ),
			__( 'URL for each feed item. Default: {link}', 'starter-theme' )
		);

		// Bot message template.
		$this->add_textarea_field(
			'bot_message',
			__( 'Bot Message Template', 'starter-theme' ),
			__( 'Custom message for RSS bots (Telegram, Discord, etc.). This goes into content:encoded. Supports all placeholders.', 'starter-theme' )
		);

		// Items count.
		register_setting( 'starter-manga-feed', self::OPTION_PREFIX . 'items_count', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => self::DEFAULT_ITEMS,
		) );

		add_settings_field(
			self::OPTION_PREFIX . 'items_count',
			__( 'Number of Items', 'starter-theme' ),
			array( $this, 'render_number_field' ),
			'starter-manga-feed',
			'starter_manga_feed_main',
			array(
				'key'         => 'items_count',
				'description' => __( 'Number of chapters to include in the feed (1-200).', 'starter-theme' ),
			)
		);

		// Include thumbnail.
		register_setting( 'starter-manga-feed', self::OPTION_PREFIX . 'include_thumbnail', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_yes_no' ),
			'default'           => 'yes',
		) );

		add_settings_field(
			self::OPTION_PREFIX . 'include_thumbnail',
			__( 'Include Thumbnail', 'starter-theme' ),
			array( $this, 'render_select_field' ),
			'starter-manga-feed',
			'starter_manga_feed_main',
			array(
				'key'         => 'include_thumbnail',
				'options'     => array(
					'yes' => __( 'Yes', 'starter-theme' ),
					'no'  => __( 'No', 'starter-theme' ),
				),
				'description' => __( 'Include manga cover image in feed items (enclosure and media:content).', 'starter-theme' ),
			)
		);

		// Feed language.
		$this->add_text_field(
			'feed_language',
			__( 'Feed Language', 'starter-theme' ),
			__( 'Language code for the feed (e.g., ar, en-US). Leave blank to use site language.', 'starter-theme' )
		);
	}

	/**
	 * Register a text input setting field.
	 *
	 * @param string $key         Option key suffix.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function add_text_field( $key, $label, $description ) {
		register_setting( 'starter-manga-feed', self::OPTION_PREFIX . $key, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => $this->defaults[ $key ],
		) );

		add_settings_field(
			self::OPTION_PREFIX . $key,
			$label,
			array( $this, 'render_text_field' ),
			'starter-manga-feed',
			'starter_manga_feed_main',
			array(
				'key'         => $key,
				'description' => $description,
			)
		);
	}

	/**
	 * Register a textarea setting field.
	 *
	 * @param string $key         Option key suffix.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function add_textarea_field( $key, $label, $description ) {
		register_setting( 'starter-manga-feed', self::OPTION_PREFIX . $key, array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => $this->defaults[ $key ],
		) );

		add_settings_field(
			self::OPTION_PREFIX . $key,
			$label,
			array( $this, 'render_textarea_field' ),
			'starter-manga-feed',
			'starter_manga_feed_main',
			array(
				'key'         => $key,
				'description' => $description,
			)
		);
	}

	/*--------------------------------------------------------------------------
	 * Admin Field Renderers
	 *------------------------------------------------------------------------*/

	/**
	 * Render the settings section description with placeholder documentation.
	 *
	 * @return void
	 */
	public function render_section_description() {
		?>
		<p><?php esc_html_e( 'Configure how manga chapter updates appear in RSS and Atom feeds. These feeds are designed to work with RSS bots (Telegram, Discord, Slack, etc.).', 'starter-theme' ); ?></p>

		<details style="margin-bottom:15px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Available Placeholders', 'starter-theme' ); ?></summary>
			<table class="widefat striped" style="max-width:700px;margin-top:10px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Placeholder', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Description', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>{chapter_number}</code></td><td><?php esc_html_e( 'Chapter number', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{chapter_name}</code></td><td><?php esc_html_e( 'Chapter name/title (empty string if not set)', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{manga_name}</code></td><td><?php esc_html_e( 'Manga title', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{manga_name_alt}</code></td><td><?php esc_html_e( 'Alternative manga name', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{link}</code></td><td><?php esc_html_e( 'Full URL to chapter', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{manga_link}</code></td><td><?php esc_html_e( 'Full URL to manga page', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{thumbnail}</code></td><td><?php esc_html_e( 'Manga cover image URL', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{thumbnail_html}</code></td><td><?php esc_html_e( 'Full <img> tag for thumbnail', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{author}</code></td><td><?php esc_html_e( 'Manga author name', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{artist}</code></td><td><?php esc_html_e( 'Manga artist name', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{genres}</code></td><td><?php esc_html_e( 'Comma-separated genre list', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{type}</code></td><td><?php esc_html_e( 'Content type (Manga/Novel/Video)', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{status}</code></td><td><?php esc_html_e( 'Manga status (Ongoing, Completed, etc.)', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{date}</code></td><td><?php esc_html_e( 'Publication date (formatted)', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{date_iso}</code></td><td><?php esc_html_e( 'ISO 8601 date', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{volume}</code></td><td><?php esc_html_e( 'Volume number', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{site_name}</code></td><td><?php esc_html_e( 'Site name', 'starter-theme' ); ?></td></tr>
					<tr><td><code>{site_url}</code></td><td><?php esc_html_e( 'Site URL', 'starter-theme' ); ?></td></tr>
				</tbody>
			</table>
		</details>

		<p>
			<strong><?php esc_html_e( 'Feed URLs:', 'starter-theme' ); ?></strong><br/>
			RSS 2.0: <code><?php echo esc_html( home_url( '/feed/manga-chapters/' ) ); ?></code><br/>
			Atom 1.0: <code><?php echo esc_html( home_url( '/feed/manga-chapters-atom/' ) ); ?></code>
		</p>
		<?php
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_text_field( $args ) {
		$key   = $args['key'];
		$name  = self::OPTION_PREFIX . $key;
		$value = get_option( $name, $this->defaults[ $key ] );
		?>
		<input type="text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>" class="large-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_textarea_field( $args ) {
		$key   = $args['key'];
		$name  = self::OPTION_PREFIX . $key;
		$value = get_option( $name, $this->defaults[ $key ] );
		?>
		<textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
			rows="5" class="large-text" dir="auto"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_number_field( $args ) {
		$key   = $args['key'];
		$name  = self::OPTION_PREFIX . $key;
		$value = get_option( $name, $this->defaults[ $key ] );
		?>
		<input type="number" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo absint( $value ); ?>" min="1" max="200" step="1" class="small-text" />
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_select_field( $args ) {
		$key     = $args['key'];
		$name    = self::OPTION_PREFIX . $key;
		$value   = get_option( $name, $this->defaults[ $key ] );
		$options = isset( $args['options'] ) ? $args['options'] : array();
		?>
		<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $options as $opt_value => $opt_label ) : ?>
				<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
					<?php echo esc_html( $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the admin settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'starter-manga-feed' ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'starter-manga-feed' );
				do_settings_sections( 'starter-manga-feed' );
				submit_button( __( 'Save Feed Settings', 'starter-theme' ) );
				?>
			</form>

			<hr/>
			<h2><?php esc_html_e( 'Feed Preview', 'starter-theme' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( home_url( '/feed/manga-chapters/' ) ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'Open RSS Feed', 'starter-theme' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/feed/manga-chapters-atom/' ) ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'Open Atom Feed', 'starter-theme' ); ?>
				</a>
			</p>

			<hr/>
			<h2><?php esc_html_e( 'Flush Rewrite Rules', 'starter-theme' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'If feed URLs return 404, go to Settings > Permalinks and click "Save Changes" to flush rewrite rules.', 'starter-theme' ); ?>
			</p>
		</div>
		<?php
	}

	/*--------------------------------------------------------------------------
	 * Utility Methods
	 *------------------------------------------------------------------------*/

	/**
	 * Sanitize a yes/no option value.
	 *
	 * @param string $value Input value.
	 * @return string 'yes' or 'no'.
	 */
	public function sanitize_yes_no( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Get the MIME type for an image URL based on file extension.
	 *
	 * @param string $url Image URL.
	 * @return string MIME type.
	 */
	private function get_image_mime_type( $url ) {
		$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		$mime_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'svg'  => 'image/svg+xml',
		);

		return isset( $mime_types[ $extension ] ) ? $mime_types[ $extension ] : 'image/jpeg';
	}

	/**
	 * Get the list of available placeholder keys for external use.
	 *
	 * @return string[] Array of placeholder names (without braces).
	 */
	public function get_available_placeholders() {
		return array(
			'chapter_number',
			'chapter_name',
			'manga_name',
			'manga_name_alt',
			'link',
			'manga_link',
			'thumbnail',
			'thumbnail_html',
			'author',
			'artist',
			'genres',
			'type',
			'status',
			'date',
			'date_iso',
			'volume',
			'site_name',
			'site_url',
		);
	}
}
