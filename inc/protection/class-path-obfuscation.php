<?php
/**
 * Path obfuscation.
 *
 * Maps internal file paths to hashed public identifiers so that real
 * server paths are never exposed in URLs or page source. Provides a
 * proxy endpoint, rewrite rules, and database-backed mapping table.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Path_Obfuscation
 *
 * Handles:
 *  - Database table for hash-to-path mappings.
 *  - HMAC-SHA256 hashing of real paths (truncated to 16 chars).
 *  - Clean rewrite rules: /content/{hash} serves the actual file.
 *  - Object-cache / transient caching of mappings.
 *  - Stale mapping cleanup (configurable retention).
 *  - .htaccess rules to deny direct access to upload directories.
 */
class Starter_Path_Obfuscation {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Path_Obfuscation|null
	 */
	private static $instance = null;

	/**
	 * HMAC secret key.
	 *
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Database table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_SUFFIX = 'starter_path_map';

	/**
	 * Rewrite endpoint base.
	 *
	 * @var string
	 */
	const ENDPOINT = 'content';

	/**
	 * Query variable for the path hash.
	 *
	 * @var string
	 */
	const QV_HASH = 'starter_content_hash';

	/**
	 * Length of the truncated hash used in URLs.
	 *
	 * @var int
	 */
	const HASH_LENGTH = 16;

	/**
	 * Default stale mapping retention in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Cache group for object-cache lookups.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'starter_path_map';

	/**
	 * Protected upload sub-directory.
	 *
	 * @var string
	 */
	const PROTECTED_DIR = 'starter-manga';

	/**
	 * Supported MIME types keyed by extension.
	 *
	 * @var string[]
	 */
	private $mime_map = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Path_Obfuscation
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->secret_key = Starter_Env_Loader::get( 'ENCRYPTION_KEY', wp_salt( 'auth' ) );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/*------------------------------------------------------------------
	 * Initialization
	 *-----------------------------------------------------------------*/

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Rewrite rules.
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_content_request' ) );

		// Theme activation: create DB table, flush rewrites, write .htaccess.
		add_action( 'after_switch_theme', array( $this, 'on_theme_activation' ) );

		// Scheduled cleanup of stale mappings.
		add_action( 'starter_cleanup_path_map', array( $this, 'cleanup_stale_mappings' ) );
		if ( ! wp_next_scheduled( 'starter_cleanup_path_map' ) ) {
			wp_schedule_event( time(), 'daily', 'starter_cleanup_path_map' );
		}
	}

	/**
	 * Run all activation tasks: DB table, rewrites, .htaccess.
	 *
	 * @return void
	 */
	public function on_theme_activation() {
		$this->create_table();
		$this->add_rewrite_rules();
		flush_rewrite_rules();
		$this->write_htaccess_protection();
	}

	/*------------------------------------------------------------------
	 * Database table
	 *-----------------------------------------------------------------*/

	/**
	 * Get the full table name including the WP prefix.
	 *
	 * @return string
	 */
	public function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create the path mapping table if it does not exist.
	 *
	 * Schema:
	 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
	 *   hash       VARCHAR(16) UNIQUE
	 *   real_path  TEXT
	 *   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table   = $this->get_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hash VARCHAR(16) NOT NULL,
			real_path TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY hash (hash),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/*------------------------------------------------------------------
	 * Hashing & mapping
	 *-----------------------------------------------------------------*/

	/**
	 * Generate a truncated HMAC-SHA256 hash for a real path.
	 *
	 * @param string $real_path Absolute file path on disk.
	 * @return string 16-character hex hash.
	 */
	public function generate_hash( $real_path ) {
		$full_hash = hash_hmac( 'sha256', $real_path, $this->secret_key );
		return substr( $full_hash, 0, self::HASH_LENGTH );
	}

	/**
	 * Register (or retrieve) the public hash for a real path.
	 *
	 * If the mapping already exists the existing hash is returned.
	 * Otherwise a new row is inserted and the hash is cached.
	 *
	 * @param string $real_path Absolute file path on disk.
	 * @return string 16-character hex hash.
	 */
	public function register_path( $real_path ) {
		$hash = $this->generate_hash( $real_path );

		// Try object cache first.
		$cached = wp_cache_get( $hash, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $hash;
		}

		// Try transient.
		$transient_key = 'starter_pm_' . $hash;
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			wp_cache_set( $hash, $cached, self::CACHE_GROUP, DAY_IN_SECONDS );
			return $hash;
		}

		// Check database.
		global $wpdb;
		$table    = $this->get_table_name();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT real_path FROM {$table} WHERE hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);

		if ( null !== $existing ) {
			// Refresh caches.
			wp_cache_set( $hash, $existing, self::CACHE_GROUP, DAY_IN_SECONDS );
			set_transient( $transient_key, $existing, DAY_IN_SECONDS );
			return $hash;
		}

		// Insert new mapping.
		$wpdb->insert(
			$table,
			array(
				'hash'      => $hash,
				'real_path' => $real_path,
			),
			array( '%s', '%s' )
		);

		wp_cache_set( $hash, $real_path, self::CACHE_GROUP, DAY_IN_SECONDS );
		set_transient( $transient_key, $real_path, DAY_IN_SECONDS );

		return $hash;
	}

	/**
	 * Resolve a hash back to its real path.
	 *
	 * @param string $hash 16-character hex hash.
	 * @return string|false Real path on success, false if not found.
	 */
	public function resolve_hash( $hash ) {
		// Sanitize.
		$hash = preg_replace( '/[^a-f0-9]/', '', strtolower( $hash ) );
		if ( strlen( $hash ) !== self::HASH_LENGTH ) {
			return false;
		}

		// Object cache.
		$cached = wp_cache_get( $hash, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		// Transient.
		$transient_key = 'starter_pm_' . $hash;
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			wp_cache_set( $hash, $cached, self::CACHE_GROUP, DAY_IN_SECONDS );
			return $cached;
		}

		// Database.
		global $wpdb;
		$table     = $this->get_table_name();
		$real_path = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT real_path FROM {$table} WHERE hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);

		if ( null === $real_path ) {
			return false;
		}

		wp_cache_set( $hash, $real_path, self::CACHE_GROUP, DAY_IN_SECONDS );
		set_transient( $transient_key, $real_path, DAY_IN_SECONDS );

		return $real_path;
	}

	/**
	 * Get the public URL for a registered path.
	 *
	 * @param string $real_path Absolute file path on disk.
	 * @return string Public-facing URL.
	 */
	public function get_public_url( $real_path ) {
		$hash = $this->register_path( $real_path );
		return home_url( self::ENDPOINT . '/' . $hash );
	}

	/*------------------------------------------------------------------
	 * Rewrite rules
	 *-----------------------------------------------------------------*/

	/**
	 * Add rewrite rules for the content proxy.
	 *
	 * URL format: /content/{hash}
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^' . self::ENDPOINT . '/([a-f0-9]{' . self::HASH_LENGTH . '})/?$',
			'index.php?' . self::QV_HASH . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the custom query variable.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QV_HASH;
		return $vars;
	}

	/*------------------------------------------------------------------
	 * Content proxy
	 *-----------------------------------------------------------------*/

	/**
	 * Handle an incoming /content/{hash} request.
	 *
	 * Resolves the hash, validates the file exists and is within the
	 * allowed uploads directory, then streams the file with proper
	 * headers.
	 *
	 * @return void
	 */
	public function handle_content_request() {
		$hash = get_query_var( self::QV_HASH );

		if ( empty( $hash ) ) {
			return; // Not our request.
		}

		$real_path = $this->resolve_hash( $hash );

		if ( false === $real_path ) {
			status_header( 404 );
			echo esc_html__( 'Content not found.', 'starter-theme' );
			exit;
		}

		// Security: ensure the resolved path is within the uploads directory.
		$upload_dir  = wp_upload_dir();
		$uploads_base = realpath( $upload_dir['basedir'] );
		$resolved    = realpath( $real_path );

		if ( false === $resolved || false === $uploads_base || 0 !== strpos( $resolved, $uploads_base ) ) {
			status_header( 403 );
			echo esc_html__( 'Access denied.', 'starter-theme' );
			exit;
		}

		if ( ! file_exists( $resolved ) || ! is_readable( $resolved ) ) {
			status_header( 404 );
			echo esc_html__( 'File not found.', 'starter-theme' );
			exit;
		}

		// Determine content type.
		$extension    = strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) );
		$content_type = isset( $this->mime_map[ $extension ] ) ? $this->mime_map[ $extension ] : 'application/octet-stream';

		// Only serve allowed image types.
		if ( ! isset( $this->mime_map[ $extension ] ) ) {
			status_header( 403 );
			echo esc_html__( 'File type not allowed.', 'starter-theme' );
			exit;
		}

		// Stream the file.
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . filesize( $resolved ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming binary data.
		readfile( $resolved );
		exit;
	}

	/*------------------------------------------------------------------
	 * Cleanup
	 *-----------------------------------------------------------------*/

	/**
	 * Remove stale path mappings older than the configured retention.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_stale_mappings() {
		global $wpdb;

		$retention_days = (int) get_theme_mod(
			'starter_path_map_retention_days',
			self::DEFAULT_RETENTION_DAYS
		);

		if ( $retention_days < 1 ) {
			$retention_days = self::DEFAULT_RETENTION_DAYS;
		}

		$table    = $this->get_table_name();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// Fetch hashes to clear from cache before deleting.
		$stale_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT hash FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		if ( empty( $stale_hashes ) ) {
			return 0;
		}

		// Clear caches.
		foreach ( $stale_hashes as $stale_hash ) {
			wp_cache_delete( $stale_hash, self::CACHE_GROUP );
			delete_transient( 'starter_pm_' . $stale_hash );
		}

		// Delete from database.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/*------------------------------------------------------------------
	 * .htaccess protection
	 *-----------------------------------------------------------------*/

	/**
	 * Write .htaccess rules to protect the manga uploads directory.
	 *
	 * Denies direct HTTP access to wp-content/uploads/starter-manga/
	 * and prevents directory listing.
	 *
	 * @return bool True if the file was written successfully.
	 */
	public function write_htaccess_protection() {
		$upload_dir = wp_upload_dir();
		$manga_dir  = $upload_dir['basedir'] . '/' . self::PROTECTED_DIR;

		// Create the directory if it does not exist.
		if ( ! is_dir( $manga_dir ) ) {
			wp_mkdir_p( $manga_dir );
		}

		$htaccess_path = $manga_dir . '/.htaccess';

		$rules = <<<'HTACCESS'
# Starter Theme — Protect manga upload directory.
# All files in this directory must be served through the proxy.

# Deny all direct access.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Disable directory listing.
Options -Indexes

# Block common hotlinking.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_REFERER} !^$
    RewriteCond %{HTTP_REFERER} !^https?://(www\.)?%{HTTP_HOST} [NC]
    RewriteRule \.(jpe?g|png|gif|webp)$ - [F,NC,L]
</IfModule>
HTACCESS;

		// Only write if the file does not exist or does not contain our marker.
		if ( file_exists( $htaccess_path ) ) {
			$existing = file_get_contents( $htaccess_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== strpos( $existing, 'Starter Theme' ) ) {
				return true; // Already protected.
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return (bool) file_put_contents( $htaccess_path, $rules . "\n", LOCK_EX );
	}

	/**
	 * Remove .htaccess protection (for theme deactivation cleanup).
	 *
	 * @return bool
	 */
	public function remove_htaccess_protection() {
		$upload_dir    = wp_upload_dir();
		$htaccess_path = $upload_dir['basedir'] . '/' . self::PROTECTED_DIR . '/.htaccess';

		if ( file_exists( $htaccess_path ) ) {
			return wp_delete_file( $htaccess_path );
		}

		return true;
	}
}
