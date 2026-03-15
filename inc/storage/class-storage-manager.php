<?php
/**
 * Storage Manager.
 *
 * Factory / facade that resolves the configured storage backend and
 * delegates all operations through the Starter_Storage_Interface.
 * Implements a fallback chain: if the primary handler fails, local
 * storage is attempted automatically.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Storage_Manager
 *
 * Singleton — obtain the instance via Starter_Storage_Manager::get_instance().
 */
class Starter_Storage_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Storage_Manager|null
	 */
	private static $instance = null;

	/**
	 * Active primary storage handler.
	 *
	 * @var Starter_Storage_Interface|null
	 */
	private $handler = null;

	/**
	 * Local storage handler (used as fallback).
	 *
	 * @var Starter_Storage_Local|null
	 */
	private $local_handler = null;

	/**
	 * Current storage type key.
	 *
	 * @var string
	 */
	private $storage_type = 'local';

	/**
	 * Allowed MIME types for upload validation.
	 *
	 * @var array
	 */
	private $allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'bmp'          => 'image/bmp',
		'svg'          => 'image/svg+xml',
		'mp4'          => 'video/mp4',
		'webm'         => 'video/webm',
		'mkv'          => 'video/x-matroska',
		'pdf'          => 'application/pdf',
		'zip'          => 'application/zip',
	);

	/**
	 * Maximum file size in bytes (default 50 MB).
	 *
	 * @var int
	 */
	private $max_file_size = 52428800;

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Storage_Manager
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
		$this->storage_type = $this->resolve_storage_type();
		$this->max_file_size = (int) apply_filters(
			'starter_storage_max_file_size',
			$this->max_file_size
		);
		$this->allowed_mimes = (array) apply_filters(
			'starter_storage_allowed_mimes',
			$this->allowed_mimes
		);
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

	/*--------------------------------------------------------------------------
	 * Public API.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload a file.
	 *
	 * Validates the file first, then delegates to the primary handler.
	 * On failure the local handler is attempted as a fallback (unless
	 * local is already the primary).
	 *
	 * @param string $file Absolute path to the file to upload.
	 * @param string $path Relative destination path.
	 * @return string|WP_Error Public URL on success.
	 */
	public function upload( $file, $path ) {
		$validation = $this->validate_file( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$path    = $this->generate_path( $path );
		$handler = $this->get_handler();
		$result  = $handler->upload( $file, $path );

		// Fallback chain: try local storage when the primary fails.
		if ( is_wp_error( $result ) && 'local' !== $this->storage_type ) {
			$result = $this->get_local_handler()->upload( $file, $path );

			if ( ! is_wp_error( $result ) ) {
				/**
				 * Fires when the fallback (local) storage was used because
				 * the primary handler failed.
				 *
				 * @param string   $path           Destination path.
				 * @param string   $storage_type   Primary storage type that failed.
				 * @param WP_Error $original_error The error from the primary handler.
				 */
				do_action( 'starter_storage_fallback_used', $path, $this->storage_type, $result );
			}
		}

		return $result;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Relative path of the file.
	 * @return bool
	 */
	public function delete( $path ) {
		$path = $this->sanitize_path( $path );
		return $this->get_handler()->delete( $path );
	}

	/**
	 * Get the public URL for a stored file.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public function get_url( $path ) {
		$path = $this->sanitize_path( $path );
		return $this->get_handler()->get_url( $path );
	}

	/**
	 * Check whether a file exists.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = $this->sanitize_path( $path );
		return $this->get_handler()->exists( $path );
	}

	/**
	 * Get the current storage type.
	 *
	 * @return string One of 'local', 's3', 'ftp', 'external'.
	 */
	public function get_storage_type() {
		return $this->storage_type;
	}

	/**
	 * Get the raw handler instance (for advanced / backend-specific calls).
	 *
	 * @return Starter_Storage_Interface
	 */
	public function get_handler() {
		if ( null === $this->handler ) {
			$this->handler = $this->create_handler( $this->storage_type );
		}
		return $this->handler;
	}

	/*--------------------------------------------------------------------------
	 * Factory.
	 *------------------------------------------------------------------------*/

	/**
	 * Create a storage handler for the given type.
	 *
	 * @param string $type Storage type key.
	 * @return Starter_Storage_Interface
	 */
	private function create_handler( $type ) {
		switch ( $type ) {
			case 's3':
				return new Starter_Storage_S3();

			case 'ftp':
				return new Starter_Storage_FTP();

			case 'external':
				return new Starter_Storage_External();

			case 'local':
			default:
				return $this->get_local_handler();
		}
	}

	/**
	 * Get (or create) the local storage handler.
	 *
	 * @return Starter_Storage_Local
	 */
	private function get_local_handler() {
		if ( null === $this->local_handler ) {
			$this->local_handler = new Starter_Storage_Local();
		}
		return $this->local_handler;
	}

	/*--------------------------------------------------------------------------
	 * Configuration helpers.
	 *------------------------------------------------------------------------*/

	/**
	 * Resolve the configured storage type.
	 *
	 * Priority: .env STARTER_STORAGE_TYPE > theme option > default ('local').
	 *
	 * @return string
	 */
	private function resolve_storage_type() {
		// Check .env first.
		$env_type = Starter_Env_Loader::get( 'STARTER_STORAGE_TYPE' );

		if ( ! empty( $env_type ) && $this->is_valid_type( $env_type ) ) {
			return sanitize_key( $env_type );
		}

		// Fall back to theme option.
		$option = get_option( 'starter_storage_type', 'local' );

		if ( $this->is_valid_type( $option ) ) {
			return sanitize_key( $option );
		}

		return 'local';
	}

	/**
	 * Validate a storage type string.
	 *
	 * @param string $type Type key.
	 * @return bool
	 */
	private function is_valid_type( $type ) {
		return in_array( $type, array( 'local', 's3', 'ftp', 'external' ), true );
	}

	/*--------------------------------------------------------------------------
	 * File validation.
	 *------------------------------------------------------------------------*/

	/**
	 * Validate a file before upload.
	 *
	 * Checks existence, MIME type, and file size.
	 *
	 * @param string $file Absolute file path.
	 * @return true|WP_Error
	 */
	private function validate_file( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error(
				'starter_storage_file_missing',
				__( 'The file does not exist or is not readable.', 'starter-theme' )
			);
		}

		// Size check.
		$size = filesize( $file );
		if ( $size > $this->max_file_size ) {
			return new WP_Error(
				'starter_storage_file_too_large',
				sprintf(
					/* translators: %s: human-readable size limit */
					__( 'File exceeds the maximum allowed size of %s.', 'starter-theme' ),
					size_format( $this->max_file_size )
				)
			);
		}

		// MIME check.
		$filetype = wp_check_filetype( basename( $file ), $this->allowed_mimes );

		if ( empty( $filetype['type'] ) ) {
			// Double-check with real MIME detection.
			$real_mime  = '';
			$finfo     = new finfo( FILEINFO_MIME_TYPE );
			$real_mime = $finfo->file( $file );

			if ( ! in_array( $real_mime, $this->allowed_mimes, true ) ) {
				return new WP_Error(
					'starter_storage_invalid_mime',
					sprintf(
						/* translators: %s: detected MIME type */
						__( 'File type "%s" is not allowed.', 'starter-theme' ),
						$real_mime
					)
				);
			}
		}

		return true;
	}

	/*--------------------------------------------------------------------------
	 * Path generation.
	 *------------------------------------------------------------------------*/

	/**
	 * Generate an organised storage path.
	 *
	 * If the path already looks fully-qualified (contains at least one slash
	 * and a file extension) it is returned as-is after sanitisation.
	 *
	 * @param string $path Raw destination path or filename.
	 * @return string Sanitised path.
	 */
	private function generate_path( $path ) {
		return $this->sanitize_path( $path );
	}

	/**
	 * Build a canonical path for a manga page image.
	 *
	 * Example: one-piece/chapter-1042/page-001.jpg
	 *
	 * @param string $manga_slug  Manga slug.
	 * @param int    $chapter_num Chapter number.
	 * @param int    $page_num    Page number.
	 * @param string $extension   File extension (without dot).
	 * @return string
	 */
	public function build_manga_path( $manga_slug, $chapter_num, $page_num, $extension = 'jpg' ) {
		$manga_slug  = sanitize_title( $manga_slug );
		$chapter_num = absint( $chapter_num );
		$page_num    = absint( $page_num );
		$extension   = sanitize_file_name( $extension );

		return sprintf(
			'%s/chapter-%d/page-%03d.%s',
			$manga_slug,
			$chapter_num,
			$page_num,
			$extension
		);
	}

	/**
	 * Sanitize a relative storage path.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private function sanitize_path( $path ) {
		$path = str_replace( "\0", '', $path );
		$path = str_replace( '\\', '/', $path );
		$path = str_replace( '../', '', $path );
		$path = ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );

		$parts     = explode( '/', $path );
		$sanitised = array();

		foreach ( $parts as $part ) {
			$part = sanitize_file_name( $part );
			if ( '' !== $part && '.' !== $part && '..' !== $part ) {
				$sanitised[] = $part;
			}
		}

		return implode( '/', $sanitised );
	}
}
