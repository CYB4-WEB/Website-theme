<?php
/**
 * Manga Import Handler.
 *
 * Handles ZIP uploads (flat/nested structures), PDF imports via Imagick,
 * raw image uploads, external URL pasting, and background processing
 * for large files.
 *
 * @package starter-theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Import
 *
 * Processes manga file imports and converts them to chapter structures.
 *
 * @since 1.0.0
 */
class Starter_Manga_Import {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Import|null
	 */
	private static $instance = null;

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_import_nonce';

	/**
	 * Temp directory relative to uploads.
	 *
	 * @var string
	 */
	const TEMP_DIR = 'starter-temp';

	/**
	 * Allowed image MIME types.
	 *
	 * @var array
	 */
	const ALLOWED_IMAGE_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	/**
	 * Allowed image extensions.
	 *
	 * @var array
	 */
	const ALLOWED_IMAGE_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Allowed archive extensions.
	 *
	 * @var array
	 */
	const ALLOWED_ARCHIVE_EXTS = array( 'zip' );

	/**
	 * Allowed document extensions.
	 *
	 * @var array
	 */
	const ALLOWED_DOC_EXTS = array( 'pdf' );

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Import
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
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'register_ajax_handlers' ) );
		add_action( 'starter_process_zip_background', array( $this, 'process_zip_background' ), 10, 3 );
		add_action( 'starter_cleanup_temp_dir', array( $this, 'cleanup_temp_directory' ) );

		// Schedule hourly cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'starter_cleanup_temp_dir' ) ) {
			wp_schedule_event( time(), 'hourly', 'starter_cleanup_temp_dir' );
		}
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register_ajax_handlers() {
		add_action( 'wp_ajax_starter_import_upload', array( $this, 'ajax_handle_upload' ) );
		add_action( 'wp_ajax_starter_import_external_urls', array( $this, 'ajax_handle_external_urls' ) );
		add_action( 'wp_ajax_starter_import_progress', array( $this, 'ajax_check_progress' ) );
	}

	/**
	 * Get maximum file size for a given type.
	 *
	 * @param string $type File type: zip, image.
	 * @return int Max size in bytes.
	 */
	private function get_max_file_size( $type = 'image' ) {
		$defaults = array(
			'zip'   => 100 * MB_IN_BYTES,
			'image' => 10 * MB_IN_BYTES,
		);

		$option_key = 'starter_max_' . $type . '_size';
		$custom     = get_option( $option_key, 0 );

		if ( $custom > 0 ) {
			return absint( $custom );
		}

		return isset( $defaults[ $type ] ) ? $defaults[ $type ] : $defaults['image'];
	}

	/**
	 * Get the temp directory path, creating it if needed.
	 *
	 * @return string Absolute path to temp directory.
	 */
	private function get_temp_dir() {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . self::TEMP_DIR;

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );

			// Add index.php for security.
			$index_file = trailingslashit( $temp_dir ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index_file, '<?php // Silence is golden.' );
			}
		}

		return $temp_dir;
	}

	/**
	 * AJAX handler: Handle file upload (ZIP, PDF, images).
	 *
	 * @return void
	 */
	public function ajax_handle_upload() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'starter' ) ) );
		}

		$manga_id   = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $manga_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga ID.', 'starter' ) ) );
		}

		if ( empty( $_FILES['files'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No files uploaded.', 'starter' ) ) );
		}

		$files  = $_FILES['files'];
		$result = array();

		// Normalize single file to array format.
		if ( ! is_array( $files['name'] ) ) {
			$files = array(
				'name'     => array( $files['name'] ),
				'type'     => array( $files['type'] ),
				'tmp_name' => array( $files['tmp_name'] ),
				'error'    => array( $files['error'] ),
				'size'     => array( $files['size'] ),
			);
		}

		$file_count = count( $files['name'] );

		// Single ZIP file.
		if ( 1 === $file_count && $this->is_zip_file( $files['name'][0] ) ) {
			$result = $this->process_zip_upload( $files, 0, $manga_id, $chapter_id );
		} elseif ( 1 === $file_count && $this->is_pdf_file( $files['name'][0] ) ) {
			// Single PDF.
			$result = $this->process_pdf_upload( $files, 0, $manga_id, $chapter_id );
		} else {
			// Multiple images = one chapter.
			$result = $this->process_image_upload( $files, $manga_id, $chapter_id );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Handle external URL pasting.
	 *
	 * @return void
	 */
	public function ajax_handle_external_urls() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'starter' ) ) );
		}

		$manga_id   = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;
		$urls_raw   = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';

		if ( ! $manga_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga ID.', 'starter' ) ) );
		}

		if ( empty( $urls_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No URLs provided.', 'starter' ) ) );
		}

		$lines      = explode( "\n", $urls_raw );
		$valid_urls = array();
		$errors     = array();

		foreach ( $lines as $line_num => $line ) {
			$url = trim( $line );
			if ( empty( $url ) ) {
				continue;
			}

			$url = esc_url_raw( $url );
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$valid_urls[] = $url;
			} else {
				/* translators: %d: line number */
				$errors[] = sprintf( __( 'Invalid URL on line %d.', 'starter' ), $line_num + 1 );
			}
		}

		if ( empty( $valid_urls ) ) {
			wp_send_json_error( array(
				'message' => __( 'No valid URLs found.', 'starter' ),
				'errors'  => $errors,
			) );
		}

		// Store external URLs as chapter data.
		$chapter_data = array(
			'type'   => 'external',
			'images' => $valid_urls,
		);

		$stored = $this->store_chapter_data( $manga_id, $chapter_id, $chapter_data );

		if ( is_wp_error( $stored ) ) {
			wp_send_json_error( array( 'message' => $stored->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of URLs */
				__( '%d URLs imported successfully.', 'starter' ),
				count( $valid_urls )
			),
			'count'   => count( $valid_urls ),
			'errors'  => $errors,
		) );
	}

	/**
	 * AJAX handler: Check import progress.
	 *
	 * @return void
	 */
	public function ajax_check_progress() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'starter' ) ) );
		}

		$task_id  = isset( $_POST['task_id'] ) ? sanitize_key( $_POST['task_id'] ) : '';
		$progress = get_transient( 'starter_import_progress_' . $task_id );

		if ( false === $progress ) {
			wp_send_json_error( array( 'message' => __( 'Task not found.', 'starter' ) ) );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Check if a filename is a ZIP file.
	 *
	 * @param string $filename File name.
	 * @return bool
	 */
	private function is_zip_file( $filename ) {
		$ext = strtolower( pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION ) );
		return in_array( $ext, self::ALLOWED_ARCHIVE_EXTS, true );
	}

	/**
	 * Check if a filename is a PDF file.
	 *
	 * @param string $filename File name.
	 * @return bool
	 */
	private function is_pdf_file( $filename ) {
		$ext = strtolower( pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION ) );
		return in_array( $ext, self::ALLOWED_DOC_EXTS, true );
	}

	/**
	 * Check if a filename is an allowed image.
	 *
	 * @param string $filename File name.
	 * @return bool
	 */
	private function is_image_file( $filename ) {
		$ext = strtolower( pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION ) );
		return in_array( $ext, self::ALLOWED_IMAGE_EXTS, true );
	}

	/**
	 * Validate a file's MIME type against allowed types.
	 *
	 * @param string $file_path Absolute file path.
	 * @param array  $allowed   Allowed MIME types.
	 * @return bool
	 */
	private function validate_mime_type( $file_path, $allowed ) {
		$filetype = wp_check_filetype_and_ext( $file_path, basename( $file_path ) );
		$mime     = $filetype['type'];

		if ( ! $mime ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
		}

		return in_array( $mime, $allowed, true );
	}

	/**
	 * Process a ZIP upload.
	 *
	 * For large files, delegates to background processing.
	 *
	 * @param array $files      $_FILES array.
	 * @param int   $index      File index.
	 * @param int   $manga_id   Manga post ID.
	 * @param int   $chapter_id Chapter ID.
	 * @return array|WP_Error Result data or error.
	 */
	private function process_zip_upload( $files, $index, $manga_id, $chapter_id ) {
		$file_size = $files['size'][ $index ];
		$max_size  = $this->get_max_file_size( 'zip' );

		if ( $file_size > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: max file size */
					__( 'ZIP file exceeds maximum size of %s.', 'starter' ),
					size_format( $max_size )
				)
			);
		}

		if ( UPLOAD_ERR_OK !== $files['error'][ $index ] ) {
			return new WP_Error( 'upload_error', __( 'File upload error.', 'starter' ) );
		}

		// Move to temp directory.
		$temp_dir  = $this->get_temp_dir();
		$temp_file = trailingslashit( $temp_dir ) . wp_unique_filename( $temp_dir, sanitize_file_name( $files['name'][ $index ] ) );

		if ( ! move_uploaded_file( $files['tmp_name'][ $index ], $temp_file ) ) {
			return new WP_Error( 'move_error', __( 'Could not move uploaded file.', 'starter' ) );
		}

		// Large files (>50MB): process in background.
		if ( $file_size > 50 * MB_IN_BYTES ) {
			$task_id = wp_generate_uuid4();

			set_transient( 'starter_import_progress_' . $task_id, array(
				'status'  => 'processing',
				'percent' => 0,
				'message' => __( 'Queued for processing...', 'starter' ),
			), HOUR_IN_SECONDS );

			wp_schedule_single_event(
				time(),
				'starter_process_zip_background',
				array( $temp_file, $manga_id, $task_id )
			);

			return array(
				'background' => true,
				'task_id'    => $task_id,
				'message'    => __( 'Large file queued for background processing.', 'starter' ),
			);
		}

		// Process inline.
		return $this->extract_zip( $temp_file, $manga_id, $chapter_id );
	}

	/**
	 * Background processing callback for large ZIPs.
	 *
	 * @param string $zip_path  Path to ZIP file.
	 * @param int    $manga_id  Manga post ID.
	 * @param string $task_id   Task identifier for progress tracking.
	 * @return void
	 */
	public function process_zip_background( $zip_path, $manga_id, $task_id ) {
		$progress_key = 'starter_import_progress_' . $task_id;

		set_transient( $progress_key, array(
			'status'  => 'processing',
			'percent' => 10,
			'message' => __( 'Extracting ZIP...', 'starter' ),
		), HOUR_IN_SECONDS );

		$result = $this->extract_zip( $zip_path, $manga_id, 0, $task_id );

		if ( is_wp_error( $result ) ) {
			set_transient( $progress_key, array(
				'status'  => 'error',
				'percent' => 0,
				'message' => $result->get_error_message(),
			), HOUR_IN_SECONDS );
		} else {
			set_transient( $progress_key, array(
				'status'  => 'complete',
				'percent' => 100,
				'message' => __( 'Import complete.', 'starter' ),
				'data'    => $result,
			), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Extract and process a ZIP file.
	 *
	 * Detects structure: flat images, folders as chapters, or nested.
	 *
	 * @param string $zip_path   Path to ZIP file.
	 * @param int    $manga_id   Manga post ID.
	 * @param int    $chapter_id Chapter ID (0 for auto-detection from folders).
	 * @param string $task_id    Optional task ID for progress tracking.
	 * @return array|WP_Error Chapter data array or error.
	 */
	private function extract_zip( $zip_path, $manga_id, $chapter_id = 0, $task_id = '' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip_support', __( 'ZipArchive PHP extension is required.', 'starter' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_error', __( 'Could not open ZIP file.', 'starter' ) );
		}

		$temp_dir    = $this->get_temp_dir();
		$extract_dir = trailingslashit( $temp_dir ) . 'extract_' . wp_generate_uuid4();
		wp_mkdir_p( $extract_dir );

		/* ZIP Slip prevention: manually extract and verify each entry stays within $extract_dir. */
		$real_extract_dir = realpath( $extract_dir );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );
			if ( false === $entry_name ) {
				continue;
			}
			/* Resolve destination and confirm it stays inside $extract_dir. */
			$dest_path = $real_extract_dir . DIRECTORY_SEPARATOR . $entry_name;
			$dest_dir  = realpath( dirname( $dest_path ) );
			if ( false !== $dest_dir && strpos( $dest_dir . DIRECTORY_SEPARATOR, $real_extract_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
				continue; /* Path traversal attempt — skip. */
			}
			if ( substr( $entry_name, -1 ) === '/' ) {
				wp_mkdir_p( $dest_path );
				continue;
			}
			wp_mkdir_p( dirname( $dest_path ) );
			$stream = $zip->getStream( $entry_name );
			if ( $stream ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$fp = fopen( $dest_path, 'wb' );
				if ( $fp ) {
					stream_copy_to_stream( $stream, $fp );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
				}
				fclose( $stream );
			}
		}
		$zip->close();

		// Clean up the ZIP file.
		wp_delete_file( $zip_path );

		// Analyze structure.
		$structure = $this->detect_zip_structure( $extract_dir );
		$chapters  = array();

		if ( $task_id ) {
			set_transient( 'starter_import_progress_' . $task_id, array(
				'status'  => 'processing',
				'percent' => 40,
				'message' => __( 'Processing images...', 'starter' ),
			), HOUR_IN_SECONDS );
		}

		switch ( $structure['type'] ) {
			case 'flat':
				// All images in root = single chapter.
				$images    = $this->collect_and_sort_images( $extract_dir );
				$stored    = $this->store_images_as_chapter( $images, $manga_id, $chapter_id );
				if ( ! is_wp_error( $stored ) ) {
					$chapters[] = $stored;
				}
				break;

			case 'folders':
				// Each folder = one chapter.
				$folders = $structure['folders'];
				natsort( $folders );
				$total = count( $folders );

				foreach ( array_values( $folders ) as $idx => $folder ) {
					$folder_path = trailingslashit( $extract_dir ) . $folder;
					$images      = $this->collect_and_sort_images( $folder_path );

					if ( ! empty( $images ) ) {
						$stored = $this->store_images_as_chapter( $images, $manga_id, 0 );
						if ( ! is_wp_error( $stored ) ) {
							$chapters[] = $stored;
						}
					}

					if ( $task_id && $total > 0 ) {
						$percent = 40 + (int) ( ( $idx + 1 ) / $total * 50 );
						set_transient( 'starter_import_progress_' . $task_id, array(
							'status'  => 'processing',
							'percent' => $percent,
							'message' => sprintf(
								/* translators: %1$d: current, %2$d: total */
								__( 'Processing chapter %1$d of %2$d...', 'starter' ),
								$idx + 1,
								$total
							),
						), HOUR_IN_SECONDS );
					}
				}
				break;

			case 'nested':
				// Single root folder containing images or sub-folders.
				$root_folder = trailingslashit( $extract_dir ) . $structure['root'];
				$sub_result  = $this->detect_zip_structure( $root_folder );

				if ( 'folders' === $sub_result['type'] ) {
					$folders = $sub_result['folders'];
					natsort( $folders );

					foreach ( array_values( $folders ) as $folder ) {
						$folder_path = trailingslashit( $root_folder ) . $folder;
						$images      = $this->collect_and_sort_images( $folder_path );

						if ( ! empty( $images ) ) {
							$stored = $this->store_images_as_chapter( $images, $manga_id, 0 );
							if ( ! is_wp_error( $stored ) ) {
								$chapters[] = $stored;
							}
						}
					}
				} else {
					$images = $this->collect_and_sort_images( $root_folder );
					$stored = $this->store_images_as_chapter( $images, $manga_id, $chapter_id );
					if ( ! is_wp_error( $stored ) ) {
						$chapters[] = $stored;
					}
				}
				break;
		}

		// Clean up extract directory.
		$this->recursive_delete( $extract_dir );

		return array(
			'chapters' => $chapters,
			'count'    => count( $chapters ),
			'message'  => sprintf(
				/* translators: %d: number of chapters */
				__( '%d chapter(s) imported.', 'starter' ),
				count( $chapters )
			),
		);
	}

	/**
	 * Detect the structure of an extracted ZIP directory.
	 *
	 * @param string $dir Directory path.
	 * @return array Structure info with type and details.
	 */
	private function detect_zip_structure( $dir ) {
		$items   = scandir( $dir );
		$images  = array();
		$folders = array();

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $item;

			if ( is_dir( $path ) ) {
				// Skip macOS metadata.
				if ( '__MACOSX' === $item ) {
					continue;
				}
				$folders[] = $item;
			} elseif ( $this->is_image_file( $item ) ) {
				$images[] = $item;
			}
		}

		// Single root folder (nested).
		if ( empty( $images ) && 1 === count( $folders ) ) {
			return array(
				'type' => 'nested',
				'root' => $folders[0],
			);
		}

		// Multiple folders (each is a chapter).
		if ( ! empty( $folders ) && empty( $images ) ) {
			return array(
				'type'    => 'folders',
				'folders' => $folders,
			);
		}

		// Flat: images in root.
		return array(
			'type'   => 'flat',
			'images' => $images,
		);
	}

	/**
	 * Collect all images from a directory and sort naturally.
	 *
	 * @param string $dir Directory path.
	 * @return array Sorted array of absolute image paths.
	 */
	private function collect_and_sort_images( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$items  = scandir( $dir );
		$images = array();

		foreach ( $items as $item ) {
			if ( $this->is_image_file( $item ) ) {
				$images[] = $item;
			}
		}

		// Natural sort: 1, 2, 3, ... 10, 11 (not 1, 10, 11, 2).
		natsort( $images );

		$full_paths = array();
		foreach ( array_values( $images ) as $image ) {
			$full_paths[] = trailingslashit( $dir ) . $image;
		}

		return $full_paths;
	}

	/**
	 * Store collected images as a chapter via Starter_Storage_Manager.
	 *
	 * @param array $image_paths Array of absolute file paths.
	 * @param int   $manga_id   Manga post ID.
	 * @param int   $chapter_id Chapter ID.
	 * @return array|WP_Error Chapter data or error.
	 */
	private function store_images_as_chapter( $image_paths, $manga_id, $chapter_id ) {
		if ( empty( $image_paths ) ) {
			return new WP_Error( 'no_images', __( 'No images found.', 'starter' ) );
		}

		$stored_urls = array();

		// Use Storage Manager if available, otherwise fallback to wp_upload_dir.
		$use_storage_manager = class_exists( 'Starter_Storage_Manager' );

		foreach ( $image_paths as $image_path ) {
			// Validate MIME type.
			if ( ! $this->validate_mime_type( $image_path, self::ALLOWED_IMAGE_MIMES ) ) {
				continue;
			}

			// Validate file size.
			$max_size = $this->get_max_file_size( 'image' );
			if ( filesize( $image_path ) > $max_size ) {
				continue;
			}

			if ( $use_storage_manager ) {
				$storage = Starter_Storage_Manager::get_instance();
				$url     = $storage->store( $image_path, 'manga/' . $manga_id );

				if ( ! is_wp_error( $url ) ) {
					$stored_urls[] = $url;
				}
			} else {
				// Fallback: move to uploads directory.
				$upload_dir  = wp_upload_dir();
				$target_dir  = trailingslashit( $upload_dir['basedir'] ) . 'manga/' . $manga_id;
				wp_mkdir_p( $target_dir );

				$filename = wp_unique_filename( $target_dir, basename( $image_path ) );
				$target   = trailingslashit( $target_dir ) . $filename;

				if ( copy( $image_path, $target ) ) {
					$stored_urls[] = trailingslashit( $upload_dir['baseurl'] ) . 'manga/' . $manga_id . '/' . $filename;
				}
			}
		}

		if ( empty( $stored_urls ) ) {
			return new WP_Error( 'storage_failed', __( 'Failed to store any images.', 'starter' ) );
		}

		$chapter_data = array(
			'type'   => 'local',
			'images' => $stored_urls,
		);

		return $this->store_chapter_data( $manga_id, $chapter_id, $chapter_data );
	}

	/**
	 * Process a PDF upload.
	 *
	 * Uses Imagick to extract pages as JPG if available,
	 * otherwise stores the PDF directly.
	 *
	 * @param array $files      $_FILES array.
	 * @param int   $index      File index.
	 * @param int   $manga_id   Manga post ID.
	 * @param int   $chapter_id Chapter ID.
	 * @return array|WP_Error Result data or error.
	 */
	private function process_pdf_upload( $files, $index, $manga_id, $chapter_id ) {
		$max_size = $this->get_max_file_size( 'zip' ); // Use ZIP limit for PDFs.

		if ( $files['size'][ $index ] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: max file size */
					__( 'PDF file exceeds maximum size of %s.', 'starter' ),
					size_format( $max_size )
				)
			);
		}

		if ( UPLOAD_ERR_OK !== $files['error'][ $index ] ) {
			return new WP_Error( 'upload_error', __( 'File upload error.', 'starter' ) );
		}

		$temp_dir  = $this->get_temp_dir();
		$temp_file = trailingslashit( $temp_dir ) . wp_unique_filename( $temp_dir, sanitize_file_name( $files['name'][ $index ] ) );

		if ( ! move_uploaded_file( $files['tmp_name'][ $index ], $temp_file ) ) {
			return new WP_Error( 'move_error', __( 'Could not move uploaded file.', 'starter' ) );
		}

		// Try Imagick extraction.
		if ( class_exists( 'Imagick' ) ) {
			return $this->extract_pdf_pages( $temp_file, $manga_id, $chapter_id );
		}

		// Fallback: store PDF directly.
		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'manga/' . $manga_id;
		wp_mkdir_p( $target_dir );

		$filename = wp_unique_filename( $target_dir, basename( $temp_file ) );
		$target   = trailingslashit( $target_dir ) . $filename;

		if ( ! rename( $temp_file, $target ) ) {
			return new WP_Error( 'move_error', __( 'Could not move PDF file.', 'starter' ) );
		}

		$pdf_url = trailingslashit( $upload_dir['baseurl'] ) . 'manga/' . $manga_id . '/' . $filename;

		$chapter_data = array(
			'type'   => 'pdf',
			'url'    => $pdf_url,
			'images' => array( $pdf_url ),
		);

		return $this->store_chapter_data( $manga_id, $chapter_id, $chapter_data );
	}

	/**
	 * Extract PDF pages as JPG images using Imagick.
	 *
	 * @param string $pdf_path   Path to PDF file.
	 * @param int    $manga_id   Manga post ID.
	 * @param int    $chapter_id Chapter ID.
	 * @return array|WP_Error Result data or error.
	 */
	private function extract_pdf_pages( $pdf_path, $manga_id, $chapter_id ) {
		try {
			$imagick = new Imagick();
			$imagick->setResolution( 150, 150 );
			$imagick->readImage( $pdf_path );

			$page_count = $imagick->getNumberImages();
			$temp_dir   = $this->get_temp_dir();
			$pages_dir  = trailingslashit( $temp_dir ) . 'pdf_pages_' . wp_generate_uuid4();
			wp_mkdir_p( $pages_dir );

			for ( $i = 0; $i < $page_count; $i++ ) {
				$imagick->setIteratorIndex( $i );
				$imagick->setImageFormat( 'jpg' );
				$imagick->setImageCompressionQuality( 85 );

				$page_file = trailingslashit( $pages_dir ) . sprintf( 'page_%04d.jpg', $i + 1 );
				$imagick->writeImage( $page_file );
			}

			$imagick->clear();
			$imagick->destroy();

			// Clean up the PDF.
			wp_delete_file( $pdf_path );

			// Collect page images and store as chapter.
			$images = $this->collect_and_sort_images( $pages_dir );
			$result = $this->store_images_as_chapter( $images, $manga_id, $chapter_id );

			// Clean up extracted pages.
			$this->recursive_delete( $pages_dir );

			return $result;

		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_error', $e->getMessage() );
		}
	}

	/**
	 * Process raw image uploads (multiple files = one chapter).
	 *
	 * @param array $files      $_FILES array (normalized to multi).
	 * @param int   $manga_id   Manga post ID.
	 * @param int   $chapter_id Chapter ID.
	 * @return array|WP_Error Result data or error.
	 */
	private function process_image_upload( $files, $manga_id, $chapter_id ) {
		$max_size    = $this->get_max_file_size( 'image' );
		$temp_dir    = $this->get_temp_dir();
		$batch_dir   = trailingslashit( $temp_dir ) . 'batch_' . wp_generate_uuid4();
		wp_mkdir_p( $batch_dir );

		$errors = array();
		$count  = count( $files['name'] );

		for ( $i = 0; $i < $count; $i++ ) {
			$name = sanitize_file_name( $files['name'][ $i ] );

			if ( ! $this->is_image_file( $name ) ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( 'Skipped non-image file: %s', 'starter' ), $name );
				continue;
			}

			if ( $files['size'][ $i ] > $max_size ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( 'File too large: %s', 'starter' ), $name );
				continue;
			}

			if ( UPLOAD_ERR_OK !== $files['error'][ $i ] ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( 'Upload error for: %s', 'starter' ), $name );
				continue;
			}

			$target = trailingslashit( $batch_dir ) . wp_unique_filename( $batch_dir, $name );
			move_uploaded_file( $files['tmp_name'][ $i ], $target );
		}

		$images = $this->collect_and_sort_images( $batch_dir );
		$result = $this->store_images_as_chapter( $images, $manga_id, $chapter_id );

		$this->recursive_delete( $batch_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $errors ) ) {
			$result['warnings'] = $errors;
		}

		return $result;
	}

	/**
	 * Store chapter data (JSON array of image URLs) to the chapter system.
	 *
	 * @param int   $manga_id     Manga post ID.
	 * @param int   $chapter_id   Chapter ID (0 to create new).
	 * @param array $chapter_data Chapter data array.
	 * @return array|WP_Error Stored chapter info or error.
	 */
	private function store_chapter_data( $manga_id, $chapter_id, $chapter_data ) {
		/**
		 * Filter chapter data before storage.
		 *
		 * @param array $chapter_data Chapter data.
		 * @param int   $manga_id    Manga post ID.
		 * @param int   $chapter_id  Chapter ID.
		 */
		$chapter_data = apply_filters( 'starter_import_chapter_data', $chapter_data, $manga_id, $chapter_id );

		// Store as chapter meta if chapter manager is available.
		if ( class_exists( 'Starter_Manga_Chapter' ) ) {
			$chapter_manager = Starter_Manga_Chapter::get_instance();

			if ( method_exists( $chapter_manager, 'update_chapter_images' ) ) {
				$chapter_manager->update_chapter_images( $chapter_id, $chapter_data['images'] );
			}
		}

		return array(
			'manga_id'    => $manga_id,
			'chapter_id'  => $chapter_id,
			'image_count' => count( $chapter_data['images'] ),
			'images'      => $chapter_data['images'],
			'type'        => $chapter_data['type'],
		);
	}

	/**
	 * Clean up the temp directory (removes files older than 1 hour).
	 *
	 * Hooked to the hourly cron event.
	 *
	 * @return void
	 */
	public function cleanup_temp_directory() {
		$temp_dir = $this->get_temp_dir();

		if ( ! is_dir( $temp_dir ) ) {
			return;
		}

		$items    = new DirectoryIterator( $temp_dir );
		$one_hour = time() - HOUR_IN_SECONDS;

		foreach ( $items as $item ) {
			if ( $item->isDot() ) {
				continue;
			}

			// Keep the index.php.
			if ( 'index.php' === $item->getFilename() ) {
				continue;
			}

			if ( $item->getMTime() < $one_hour ) {
				if ( $item->isDir() ) {
					$this->recursive_delete( $item->getPathname() );
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}
		}
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function recursive_delete( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		rmdir( $dir );
	}
}
