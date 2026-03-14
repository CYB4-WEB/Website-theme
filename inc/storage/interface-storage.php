<?php
/**
 * Storage interface.
 *
 * Defines the contract all storage handlers must implement.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Starter_Storage_Interface
 *
 * Every storage backend (local, S3, FTP, external) must implement this
 * interface so the Storage Manager can treat them interchangeably.
 */
interface Starter_Storage_Interface {

	/**
	 * Upload a file to the storage backend.
	 *
	 * @param string $file_path   Absolute local path to the file being uploaded.
	 * @param string $destination Relative destination path inside the storage
	 *                            (e.g. "manga-slug/chapter-1/page-001.jpg").
	 * @return string|WP_Error    Public URL on success, WP_Error on failure.
	 */
	public function upload( $file_path, $destination );

	/**
	 * Delete a file from the storage backend.
	 *
	 * @param string $path Relative path of the file to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $path );

	/**
	 * Get the public URL for a stored file.
	 *
	 * @param string $path Relative path of the file.
	 * @return string Fully-qualified URL.
	 */
	public function get_url( $path );

	/**
	 * Check whether a file exists in the storage backend.
	 *
	 * @param string $path Relative path of the file.
	 * @return bool True if the file exists.
	 */
	public function exists( $path );
}
