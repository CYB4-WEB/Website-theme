<?php
/**
 * FTP / SFTP storage handler.
 *
 * Supports plain FTP (with optional TLS) via PHP's built-in FTP functions
 * and SFTP via the ssh2 extension.  Connection pooling keeps a single
 * connection open for the lifetime of the request.
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
 * Class Starter_Storage_FTP
 */
class Starter_Storage_FTP implements Starter_Storage_Interface {

	/**
	 * FTP or SFTP host.
	 *
	 * @var string
	 */
	private $host = '';

	/**
	 * Username.
	 *
	 * @var string
	 */
	private $user = '';

	/**
	 * Password.
	 *
	 * @var string
	 */
	private $pass = '';

	/**
	 * Port number.
	 *
	 * @var int
	 */
	private $port = 21;

	/**
	 * Remote base path.
	 *
	 * @var string
	 */
	private $remote_path = '/';

	/**
	 * Whether to use FTP over TLS (FTPS).
	 *
	 * @var bool
	 */
	private $ssl = false;

	/**
	 * Connection type: 'ftp' or 'sftp'.
	 *
	 * @var string
	 */
	private $type = 'ftp';

	/**
	 * Public-facing base URL where uploaded files are accessible.
	 *
	 * @var string
	 */
	private $public_url = '';

	/**
	 * FTP connection resource (connection pooling).
	 *
	 * @var resource|null
	 */
	private $ftp_connection = null;

	/**
	 * SSH2 connection resource.
	 *
	 * @var resource|null
	 */
	private $ssh_connection = null;

	/**
	 * SFTP subsystem resource.
	 *
	 * @var resource|null
	 */
	private $sftp_connection = null;

	/**
	 * Whether we are currently connected.
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Constructor — loads credentials from .env.
	 */
	public function __construct() {
		$this->host        = Starter_Env_Loader::get( 'STARTER_FTP_HOST' );
		$this->user        = Starter_Env_Loader::get( 'STARTER_FTP_USER' );
		$this->pass        = Starter_Env_Loader::get( 'STARTER_FTP_PASS' );
		$this->port        = (int) Starter_Env_Loader::get( 'STARTER_FTP_PORT', $this->get_default_port() );
		$this->remote_path = untrailingslashit( Starter_Env_Loader::get( 'STARTER_FTP_PATH', '/' ) );
		$this->ssl         = filter_var( Starter_Env_Loader::get( 'STARTER_FTP_SSL', 'false' ), FILTER_VALIDATE_BOOLEAN );
		$this->type        = strtolower( Starter_Env_Loader::get( 'STARTER_FTP_TYPE', 'ftp' ) );
		$this->public_url  = untrailingslashit( Starter_Env_Loader::get( 'STARTER_FTP_PUBLIC_URL' ) );

		// Re-evaluate port if the type was set after defaults.
		if ( 0 === $this->port ) {
			$this->port = $this->get_default_port();
		}

		// Disconnect at end of request.
		add_action( 'shutdown', array( $this, 'disconnect' ) );
	}

	/*--------------------------------------------------------------------------
	 * Starter_Storage_Interface methods.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload a file via FTP/SFTP.
	 *
	 * @param string $file_path   Absolute local path.
	 * @param string $destination Relative destination path.
	 * @return string|WP_Error    Public URL on success.
	 */
	public function upload( $file_path, $destination ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'starter_ftp_file_missing',
				__( 'Source file does not exist or is not readable.', 'starter-theme' )
			);
		}

		$connection = $this->connect();
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$destination  = $this->sanitize_path( $destination );
		$remote_file  = $this->remote_path . '/' . $destination;
		$remote_dir   = dirname( $remote_file );

		// Auto-create directory structure.
		$mkdir = $this->mkdir_recursive( $remote_dir );
		if ( is_wp_error( $mkdir ) ) {
			return $mkdir;
		}

		if ( 'sftp' === $this->type ) {
			$result = $this->sftp_upload( $file_path, $remote_file );
		} else {
			$result = $this->ftp_upload( $file_path, $remote_file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_url( $destination );
	}

	/**
	 * Delete a file via FTP/SFTP.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function delete( $path ) {
		$connection = $this->connect();
		if ( is_wp_error( $connection ) ) {
			return false;
		}

		$path        = $this->sanitize_path( $path );
		$remote_file = $this->remote_path . '/' . $path;

		if ( 'sftp' === $this->type ) {
			return $this->sftp_delete( $remote_file );
		}

		return $this->ftp_delete( $remote_file );
	}

	/**
	 * Get the public URL.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public function get_url( $path ) {
		$path = $this->sanitize_path( $path );

		if ( empty( $this->public_url ) ) {
			return $path;
		}

		return trailingslashit( $this->public_url ) . $path;
	}

	/**
	 * Check if a file exists on the remote server.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function exists( $path ) {
		$connection = $this->connect();
		if ( is_wp_error( $connection ) ) {
			return false;
		}

		$path        = $this->sanitize_path( $path );
		$remote_file = $this->remote_path . '/' . $path;

		if ( 'sftp' === $this->type ) {
			return $this->sftp_exists( $remote_file );
		}

		return $this->ftp_exists( $remote_file );
	}

	/*--------------------------------------------------------------------------
	 * Connection management (pooling).
	 *------------------------------------------------------------------------*/

	/**
	 * Establish a connection if not already connected.
	 *
	 * @return true|WP_Error
	 */
	private function connect() {
		if ( $this->connected ) {
			return true;
		}

		$validation = $this->validate_credentials();
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( 'sftp' === $this->type ) {
			return $this->connect_sftp();
		}

		return $this->connect_ftp();
	}

	/**
	 * Connect via FTP (or FTPS).
	 *
	 * @return true|WP_Error
	 */
	private function connect_ftp() {
		if ( ! function_exists( 'ftp_connect' ) ) {
			return new WP_Error(
				'starter_ftp_unavailable',
				__( 'PHP FTP extension is not available.', 'starter-theme' )
			);
		}

		if ( $this->ssl && function_exists( 'ftp_ssl_connect' ) ) {
			$this->ftp_connection = @ftp_ssl_connect( $this->host, $this->port, 30 );
		} else {
			$this->ftp_connection = @ftp_connect( $this->host, $this->port, 30 );
		}

		if ( ! $this->ftp_connection ) {
			return new WP_Error(
				'starter_ftp_connect_fail',
				sprintf(
					/* translators: %s: host */
					__( 'Could not connect to FTP server %s.', 'starter-theme' ),
					$this->host
				)
			);
		}

		$login = @ftp_login( $this->ftp_connection, $this->user, $this->pass );

		if ( ! $login ) {
			@ftp_close( $this->ftp_connection );
			$this->ftp_connection = null;
			return new WP_Error(
				'starter_ftp_login_fail',
				__( 'FTP login failed. Check STARTER_FTP_USER and STARTER_FTP_PASS.', 'starter-theme' )
			);
		}

		// Enable passive mode — works better behind NAT/firewalls.
		ftp_pasv( $this->ftp_connection, true );

		$this->connected = true;
		return true;
	}

	/**
	 * Connect via SFTP using the ssh2 extension.
	 *
	 * @return true|WP_Error
	 */
	private function connect_sftp() {
		if ( ! function_exists( 'ssh2_connect' ) ) {
			return new WP_Error(
				'starter_sftp_unavailable',
				__( 'PHP ssh2 extension is not available. Install php-ssh2 to use SFTP.', 'starter-theme' )
			);
		}

		$this->ssh_connection = @ssh2_connect( $this->host, $this->port );

		if ( ! $this->ssh_connection ) {
			return new WP_Error(
				'starter_sftp_connect_fail',
				sprintf(
					/* translators: %s: host */
					__( 'Could not connect to SFTP server %s.', 'starter-theme' ),
					$this->host
				)
			);
		}

		$auth = @ssh2_auth_password( $this->ssh_connection, $this->user, $this->pass );

		if ( ! $auth ) {
			$this->ssh_connection = null;
			return new WP_Error(
				'starter_sftp_auth_fail',
				__( 'SFTP authentication failed. Check STARTER_FTP_USER and STARTER_FTP_PASS.', 'starter-theme' )
			);
		}

		$this->sftp_connection = @ssh2_sftp( $this->ssh_connection );

		if ( ! $this->sftp_connection ) {
			$this->ssh_connection = null;
			return new WP_Error(
				'starter_sftp_subsystem_fail',
				__( 'Could not initialise SFTP subsystem.', 'starter-theme' )
			);
		}

		$this->connected = true;
		return true;
	}

	/**
	 * Close the active connection.
	 *
	 * Hooked to the WordPress shutdown action for automatic cleanup.
	 *
	 * @return void
	 */
	public function disconnect() {
		if ( 'sftp' === $this->type ) {
			$this->ssh_connection  = null;
			$this->sftp_connection = null;
		} elseif ( $this->ftp_connection ) {
			@ftp_close( $this->ftp_connection );
			$this->ftp_connection = null;
		}

		$this->connected = false;
	}

	/*--------------------------------------------------------------------------
	 * FTP operations.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload a file via FTP.
	 *
	 * @param string $local_path  Local absolute path.
	 * @param string $remote_path Remote absolute path.
	 * @return true|WP_Error
	 */
	private function ftp_upload( $local_path, $remote_path ) {
		$result = @ftp_put( $this->ftp_connection, $remote_path, $local_path, FTP_BINARY );

		if ( ! $result ) {
			return new WP_Error(
				'starter_ftp_upload_fail',
				sprintf(
					/* translators: %s: remote path */
					__( 'FTP upload failed for %s.', 'starter-theme' ),
					$remote_path
				)
			);
		}

		return true;
	}

	/**
	 * Delete a file via FTP.
	 *
	 * @param string $remote_path Remote absolute path.
	 * @return bool
	 */
	private function ftp_delete( $remote_path ) {
		return @ftp_delete( $this->ftp_connection, $remote_path );
	}

	/**
	 * Check file existence via FTP.
	 *
	 * @param string $remote_path Remote absolute path.
	 * @return bool
	 */
	private function ftp_exists( $remote_path ) {
		$size = @ftp_size( $this->ftp_connection, $remote_path );
		return ( -1 !== $size );
	}

	/*--------------------------------------------------------------------------
	 * SFTP operations.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload a file via SFTP.
	 *
	 * @param string $local_path  Local absolute path.
	 * @param string $remote_path Remote absolute path.
	 * @return true|WP_Error
	 */
	private function sftp_upload( $local_path, $remote_path ) {
		$sftp_id = intval( $this->sftp_connection );
		$stream  = @fopen( "ssh2.sftp://{$sftp_id}{$remote_path}", 'w' );

		if ( ! $stream ) {
			return new WP_Error(
				'starter_sftp_upload_fail',
				sprintf(
					/* translators: %s: remote path */
					__( 'SFTP upload failed for %s.', 'starter-theme' ),
					$remote_path
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data    = file_get_contents( $local_path );
		$written = @fwrite( $stream, $data );
		@fclose( $stream );

		if ( false === $written ) {
			return new WP_Error( 'starter_sftp_write_fail', __( 'SFTP write failed.', 'starter-theme' ) );
		}

		return true;
	}

	/**
	 * Delete a file via SFTP.
	 *
	 * @param string $remote_path Remote absolute path.
	 * @return bool
	 */
	private function sftp_delete( $remote_path ) {
		return @ssh2_sftp_unlink( $this->sftp_connection, $remote_path );
	}

	/**
	 * Check file existence via SFTP.
	 *
	 * @param string $remote_path Remote absolute path.
	 * @return bool
	 */
	private function sftp_exists( $remote_path ) {
		$sftp_id = intval( $this->sftp_connection );
		$stat    = @stat( "ssh2.sftp://{$sftp_id}{$remote_path}" );
		return ( false !== $stat );
	}

	/*--------------------------------------------------------------------------
	 * Directory helpers.
	 *------------------------------------------------------------------------*/

	/**
	 * Recursively create a directory on the remote server.
	 *
	 * @param string $dir Remote absolute directory path.
	 * @return true|WP_Error
	 */
	private function mkdir_recursive( $dir ) {
		if ( 'sftp' === $this->type ) {
			return $this->sftp_mkdir_recursive( $dir );
		}

		return $this->ftp_mkdir_recursive( $dir );
	}

	/**
	 * Recursive mkdir via FTP.
	 *
	 * @param string $dir Remote absolute path.
	 * @return true|WP_Error
	 */
	private function ftp_mkdir_recursive( $dir ) {
		// Attempt to change to the directory to see if it exists.
		if ( @ftp_chdir( $this->ftp_connection, $dir ) ) {
			// Reset to root.
			@ftp_chdir( $this->ftp_connection, '/' );
			return true;
		}

		$parts   = explode( '/', trim( $dir, '/' ) );
		$current = '';

		foreach ( $parts as $part ) {
			$current .= '/' . $part;

			if ( ! @ftp_chdir( $this->ftp_connection, $current ) ) {
				$made = @ftp_mkdir( $this->ftp_connection, $current );

				if ( ! $made ) {
					return new WP_Error(
						'starter_ftp_mkdir_fail',
						sprintf(
							/* translators: %s: directory path */
							__( 'Could not create remote directory %s.', 'starter-theme' ),
							$current
						)
					);
				}
			}
		}

		// Reset to root.
		@ftp_chdir( $this->ftp_connection, '/' );
		return true;
	}

	/**
	 * Recursive mkdir via SFTP.
	 *
	 * @param string $dir Remote absolute path.
	 * @return true|WP_Error
	 */
	private function sftp_mkdir_recursive( $dir ) {
		$sftp_id = intval( $this->sftp_connection );
		$stat    = @stat( "ssh2.sftp://{$sftp_id}{$dir}" );

		if ( false !== $stat ) {
			return true;
		}

		$parts   = explode( '/', trim( $dir, '/' ) );
		$current = '';

		foreach ( $parts as $part ) {
			$current .= '/' . $part;
			$stat     = @stat( "ssh2.sftp://{$sftp_id}{$current}" );

			if ( false === $stat ) {
				if ( ! @ssh2_sftp_mkdir( $this->sftp_connection, $current, 0755 ) ) {
					return new WP_Error(
						'starter_sftp_mkdir_fail',
						sprintf(
							/* translators: %s: directory path */
							__( 'Could not create remote directory %s.', 'starter-theme' ),
							$current
						)
					);
				}
			}
		}

		return true;
	}

	/*--------------------------------------------------------------------------
	 * Utility.
	 *------------------------------------------------------------------------*/

	/**
	 * Validate that required credentials exist.
	 *
	 * @return true|WP_Error
	 */
	private function validate_credentials() {
		if ( empty( $this->host ) || empty( $this->user ) ) {
			return new WP_Error(
				'starter_ftp_credentials',
				__( 'FTP credentials are missing. Please set STARTER_FTP_HOST and STARTER_FTP_USER in your .env file.', 'starter-theme' )
			);
		}
		return true;
	}

	/**
	 * Get the default port for the current connection type.
	 *
	 * @return int
	 */
	private function get_default_port() {
		return ( 'sftp' === $this->type ) ? 22 : 21;
	}

	/**
	 * Sanitize a relative path.
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
		return $path;
	}
}
