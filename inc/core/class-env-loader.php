<?php
/**
 * Environment variable loader.
 *
 * Loads a .env file from a secure location (above web root with fallback
 * to the theme directory) and exposes values via $_ENV and putenv().
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Env_Loader
 *
 * Singleton that parses .env files and sets environment variables.
 */
class Starter_Env_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Env_Loader|null
	 */
	private static $instance = null;

	/**
	 * Path to the .env file (never exposed publicly).
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Parsed key-value store.
	 *
	 * @var array
	 */
	private $vars = array();

	/**
	 * Whether the .env file has been loaded.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Env_Loader
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {
		$this->resolve_path();
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

	/**
	 * Initialize the loader by hooking into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		$this->load();
		$this->protect_env_file();
	}

	/**
	 * Resolve the .env file path.
	 *
	 * Checks above the web root first (one directory above ABSPATH),
	 * then falls back to the theme directory.
	 *
	 * @return void
	 */
	private function resolve_path() {
		// Prefer a location above the web root for security.
		$above_root = dirname( ABSPATH ) . '/.env';
		$theme_root = get_template_directory() . '/.env';

		if ( file_exists( $above_root ) && is_readable( $above_root ) ) {
			$this->path = $above_root;
		} elseif ( file_exists( $theme_root ) && is_readable( $theme_root ) ) {
			$this->path = $theme_root;
		}
	}

	/**
	 * Load and parse the .env file.
	 *
	 * Supports KEY=VALUE pairs, # comments, single/double-quoted values,
	 * and inline comments on unquoted values.
	 *
	 * @return bool True if file was loaded, false otherwise.
	 */
	public function load() {
		if ( $this->loaded ) {
			return true;
		}

		if ( empty( $this->path ) || ! file_exists( $this->path ) || ! is_readable( $this->path ) ) {
			return false;
		}

		$lines = file( $this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( ! is_array( $lines ) ) {
			return false;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip comments and empty lines.
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			// Must contain an = sign.
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );

			// Skip invalid keys.
			if ( empty( $key ) ) {
				continue;
			}

			$value = $this->parse_value( $value );

			// Store internally.
			$this->vars[ $key ] = $value;

			// Set in $_ENV and putenv().
			$_ENV[ $key ] = $value;
			putenv( "{$key}={$value}" );

			// Also define as STARTER_ prefixed constant for convenience.
			$constant_name = 'STARTER_' . strtoupper( $key );
			if ( ! defined( $constant_name ) ) {
				define( $constant_name, $value );
			}
		}

		$this->loaded = true;
		return true;
	}

	/**
	 * Parse a value string, handling quotes and inline comments.
	 *
	 * @param string $value Raw value from the .env line.
	 * @return string Parsed value.
	 */
	private function parse_value( $value ) {
		if ( '' === $value ) {
			return '';
		}

		$first_char = $value[0];

		// Handle double-quoted values.
		if ( '"' === $first_char ) {
			preg_match( '/^"([^"]*)"/', $value, $matches );
			if ( ! empty( $matches[1] ) ) {
				// Process escape sequences in double-quoted strings.
				return str_replace(
					array( '\\n', '\\t', '\\"', '\\\\' ),
					array( "\n", "\t", '"', '\\' ),
					$matches[1]
				);
			}
			return '';
		}

		// Handle single-quoted values (no escape processing).
		if ( "'" === $first_char ) {
			preg_match( "/^'([^']*)'/", $value, $matches );
			return isset( $matches[1] ) ? $matches[1] : '';
		}

		// Unquoted value — strip inline comments.
		$hash_pos = strpos( $value, ' #' );
		if ( false !== $hash_pos ) {
			$value = substr( $value, 0, $hash_pos );
		}

		return trim( $value );
	}

	/**
	 * Get an environment variable value.
	 *
	 * @param string $key     The variable name.
	 * @param string $default Default value if the key is not set.
	 * @return string
	 */
	public static function get( $key, $default = '' ) {
		$instance = self::get_instance();

		if ( isset( $instance->vars[ $key ] ) ) {
			return $instance->vars[ $key ];
		}

		$env_value = getenv( $key );
		if ( false !== $env_value ) {
			return $env_value;
		}

		return $default;
	}

	/**
	 * Check whether a key exists.
	 *
	 * @param string $key The variable name.
	 * @return bool
	 */
	public function has( $key ) {
		return isset( $this->vars[ $key ] );
	}

	/**
	 * Get all loaded variables.
	 *
	 * @return array
	 */
	public function all() {
		return $this->vars;
	}

	/**
	 * Write a key-value pair to the .env file.
	 *
	 * @param string $key   Variable name.
	 * @param string $value Variable value.
	 * @return bool True on success.
	 */
	public function set( $key, $value ) {
		$key   = sanitize_text_field( $key );
		$value = sanitize_text_field( $value );

		if ( empty( $this->path ) ) {
			// Default to theme directory if no path resolved.
			$this->path = get_template_directory() . '/.env';
		}

		$this->vars[ $key ] = $value;
		$_ENV[ $key ]       = $value;
		putenv( "{$key}={$value}" );

		// Read existing file content.
		$content = '';
		if ( file_exists( $this->path ) ) {
			$content = file_get_contents( $this->path );
		}

		// Update or append the key.
		$pattern = '/^' . preg_quote( $key, '/' ) . '=.*/m';
		$line    = "{$key}={$value}";

		if ( preg_match( $pattern, $content ) ) {
			$content = preg_replace( $pattern, $line, $content );
		} else {
			$content = rtrim( $content, "\n" ) . "\n" . $line . "\n";
		}

		return (bool) file_put_contents( $this->path, $content, LOCK_EX );
	}

	/**
	 * Protect the .env file from direct access via .htaccess.
	 *
	 * @return void
	 */
	private function protect_env_file() {
		$theme_dir      = get_template_directory();
		$htaccess_path  = $theme_dir . '/.htaccess';

		$rules = <<<'HTACCESS'
# Protect sensitive files from direct access
<FilesMatch "^\.env$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

<FilesMatch "\.(env|env\.example|env\.local|env\.backup)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS;

		// Only write if the file does not exist or does not contain our rules.
		if ( ! file_exists( $htaccess_path ) || false === strpos( file_get_contents( $htaccess_path ), 'Protect sensitive files' ) ) {
			file_put_contents( $htaccess_path, $rules . "\n", LOCK_EX );
		}
	}
}
