<?php
/**
 * Encryption helper (AES-256-GCM).
 *
 * Provides encrypt / decrypt methods for sensitive data and URL tokens.
 * The encryption key is read from the .env file (ENCRYPTION_KEY).
 * If no key exists, one is auto-generated and persisted.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Encryption
 *
 * Singleton providing AES-256-GCM encryption and time-based tokens.
 */
class Starter_Encryption {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Encryption|null
	 */
	private static $instance = null;

	/**
	 * Cipher algorithm.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * Default token TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 3600;

	/**
	 * The encryption key (raw bytes).
	 *
	 * @var string
	 */
	private $key = '';

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Encryption
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — resolves the encryption key.
	 */
	private function __construct() {
		$this->resolve_key();
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
	 * Initialize (placeholder for consistency with other core classes).
	 *
	 * @return void
	 */
	public function init() {
		// The key is resolved in the constructor.
		// This method exists so the bootstrap file can call init() uniformly.
	}

	// ---------------------------------------------------------------
	// Key Management
	// ---------------------------------------------------------------

	/**
	 * Resolve the encryption key from the environment.
	 *
	 * Reads ENCRYPTION_KEY from the .env loader. If it does not exist,
	 * a new key is generated and stored automatically.
	 *
	 * @return void
	 */
	private function resolve_key() {
		$key_b64 = '';

		// Try the .env loader first.
		if ( class_exists( 'Starter_Env_Loader' ) ) {
			$key_b64 = Starter_Env_Loader::get( 'ENCRYPTION_KEY', '' );
		}

		// Fallback: PHP constant defined elsewhere.
		if ( empty( $key_b64 ) && defined( 'STARTER_ENCRYPTION_KEY' ) ) {
			$key_b64 = STARTER_ENCRYPTION_KEY;
		}

		if ( ! empty( $key_b64 ) ) {
			$decoded = base64_decode( $key_b64, true );
			if ( false !== $decoded && 32 === strlen( $decoded ) ) {
				$this->key = $decoded;
				return;
			}
		}

		// No valid key found — generate one.
		$this->key = $this->generate_key();
	}

	/**
	 * Generate a new 256-bit key, persist it to .env, and return raw bytes.
	 *
	 * @return string 32-byte raw key.
	 */
	public function generate_key() {
		$raw_key = random_bytes( 32 );
		$b64_key = base64_encode( $raw_key );

		// Persist via the env loader.
		if ( class_exists( 'Starter_Env_Loader' ) ) {
			$env = Starter_Env_Loader::get_instance();
			$env->set( 'ENCRYPTION_KEY', $b64_key );
		}

		return $raw_key;
	}

	// ---------------------------------------------------------------
	// Encrypt / Decrypt
	// ---------------------------------------------------------------

	/**
	 * Encrypt arbitrary data.
	 *
	 * Returns a URL-safe base64 string containing: nonce || tag || ciphertext.
	 *
	 * @param string $data Plain-text data.
	 * @return string|false Encrypted payload or false on failure.
	 */
	public function encrypt( $data ) {
		if ( ! $this->is_supported() ) {
			return false;
		}

		$nonce = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$tag   = '';

		$ciphertext = openssl_encrypt(
			$data,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',
			16
		);

		if ( false === $ciphertext ) {
			return false;
		}

		// Pack: nonce (12) + tag (16) + ciphertext.
		$payload = $nonce . $tag . $ciphertext;

		return $this->base64_url_encode( $payload );
	}

	/**
	 * Decrypt data previously encrypted with encrypt().
	 *
	 * @param string $encrypted URL-safe base64 encoded payload.
	 * @return string|false Decrypted plain text or false on failure.
	 */
	public function decrypt( $encrypted ) {
		if ( ! $this->is_supported() ) {
			return false;
		}

		$payload = $this->base64_url_decode( $encrypted );
		if ( false === $payload ) {
			return false;
		}

		$nonce_len = openssl_cipher_iv_length( self::CIPHER ); // 12 bytes.
		$tag_len   = 16;

		if ( strlen( $payload ) < $nonce_len + $tag_len ) {
			return false;
		}

		$nonce      = substr( $payload, 0, $nonce_len );
		$tag        = substr( $payload, $nonce_len, $tag_len );
		$ciphertext = substr( $payload, $nonce_len + $tag_len );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		return ( false !== $plaintext ) ? $plaintext : false;
	}

	// ---------------------------------------------------------------
	// URL Encryption
	// ---------------------------------------------------------------

	/**
	 * Encrypt a URL for obfuscated redirects or protected links.
	 *
	 * @param string $url The URL to encrypt.
	 * @return string|false Encrypted string.
	 */
	public function encrypt_url( $url ) {
		$url = esc_url_raw( $url );
		return $this->encrypt( $url );
	}

	/**
	 * Decrypt an encrypted URL.
	 *
	 * @param string $encrypted Encrypted payload.
	 * @return string|false Decrypted URL or false.
	 */
	public function decrypt_url( $encrypted ) {
		$url = $this->decrypt( $encrypted );

		if ( false === $url ) {
			return false;
		}

		return esc_url_raw( $url );
	}

	// ---------------------------------------------------------------
	// Time-Based Tokens
	// ---------------------------------------------------------------

	/**
	 * Generate a time-based encrypted token.
	 *
	 * The token embeds an expiration timestamp so it can be validated later.
	 *
	 * @param string $data Arbitrary data to embed.
	 * @param int    $ttl  Time to live in seconds. Default 3600.
	 * @return string|false Encrypted token.
	 */
	public function generate_token( $data, $ttl = self::DEFAULT_TTL ) {
		$ttl     = absint( $ttl ) > 0 ? absint( $ttl ) : self::DEFAULT_TTL;
		$expires = time() + $ttl;

		$payload = wp_json_encode( array(
			'data'    => $data,
			'expires' => $expires,
		) );

		if ( false === $payload ) {
			return false;
		}

		return $this->encrypt( $payload );
	}

	/**
	 * Validate and decode a time-based token.
	 *
	 * @param string $token The encrypted token.
	 * @return string|false The embedded data, or false if expired / invalid.
	 */
	public function validate_token( $token ) {
		$json = $this->decrypt( $token );

		if ( false === $json ) {
			return false;
		}

		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) || empty( $payload['expires'] ) || empty( $payload['data'] ) ) {
			return false;
		}

		if ( time() > (int) $payload['expires'] ) {
			return false;
		}

		return $payload['data'];
	}

	// ---------------------------------------------------------------
	// URL-Safe Base64
	// ---------------------------------------------------------------

	/**
	 * Encode data using URL-safe base64.
	 *
	 * @param string $data Raw binary data.
	 * @return string
	 */
	private function base64_url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode URL-safe base64 data.
	 *
	 * @param string $data URL-safe base64 string.
	 * @return string|false
	 */
	private function base64_url_decode( $data ) {
		$padded = str_pad( strtr( $data, '-_', '+/' ), strlen( $data ) % 4, '=', STR_PAD_RIGHT );
		return base64_decode( $padded, true );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Check whether the required OpenSSL cipher is available.
	 *
	 * @return bool
	 */
	private function is_supported() {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}
}
