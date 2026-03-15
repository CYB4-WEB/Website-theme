<?php
/**
 * Amazon S3 (and S3-compatible) storage handler.
 *
 * Uses the WordPress HTTP API exclusively — no AWS SDK required.  Supports
 * Amazon S3, DigitalOcean Spaces, MinIO, Backblaze B2 (S3-compat), and any
 * endpoint that speaks the S3 REST API.
 *
 * All credentials are loaded from .env via Starter_Env_Loader.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Storage_S3
 */
class Starter_Storage_S3 implements Starter_Storage_Interface {

	/**
	 * AWS access key.
	 *
	 * @var string
	 */
	private $access_key = '';

	/**
	 * AWS secret key.
	 *
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Bucket name.
	 *
	 * @var string
	 */
	private $bucket = '';

	/**
	 * AWS region (e.g. us-east-1).
	 *
	 * @var string
	 */
	private $region = 'us-east-1';

	/**
	 * Custom endpoint URL (for DigitalOcean Spaces, MinIO, etc.).
	 *
	 * @var string
	 */
	private $endpoint = '';

	/**
	 * Whether to use path-style URLs (bucket in path instead of subdomain).
	 *
	 * @var bool
	 */
	private $use_path_style = false;

	/**
	 * S3 service identifier for signing.
	 *
	 * @var string
	 */
	private $service = 's3';

	/**
	 * Multipart upload threshold in bytes (default 5 MB).
	 *
	 * @var int
	 */
	private $multipart_threshold = 5242880;

	/**
	 * Part size for multipart uploads (default 5 MB).
	 *
	 * @var int
	 */
	private $part_size = 5242880;

	/**
	 * Constructor — loads credentials from .env.
	 */
	public function __construct() {
		$this->access_key = Starter_Env_Loader::get( 'STARTER_S3_KEY' );
		$this->secret_key = Starter_Env_Loader::get( 'STARTER_S3_SECRET' );
		$this->bucket     = Starter_Env_Loader::get( 'STARTER_S3_BUCKET' );
		$this->region     = Starter_Env_Loader::get( 'STARTER_S3_REGION', 'us-east-1' );
		$this->endpoint   = untrailingslashit( Starter_Env_Loader::get( 'STARTER_S3_ENDPOINT' ) );

		// Custom endpoints typically use path-style addressing.
		if ( ! empty( $this->endpoint ) ) {
			$this->use_path_style = true;
		}
	}

	/*--------------------------------------------------------------------------
	 * Starter_Storage_Interface methods.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload a file to S3.
	 *
	 * Files larger than the multipart threshold are uploaded with multipart.
	 *
	 * @param string $file_path   Absolute local path.
	 * @param string $destination Relative key in the bucket.
	 * @return string|WP_Error    Public URL on success.
	 */
	public function upload( $file_path, $destination ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'starter_s3_file_missing',
				__( 'Source file does not exist or is not readable.', 'starter-theme' )
			);
		}

		$validation = $this->validate_credentials();
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$destination  = $this->sanitize_key( $destination );
		$content_type = $this->detect_content_type( $file_path );
		$file_size    = filesize( $file_path );

		// Use multipart for large files.
		if ( $file_size > $this->multipart_threshold ) {
			return $this->multipart_upload( $file_path, $destination, $content_type );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body = file_get_contents( $file_path );

		$headers = array(
			'Content-Type'   => $content_type,
			'Content-Length' => $file_size,
			'x-amz-acl'     => 'public-read',
		);

		$response = $this->request( 'PUT', $destination, $headers, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'starter_s3_upload_fail',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'S3 upload failed with HTTP %d.', 'starter-theme' ),
					$code
				)
			);
		}

		return $this->get_url( $destination );
	}

	/**
	 * Delete an object from S3.
	 *
	 * @param string $path Relative key.
	 * @return bool
	 */
	public function delete( $path ) {
		$path     = $this->sanitize_key( $path );
		$response = $this->request( 'DELETE', $path );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// S3 returns 204 on successful deletion.
		return ( 204 === $code || 200 === $code );
	}

	/**
	 * Get the public URL of an object.
	 *
	 * @param string $path Relative key.
	 * @return string
	 */
	public function get_url( $path ) {
		$path = $this->sanitize_key( $path );
		return $this->build_url( $path );
	}

	/**
	 * Check whether an object exists in S3.
	 *
	 * @param string $path Relative key.
	 * @return bool
	 */
	public function exists( $path ) {
		$path     = $this->sanitize_key( $path );
		$response = $this->request( 'HEAD', $path );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return ( 200 === wp_remote_retrieve_response_code( $response ) );
	}

	/*--------------------------------------------------------------------------
	 * Extra public methods.
	 *------------------------------------------------------------------------*/

	/**
	 * List objects under a given prefix.
	 *
	 * @param string $prefix Key prefix (e.g. "manga-slug/chapter-1/").
	 * @param int    $max    Maximum keys to return.
	 * @return array|WP_Error Array of keys on success.
	 */
	public function list_objects( $prefix = '', $max = 1000 ) {
		$query = array(
			'list-type' => '2',
			'prefix'    => $prefix,
			'max-keys'  => $max,
		);

		$response = $this->request( 'GET', '', array(), '', $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$xml  = @simplexml_load_string( $body );

		if ( false === $xml ) {
			return new WP_Error( 'starter_s3_xml_parse', __( 'Could not parse S3 response.', 'starter-theme' ) );
		}

		$keys = array();
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $object ) {
				$keys[] = (string) $object->Key;
			}
		}

		return $keys;
	}

	/**
	 * Generate a pre-signed URL for a private object.
	 *
	 * @param string $path    Relative key.
	 * @param int    $expires Lifetime in seconds (default 3600).
	 * @return string Pre-signed URL.
	 */
	public function get_presigned_url( $path, $expires = 3600 ) {
		$path       = $this->sanitize_key( $path );
		$now        = time();
		$datestamp  = gmdate( 'Ymd', $now );
		$amz_date  = gmdate( 'Ymd\THis\Z', $now );
		$credential = $this->access_key . '/' . $datestamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

		$host     = $this->get_host();
		$uri_path = $this->get_uri_path( $path );

		$query_params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => $expires,
			'X-Amz-SignedHeaders' => 'host',
		);

		ksort( $query_params );
		$canonical_query = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );

		$canonical_headers = 'host:' . $host . "\n";
		$signed_headers    = 'host';

		$canonical_request = implode( "\n", array(
			'GET',
			$uri_path,
			$canonical_query,
			$canonical_headers,
			$signed_headers,
			'UNSIGNED-PAYLOAD',
		) );

		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$datestamp . '/' . $this->region . '/' . $this->service . '/aws4_request',
			hash( 'sha256', $canonical_request ),
		) );

		$signing_key = $this->get_signing_key( $datestamp );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$scheme = ( ! empty( $this->endpoint ) ) ? wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) : 'https';

		return $scheme . '://' . $host . $uri_path . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/*--------------------------------------------------------------------------
	 * Multipart upload.
	 *------------------------------------------------------------------------*/

	/**
	 * Perform a multipart upload for large files.
	 *
	 * @param string $file_path    Absolute local path.
	 * @param string $key          S3 object key.
	 * @param string $content_type MIME type.
	 * @return string|WP_Error     Public URL on success.
	 */
	private function multipart_upload( $file_path, $key, $content_type ) {
		// 1. Initiate multipart upload.
		$headers = array(
			'Content-Type' => $content_type,
			'x-amz-acl'   => 'public-read',
		);

		$response = $this->request( 'POST', $key, $headers, '', array( 'uploads' => '' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$xml  = @simplexml_load_string( $body );

		if ( false === $xml || ! isset( $xml->UploadId ) ) {
			return new WP_Error( 'starter_s3_multipart_init', __( 'Could not initiate multipart upload.', 'starter-theme' ) );
		}

		$upload_id = (string) $xml->UploadId;

		// 2. Upload parts.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle    = fopen( $file_path, 'rb' );
		$part_num  = 1;
		$etags     = array();

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $handle, $this->part_size );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$part_headers = array(
				'Content-Length' => strlen( $chunk ),
			);

			$part_query = array(
				'partNumber' => $part_num,
				'uploadId'   => $upload_id,
			);

			$part_response = $this->request( 'PUT', $key, $part_headers, $chunk, $part_query );

			if ( is_wp_error( $part_response ) ) {
				// Abort on failure.
				$this->abort_multipart_upload( $key, $upload_id );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return $part_response;
			}

			$etag = wp_remote_retrieve_header( $part_response, 'etag' );

			if ( empty( $etag ) ) {
				$this->abort_multipart_upload( $key, $upload_id );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return new WP_Error( 'starter_s3_part_etag', __( 'Missing ETag for uploaded part.', 'starter-theme' ) );
			}

			$etags[ $part_num ] = $etag;
			$part_num++;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		// 3. Complete multipart upload.
		$complete_xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $num => $etag ) {
			$complete_xml .= '<Part>';
			$complete_xml .= '<PartNumber>' . $num . '</PartNumber>';
			$complete_xml .= '<ETag>' . $etag . '</ETag>';
			$complete_xml .= '</Part>';
		}
		$complete_xml .= '</CompleteMultipartUpload>';

		$complete_headers = array(
			'Content-Type'   => 'application/xml',
			'Content-Length' => strlen( $complete_xml ),
		);

		$complete_query = array(
			'uploadId' => $upload_id,
		);

		$complete_response = $this->request( 'POST', $key, $complete_headers, $complete_xml, $complete_query );

		if ( is_wp_error( $complete_response ) ) {
			return $complete_response;
		}

		$code = wp_remote_retrieve_response_code( $complete_response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'starter_s3_multipart_complete',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Multipart upload completion failed with HTTP %d.', 'starter-theme' ),
					$code
				)
			);
		}

		return $this->get_url( $key );
	}

	/**
	 * Abort a multipart upload.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload ID.
	 * @return void
	 */
	private function abort_multipart_upload( $key, $upload_id ) {
		$this->request( 'DELETE', $key, array(), '', array( 'uploadId' => $upload_id ) );
	}

	/*--------------------------------------------------------------------------
	 * AWS Signature V4 implementation.
	 *------------------------------------------------------------------------*/

	/**
	 * Make a signed HTTP request to S3.
	 *
	 * @param string $method       HTTP method.
	 * @param string $key          Object key (relative).
	 * @param array  $headers      Extra headers.
	 * @param string $body         Request body.
	 * @param array  $query_params Query string parameters.
	 * @return array|WP_Error      wp_remote_request response.
	 */
	private function request( $method, $key, $headers = array(), $body = '', $query_params = array() ) {
		$now       = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $now );
		$datestamp = gmdate( 'Ymd', $now );

		$host     = $this->get_host();
		$uri_path = $this->get_uri_path( $key );

		// Content hash.
		$payload_hash = hash( 'sha256', $body );

		// Merge default headers.
		$headers = array_merge( $headers, array(
			'Host'                 => $host,
			'x-amz-date'          => $amz_date,
			'x-amz-content-sha256' => $payload_hash,
		) );

		// Build canonical query string.
		ksort( $query_params );
		$canonical_query = '';
		$pairs = array();
		foreach ( $query_params as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$canonical_query = implode( '&', $pairs );

		// Build canonical headers.
		$lower_headers = array();
		foreach ( $headers as $k => $v ) {
			$lower_headers[ strtolower( $k ) ] = trim( $v );
		}
		ksort( $lower_headers );

		$canonical_headers_str = '';
		foreach ( $lower_headers as $k => $v ) {
			$canonical_headers_str .= $k . ':' . $v . "\n";
		}

		$signed_headers = implode( ';', array_keys( $lower_headers ) );

		// Canonical request.
		$canonical_request = implode( "\n", array(
			$method,
			$uri_path,
			$canonical_query,
			$canonical_headers_str,
			$signed_headers,
			$payload_hash,
		) );

		// String to sign.
		$scope          = $datestamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$scope,
			hash( 'sha256', $canonical_request ),
		) );

		// Signing key.
		$signing_key = $this->get_signing_key( $datestamp );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Authorization header.
		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key,
			$scope,
			$signed_headers,
			$signature
		);

		// Build full URL.
		$scheme = 'https';
		if ( ! empty( $this->endpoint ) ) {
			$parsed_endpoint = wp_parse_url( $this->endpoint );
			if ( isset( $parsed_endpoint['scheme'] ) ) {
				$scheme = $parsed_endpoint['scheme'];
			}
		}

		$url = $scheme . '://' . $host . $uri_path;
		if ( '' !== $canonical_query ) {
			$url .= '?' . $canonical_query;
		}

		// Remove Host header — wp_remote_request sets it automatically.
		unset( $headers['Host'] );

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => 120,
			'sslverify' => true,
		);

		return wp_remote_request( $url, $args );
	}

	/**
	 * Derive the signing key for AWS Signature V4.
	 *
	 * @param string $datestamp Ymd formatted date.
	 * @return string Binary HMAC key.
	 */
	private function get_signing_key( $datestamp ) {
		$date_key    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
		$region_key  = hash_hmac( 'sha256', $this->region, $date_key, true );
		$service_key = hash_hmac( 'sha256', $this->service, $region_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $service_key, true );

		return $signing_key;
	}

	/*--------------------------------------------------------------------------
	 * URL / host helpers.
	 *------------------------------------------------------------------------*/

	/**
	 * Get the hostname for requests.
	 *
	 * @return string
	 */
	private function get_host() {
		if ( ! empty( $this->endpoint ) ) {
			$parsed = wp_parse_url( $this->endpoint );
			$host   = $parsed['host'];
			if ( ! empty( $parsed['port'] ) && 443 !== (int) $parsed['port'] && 80 !== (int) $parsed['port'] ) {
				$host .= ':' . $parsed['port'];
			}
			return $host;
		}

		// Standard S3 virtual-hosted-style.
		return $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
	}

	/**
	 * Get the URI path portion for a given key.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function get_uri_path( $key ) {
		$encoded_key = '';
		if ( '' !== $key ) {
			$segments    = explode( '/', $key );
			$encoded_key = implode( '/', array_map( 'rawurlencode', $segments ) );
		}

		if ( $this->use_path_style ) {
			return '/' . $this->bucket . '/' . $encoded_key;
		}

		return '/' . $encoded_key;
	}

	/**
	 * Build the public URL for a given key.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function build_url( $key ) {
		$encoded_key = '';
		if ( '' !== $key ) {
			$segments    = explode( '/', $key );
			$encoded_key = implode( '/', array_map( 'rawurlencode', $segments ) );
		}

		if ( ! empty( $this->endpoint ) ) {
			return trailingslashit( $this->endpoint ) . $this->bucket . '/' . $encoded_key;
		}

		return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $encoded_key;
	}

	/*--------------------------------------------------------------------------
	 * Utility.
	 *------------------------------------------------------------------------*/

	/**
	 * Validate that we have the required credentials.
	 *
	 * @return true|WP_Error
	 */
	private function validate_credentials() {
		if ( empty( $this->access_key ) || empty( $this->secret_key ) || empty( $this->bucket ) ) {
			return new WP_Error(
				'starter_s3_credentials',
				__( 'S3 credentials are missing. Please set STARTER_S3_KEY, STARTER_S3_SECRET, and STARTER_S3_BUCKET in your .env file.', 'starter-theme' )
			);
		}
		return true;
	}

	/**
	 * Sanitize an S3 object key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function sanitize_key( $key ) {
		$key = str_replace( '\\', '/', $key );
		$key = str_replace( '../', '', $key );
		$key = ltrim( $key, '/' );
		// Remove consecutive slashes.
		$key = preg_replace( '#/+#', '/', $key );
		return $key;
	}

	/**
	 * Detect the Content-Type for a file.
	 *
	 * @param string $file_path Absolute path.
	 * @return string MIME type.
	 */
	private function detect_content_type( $file_path ) {
		$mime = wp_check_filetype( basename( $file_path ) );

		if ( ! empty( $mime['type'] ) ) {
			return $mime['type'];
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$type = mime_content_type( $file_path );
			if ( $type ) {
				return $type;
			}
		}

		return 'application/octet-stream';
	}
}
