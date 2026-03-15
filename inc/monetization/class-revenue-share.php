<?php
/**
 * Revenue sharing system for multi-author monetization.
 *
 * When a reader unlocks a chapter with coins, the revenue is split
 * between the chapter author and the site. Authors can view earnings
 * and request withdrawals which admins review and process.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Revenue_Share
 */
class Starter_Revenue_Share {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Revenue_Share|null
	 */
	private static $instance = null;

	/**
	 * Withdrawals table name (without prefix).
	 *
	 * @var string
	 */
	const WITHDRAWALS_TABLE = 'starter_withdrawals';

	/**
	 * Author earnings meta key.
	 *
	 * @var string
	 */
	const EARNINGS_META_KEY = '_starter_author_earnings';

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Revenue_Share
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
	private function __construct() {}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Database tables.
		register_activation_hook( __FILE__, array( $this, 'create_tables' ) );
		add_action( 'after_switch_theme', array( $this, 'create_tables' ) );

		// Listen for chapter unlock events from Starter_Coin_System.
		add_action( 'starter_chapter_unlocked', array( $this, 'process_revenue_split' ), 10, 3 );

		// Shortcodes.
		add_shortcode( 'starter_author_revenue', array( $this, 'shortcode_author_revenue' ) );
		add_shortcode( 'starter_author_withdrawals', array( $this, 'shortcode_author_withdrawals' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_starter_request_withdrawal', array( $this, 'ajax_request_withdrawal' ) );
		add_action( 'wp_ajax_starter_get_author_earnings', array( $this, 'ajax_get_author_earnings' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_starter_process_withdrawal', array( $this, 'admin_process_withdrawal' ) );

		// Enqueue scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/* ------------------------------------------------------------------
	 * Database
	 * ----------------------------------------------------------------*/

	/**
	 * Create the withdrawals table using dbDelta.
	 *
	 * @return void
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			method varchar(50) NOT NULL DEFAULT 'paypal',
			method_details_encrypted text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			admin_note text NOT NULL DEFAULT '',
			request_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			process_date datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY request_date (request_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------
	 * Revenue split configuration
	 * ----------------------------------------------------------------*/

	/**
	 * Get the author share percentage (0-100).
	 *
	 * @return int
	 */
	public function get_author_share_percent() {
		return absint( get_option( 'starter_author_share_percent', 70 ) );
	}

	/**
	 * Get the site share percentage (remainder).
	 *
	 * @return int
	 */
	public function get_site_share_percent() {
		return 100 - $this->get_author_share_percent();
	}

	/**
	 * Get minimum withdrawal amount.
	 *
	 * @return float
	 */
	public function get_minimum_withdrawal() {
		return floatval( get_option( 'starter_minimum_withdrawal', 50.00 ) );
	}

	/**
	 * Get configured coin-to-currency conversion rate.
	 *
	 * @return float How much 1 coin is worth in real currency.
	 */
	public function get_coin_value() {
		return floatval( get_option( 'starter_coin_value', 0.05 ) );
	}

	/* ------------------------------------------------------------------
	 * Revenue split processing
	 * ----------------------------------------------------------------*/

	/**
	 * Process revenue split when a chapter is unlocked.
	 *
	 * Hooked to 'starter_chapter_unlocked'.
	 *
	 * @param int $user_id    The reader who unlocked.
	 * @param int $chapter_id The chapter post ID.
	 * @param int $cost       Coins spent.
	 * @return void
	 */
	public function process_revenue_split( $user_id, $chapter_id, $cost ) {
		$chapter = get_post( $chapter_id );
		if ( ! $chapter ) {
			return;
		}

		$author_id     = $chapter->post_author;
		$author_percent = $this->get_author_share_percent();
		$coin_value    = $this->get_coin_value();

		// Calculate shares in currency.
		$total_value   = $cost * $coin_value;
		$author_share  = round( $total_value * ( $author_percent / 100 ), 2 );
		$site_share    = round( $total_value - $author_share, 2 );

		// Credit author's earnings balance.
		$current_earnings = $this->get_author_balance( $author_id );
		$new_balance      = $current_earnings + $author_share;
		update_user_meta( $author_id, self::EARNINGS_META_KEY, $new_balance );

		// Record in author earnings log.
		$this->log_author_earning( $author_id, $author_share, $chapter_id, $user_id, $cost );

		// Track site share.
		$site_total = floatval( get_option( 'starter_site_earnings_total', 0 ) );
		update_option( 'starter_site_earnings_total', $site_total + $site_share );

		/**
		 * Fires after revenue split is processed.
		 *
		 * @param int   $author_id    Chapter author.
		 * @param float $author_share Author's monetary share.
		 * @param float $site_share   Site's monetary share.
		 * @param int   $chapter_id   Chapter unlocked.
		 * @param int   $cost         Coins spent by reader.
		 */
		do_action( 'starter_revenue_split_processed', $author_id, $author_share, $site_share, $chapter_id, $cost );
	}

	/**
	 * Get author's current earnings balance.
	 *
	 * @param int $user_id Author user ID.
	 * @return float
	 */
	public function get_author_balance( $user_id ) {
		$balance = get_user_meta( absint( $user_id ), self::EARNINGS_META_KEY, true );
		return $balance ? floatval( $balance ) : 0.00;
	}

	/**
	 * Log an author earning entry.
	 *
	 * Uses user meta to store a serialized earnings history.
	 *
	 * @param int   $author_id    Author user ID.
	 * @param float $amount       Amount earned.
	 * @param int   $chapter_id   Chapter post ID.
	 * @param int   $reader_id    Reader user ID.
	 * @param int   $coins        Coins spent.
	 * @return void
	 */
	private function log_author_earning( $author_id, $amount, $chapter_id, $reader_id, $coins ) {
		$log = get_user_meta( $author_id, '_starter_earnings_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'amount'     => $amount,
			'chapter_id' => $chapter_id,
			'reader_id'  => $reader_id,
			'coins'      => $coins,
			'date'       => current_time( 'mysql' ),
		);

		// Keep last 1000 entries to prevent unbounded growth.
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}

		update_user_meta( $author_id, '_starter_earnings_log', $log );
	}

	/**
	 * Get per-manga earnings breakdown for an author.
	 *
	 * @param int $author_id Author user ID.
	 * @return array Keyed by manga/parent post ID.
	 */
	public function get_per_manga_earnings( $author_id ) {
		$log = get_user_meta( absint( $author_id ), '_starter_earnings_log', true );
		if ( ! is_array( $log ) ) {
			return array();
		}

		$manga_earnings = array();
		foreach ( $log as $entry ) {
			$parent_id = wp_get_post_parent_id( $entry['chapter_id'] );
			if ( ! $parent_id ) {
				$parent_id = $entry['chapter_id'];
			}

			if ( ! isset( $manga_earnings[ $parent_id ] ) ) {
				$manga_earnings[ $parent_id ] = array(
					'title'    => get_the_title( $parent_id ),
					'total'    => 0.00,
					'unlocks'  => 0,
				);
			}

			$manga_earnings[ $parent_id ]['total']   += floatval( $entry['amount'] );
			$manga_earnings[ $parent_id ]['unlocks'] += 1;
		}

		return $manga_earnings;
	}

	/* ------------------------------------------------------------------
	 * Withdrawal system
	 * ----------------------------------------------------------------*/

	/**
	 * Create a withdrawal request.
	 *
	 * @param int    $user_id        Author user ID.
	 * @param float  $amount         Amount to withdraw.
	 * @param string $method         Withdrawal method: paypal|bank_transfer|crypto.
	 * @param string $method_details Payment details (e.g. PayPal email, bank info).
	 * @return int|WP_Error Withdrawal ID or error.
	 */
	public function request_withdrawal( $user_id, $amount, $method, $method_details ) {
		$user_id = absint( $user_id );
		$amount  = floatval( $amount );
		$method  = sanitize_key( $method );

		$allowed_methods = array( 'paypal', 'bank_transfer', 'crypto' );
		if ( ! in_array( $method, $allowed_methods, true ) ) {
			return new WP_Error( 'invalid_method', __( 'Invalid withdrawal method.', 'starter-theme' ) );
		}

		$balance = $this->get_author_balance( $user_id );
		$minimum = $this->get_minimum_withdrawal();

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid withdrawal amount.', 'starter-theme' ) );
		}

		if ( $amount > $balance ) {
			return new WP_Error( 'insufficient_balance', __( 'Withdrawal amount exceeds your balance.', 'starter-theme' ) );
		}

		if ( $balance < $minimum ) {
			return new WP_Error(
				'below_minimum',
				sprintf(
					/* translators: %s: minimum withdrawal amount */
					__( 'Minimum withdrawal amount is %s.', 'starter-theme' ),
					number_format( $minimum, 2 )
				)
			);
		}

		// Check for pending withdrawals.
		$has_pending = $this->has_pending_withdrawal( $user_id );
		if ( $has_pending ) {
			return new WP_Error( 'pending_exists', __( 'You already have a pending withdrawal request.', 'starter-theme' ) );
		}

		// Encrypt payment details.
		$encrypted_details = $this->encrypt_payment_details( $method_details );
		if ( is_wp_error( $encrypted_details ) ) {
			return $encrypted_details;
		}

		// Deduct from author balance immediately (hold).
		$new_balance = $balance - $amount;
		update_user_meta( $user_id, self::EARNINGS_META_KEY, $new_balance );

		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$wpdb->insert(
			$table,
			array(
				'user_id'                  => $user_id,
				'amount'                   => $amount,
				'method'                   => $method,
				'method_details_encrypted' => $encrypted_details,
				'status'                   => 'pending',
				'admin_note'               => '',
				'request_date'             => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		$withdrawal_id = $wpdb->insert_id;

		/**
		 * Fires when a withdrawal is requested.
		 *
		 * @param int   $withdrawal_id Withdrawal record ID.
		 * @param int   $user_id       Author user ID.
		 * @param float $amount        Amount requested.
		 */
		do_action( 'starter_withdrawal_requested', $withdrawal_id, $user_id, $amount );

		return $withdrawal_id;
	}

	/**
	 * Check if a user has a pending withdrawal.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function has_pending_withdrawal( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'pending'",
				absint( $user_id )
			)
		);

		return $count > 0;
	}

	/**
	 * Get withdrawals for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max results.
	 * @return array
	 */
	public function get_user_withdrawals( $user_id, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, method, status, admin_note, request_date, process_date
				 FROM {$table}
				 WHERE user_id = %d
				 ORDER BY request_date DESC
				 LIMIT %d",
				absint( $user_id ),
				absint( $limit )
			)
		);
	}

	/**
	 * Get a single withdrawal record (admin).
	 *
	 * @param int $withdrawal_id Withdrawal ID.
	 * @return object|null
	 */
	public function get_withdrawal( $withdrawal_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $withdrawal_id ) )
		);
	}

	/**
	 * Process a withdrawal (admin action).
	 *
	 * @param int    $withdrawal_id Withdrawal ID.
	 * @param string $new_status    New status: approved|completed|rejected.
	 * @param string $admin_note    Optional admin note.
	 * @return bool
	 */
	public function update_withdrawal_status( $withdrawal_id, $new_status, $admin_note = '' ) {
		$allowed = array( 'approved', 'completed', 'rejected' );
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$withdrawal = $this->get_withdrawal( $withdrawal_id );
		if ( ! $withdrawal ) {
			return false;
		}

		$update_data = array(
			'status'       => sanitize_key( $new_status ),
			'admin_note'   => sanitize_textarea_field( $admin_note ),
			'process_date' => current_time( 'mysql' ),
		);

		$updated = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => absint( $withdrawal_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// If rejected, refund the held amount back to the author.
		if ( 'rejected' === $new_status ) {
			$current_balance = $this->get_author_balance( $withdrawal->user_id );
			update_user_meta( $withdrawal->user_id, self::EARNINGS_META_KEY, $current_balance + floatval( $withdrawal->amount ) );
		}

		/**
		 * Fires when a withdrawal status changes.
		 *
		 * @param int    $withdrawal_id Withdrawal ID.
		 * @param string $new_status    New status.
		 * @param object $withdrawal    Original withdrawal data.
		 */
		do_action( 'starter_withdrawal_status_changed', $withdrawal_id, $new_status, $withdrawal );

		return (bool) $updated;
	}

	/* ------------------------------------------------------------------
	 * Encryption helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Encrypt payment details using Starter_Encryption if available.
	 *
	 * @param string $plain_text Payment details to encrypt.
	 * @return string|WP_Error Encrypted string or error.
	 */
	private function encrypt_payment_details( $plain_text ) {
		$plain_text = sanitize_textarea_field( $plain_text );

		if ( class_exists( 'Starter_Encryption' ) ) {
			$encryption = new Starter_Encryption();
			$encrypted  = $encryption->encrypt( $plain_text );
			if ( false === $encrypted ) {
				return new WP_Error( 'encryption_failed', __( 'Failed to encrypt payment details.', 'starter-theme' ) );
			}
			return $encrypted;
		}

		// Fallback: use WordPress salts for basic encryption.
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return new WP_Error( 'encryption_unavailable', __( 'Encryption is not available.', 'starter-theme' ) );
		}

		$key    = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		$iv     = substr( hash( 'sha256', NONCE_KEY, true ), 0, 16 );
		$cipher = openssl_encrypt( $plain_text, 'AES-256-CBC', $key, 0, $iv );

		if ( false === $cipher ) {
			return new WP_Error( 'encryption_failed', __( 'Failed to encrypt payment details.', 'starter-theme' ) );
		}

		return base64_encode( $cipher );
	}

	/**
	 * Decrypt payment details.
	 *
	 * @param string $encrypted Encrypted string.
	 * @return string|WP_Error Decrypted text or error.
	 */
	public function decrypt_payment_details( $encrypted ) {
		if ( class_exists( 'Starter_Encryption' ) ) {
			$encryption = new Starter_Encryption();
			$decrypted  = $encryption->decrypt( $encrypted );
			if ( false === $decrypted ) {
				return new WP_Error( 'decryption_failed', __( 'Failed to decrypt payment details.', 'starter-theme' ) );
			}
			return $decrypted;
		}

		// Fallback decryption.
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return new WP_Error( 'decryption_unavailable', __( 'Decryption is not available.', 'starter-theme' ) );
		}

		$key       = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		$iv        = substr( hash( 'sha256', NONCE_KEY, true ), 0, 16 );
		$decoded   = base64_decode( $encrypted, true );
		$decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $key, 0, $iv );

		if ( false === $decrypted ) {
			return new WP_Error( 'decryption_failed', __( 'Failed to decrypt payment details.', 'starter-theme' ) );
		}

		return $decrypted;
	}

	/* ------------------------------------------------------------------
	 * AJAX endpoints
	 * ----------------------------------------------------------------*/

	/**
	 * AJAX: Request a withdrawal.
	 *
	 * @return void
	 */
	public function ajax_request_withdrawal() {
		check_ajax_referer( 'starter_revenue_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id        = get_current_user_id();
		$amount         = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
		$method         = isset( $_POST['method'] ) ? sanitize_key( $_POST['method'] ) : '';
		$method_details = isset( $_POST['method_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['method_details'] ) ) : '';

		if ( ! $method_details ) {
			wp_send_json_error( array( 'message' => __( 'Payment details are required.', 'starter-theme' ) ) );
		}

		$result = $this->request_withdrawal( $user_id, $amount, $method, $method_details );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'       => __( 'Withdrawal request submitted successfully.', 'starter-theme' ),
			'withdrawal_id' => $result,
			'new_balance'   => $this->get_author_balance( $user_id ),
		) );
	}

	/**
	 * AJAX: Get author earnings data.
	 *
	 * @return void
	 */
	public function ajax_get_author_earnings() {
		check_ajax_referer( 'starter_revenue_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();

		wp_send_json_success( array(
			'balance'          => $this->get_author_balance( $user_id ),
			'per_manga'        => $this->get_per_manga_earnings( $user_id ),
			'minimum_withdraw' => $this->get_minimum_withdrawal(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Shortcodes
	 * ----------------------------------------------------------------*/

	/**
	 * Shortcode: [starter_author_revenue]
	 *
	 * Displays author's earnings dashboard.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_author_revenue( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your earnings.', 'starter-theme' ) . '</p>';
		}

		$user_id = get_current_user_id();
		$balance = $this->get_author_balance( $user_id );
		$manga   = $this->get_per_manga_earnings( $user_id );
		$log     = get_user_meta( $user_id, '_starter_earnings_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$currency = get_option( 'starter_coin_currency', 'USD' );

		ob_start();
		?>
		<div class="starter-author-revenue">
			<div class="starter-author-revenue__balance">
				<h3><?php esc_html_e( 'Your Earnings Balance', 'starter-theme' ); ?></h3>
				<p class="starter-author-revenue__amount">
					<?php echo esc_html( number_format( $balance, 2 ) . ' ' . $currency ); ?>
				</p>
			</div>

			<?php if ( ! empty( $manga ) ) : ?>
			<div class="starter-author-revenue__breakdown">
				<h4><?php esc_html_e( 'Per-Manga Breakdown', 'starter-theme' ); ?></h4>
				<table class="starter-author-revenue__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Earnings', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Unlocks', 'starter-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $manga as $m ) : ?>
						<tr>
							<td><?php echo esc_html( $m['title'] ); ?></td>
							<td><?php echo esc_html( number_format( $m['total'], 2 ) . ' ' . $currency ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $m['unlocks'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $log ) ) : ?>
			<div class="starter-author-revenue__history">
				<h4><?php esc_html_e( 'Recent Transaction History', 'starter-theme' ); ?></h4>
				<table class="starter-author-revenue__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Chapter', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Coins', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Earned', 'starter-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$recent = array_slice( array_reverse( $log ), 0, 50 );
						foreach ( $recent as $entry ) :
						?>
						<tr>
							<td><?php echo esc_html( $entry['date'] ); ?></td>
							<td><?php echo esc_html( get_the_title( $entry['chapter_id'] ) ); ?></td>
							<td><?php echo esc_html( $entry['coins'] ); ?></td>
							<td><?php echo esc_html( number_format( $entry['amount'], 2 ) . ' ' . $currency ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="starter-author-revenue__withdraw">
				<h4><?php esc_html_e( 'Request Withdrawal', 'starter-theme' ); ?></h4>
				<p class="description">
					<?php
					printf(
						/* translators: %s: minimum withdrawal amount */
						esc_html__( 'Minimum withdrawal: %s', 'starter-theme' ),
						esc_html( number_format( $this->get_minimum_withdrawal(), 2 ) . ' ' . $currency )
					);
					?>
				</p>
				<form class="starter-withdrawal-form" data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_revenue_nonce' ) ); ?>">
					<p>
						<label for="starter-withdraw-amount"><?php esc_html_e( 'Amount:', 'starter-theme' ); ?></label>
						<input type="number" id="starter-withdraw-amount" name="amount" min="0" step="0.01"
							max="<?php echo esc_attr( $balance ); ?>" required />
					</p>
					<p>
						<label for="starter-withdraw-method"><?php esc_html_e( 'Method:', 'starter-theme' ); ?></label>
						<select id="starter-withdraw-method" name="method" required>
							<option value="paypal"><?php esc_html_e( 'PayPal', 'starter-theme' ); ?></option>
							<option value="bank_transfer"><?php esc_html_e( 'Bank Transfer', 'starter-theme' ); ?></option>
							<option value="crypto"><?php esc_html_e( 'Cryptocurrency', 'starter-theme' ); ?></option>
						</select>
					</p>
					<p>
						<label for="starter-withdraw-details"><?php esc_html_e( 'Payment Details:', 'starter-theme' ); ?></label>
						<textarea id="starter-withdraw-details" name="method_details" rows="3"
							placeholder="<?php esc_attr_e( 'PayPal email, bank info, or crypto address', 'starter-theme' ); ?>" required></textarea>
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Submit Withdrawal Request', 'starter-theme' ); ?></button>
					<div class="starter-withdrawal-form__message"></div>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode: [starter_author_withdrawals]
	 *
	 * Displays author's withdrawal history.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_author_withdrawals( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your withdrawals.', 'starter-theme' ) . '</p>';
		}

		$user_id     = get_current_user_id();
		$withdrawals = $this->get_user_withdrawals( $user_id );
		$currency    = get_option( 'starter_coin_currency', 'USD' );

		if ( empty( $withdrawals ) ) {
			return '<p>' . esc_html__( 'No withdrawal requests found.', 'starter-theme' ) . '</p>';
		}

		$status_labels = array(
			'pending'   => __( 'Pending', 'starter-theme' ),
			'approved'  => __( 'Approved', 'starter-theme' ),
			'completed' => __( 'Completed', 'starter-theme' ),
			'rejected'  => __( 'Rejected', 'starter-theme' ),
		);

		ob_start();
		?>
		<div class="starter-author-withdrawals">
			<table class="starter-author-withdrawals__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Method', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Status', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Requested', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Processed', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Note', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $withdrawals as $w ) : ?>
					<tr class="starter-withdrawal-status--<?php echo esc_attr( $w->status ); ?>">
						<td><?php echo esc_html( $w->id ); ?></td>
						<td><?php echo esc_html( number_format( $w->amount, 2 ) . ' ' . $currency ); ?></td>
						<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $w->method ) ) ); ?></td>
						<td>
							<span class="starter-withdrawal-badge starter-withdrawal-badge--<?php echo esc_attr( $w->status ); ?>">
								<?php echo esc_html( isset( $status_labels[ $w->status ] ) ? $status_labels[ $w->status ] : $w->status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $w->request_date ); ?></td>
						<td><?php echo $w->process_date ? esc_html( $w->process_date ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( $w->admin_note ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Admin pages
	 * ----------------------------------------------------------------*/

	/**
	 * Register admin menus.
	 *
	 * @return void
	 */
	public function register_admin_menus() {
		add_submenu_page(
			'starter-coins',
			__( 'Revenue Share', 'starter-theme' ),
			__( 'Revenue Share', 'starter-theme' ),
			'manage_options',
			'starter-revenue-share',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'starter-coins',
			__( 'Withdrawals', 'starter-theme' ),
			__( 'Withdrawals', 'starter-theme' ),
			'manage_options',
			'starter-withdrawals',
			array( $this, 'render_withdrawals_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'starter_coin_settings', 'starter_author_share_percent', array(
			'type'              => 'integer',
			'sanitize_callback' => function ( $val ) {
				$val = absint( $val );
				return min( 100, max( 0, $val ) );
			},
			'default'           => 70,
		) );

		register_setting( 'starter_coin_settings', 'starter_minimum_withdrawal', array(
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 50.00,
		) );

		register_setting( 'starter_coin_settings', 'starter_coin_value', array(
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 0.05,
		) );
	}

	/**
	 * Render the revenue share admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$site_total = floatval( get_option( 'starter_site_earnings_total', 0 ) );
		$currency   = get_option( 'starter_coin_currency', 'USD' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Revenue Share Settings', 'starter-theme' ); ?></h1>

			<div class="starter-admin-cards" style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:30px;">
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Author Share', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( $this->get_author_share_percent() . '%' ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Site Share', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( $this->get_site_share_percent() . '%' ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Site Earnings Total', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format( $site_total, 2 ) . ' ' . $currency ); ?></p>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'starter_coin_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="starter_author_share_percent"><?php esc_html_e( 'Author Share (%)', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="number" id="starter_author_share_percent" name="starter_author_share_percent"
								value="<?php echo esc_attr( $this->get_author_share_percent() ); ?>" min="0" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Percentage of chapter revenue that goes to the author. Site receives the remainder.', 'starter-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="starter_minimum_withdrawal"><?php esc_html_e( 'Minimum Withdrawal', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="number" id="starter_minimum_withdrawal" name="starter_minimum_withdrawal"
								value="<?php echo esc_attr( $this->get_minimum_withdrawal() ); ?>" min="0" step="0.01" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="starter_coin_value"><?php esc_html_e( 'Coin Value (currency per coin)', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="number" id="starter_coin_value" name="starter_coin_value"
								value="<?php echo esc_attr( $this->get_coin_value() ); ?>" min="0" step="0.001" class="small-text" />
							<p class="description"><?php esc_html_e( 'How much 1 coin is worth in real currency for revenue calculations.', 'starter-theme' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the withdrawals admin page.
	 *
	 * @return void
	 */
	public function render_withdrawals_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$currency      = get_option( 'starter_coin_currency', 'USD' );

		global $wpdb;
		$table = $wpdb->prefix . self::WITHDRAWALS_TABLE;

		$where = '';
		if ( $status_filter ) {
			$where = $wpdb->prepare( " WHERE status = %s", $status_filter );
		}

		$withdrawals = $wpdb->get_results(
			"SELECT * FROM {$table} {$where} ORDER BY request_date DESC LIMIT 100"
		);

		$status_labels = array(
			'pending'   => __( 'Pending', 'starter-theme' ),
			'approved'  => __( 'Approved', 'starter-theme' ),
			'completed' => __( 'Completed', 'starter-theme' ),
			'rejected'  => __( 'Rejected', 'starter-theme' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Withdrawal Requests', 'starter-theme' ); ?></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=starter-withdrawals' ) ); ?>"
					<?php echo ! $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'starter-theme' ); ?></a> |</li>
				<?php foreach ( $status_labels as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, admin_url( 'admin.php?page=starter-withdrawals' ) ) ); ?>"
					<?php echo $status_filter === $key ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php echo 'rejected' !== $key ? '|' : ''; ?></li>
				<?php endforeach; ?>
			</ul>

			<table class="widefat fixed striped" style="margin-top:10px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Author', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Method', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Status', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Requested', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $withdrawals ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No withdrawal requests found.', 'starter-theme' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $withdrawals as $w ) : ?>
							<?php $author = get_userdata( $w->user_id ); ?>
							<tr>
								<td><?php echo esc_html( $w->id ); ?></td>
								<td><?php echo $author ? esc_html( $author->display_name ) : esc_html( '#' . $w->user_id ); ?></td>
								<td><?php echo esc_html( number_format( $w->amount, 2 ) . ' ' . $currency ); ?></td>
								<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $w->method ) ) ); ?></td>
								<td>
									<span class="starter-withdrawal-badge starter-withdrawal-badge--<?php echo esc_attr( $w->status ); ?>">
										<?php echo esc_html( isset( $status_labels[ $w->status ] ) ? $status_labels[ $w->status ] : $w->status ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $w->request_date ); ?></td>
								<td>
									<?php if ( 'pending' === $w->status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'starter_process_withdrawal_' . $w->id ); ?>
											<input type="hidden" name="action" value="starter_process_withdrawal" />
											<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $w->id ); ?>" />
											<input type="text" name="admin_note" placeholder="<?php esc_attr_e( 'Note (optional)', 'starter-theme' ); ?>" style="width:150px;" />
											<button type="submit" name="new_status" value="approved" class="button button-primary button-small">
												<?php esc_html_e( 'Approve', 'starter-theme' ); ?>
											</button>
											<button type="submit" name="new_status" value="rejected" class="button button-small">
												<?php esc_html_e( 'Reject', 'starter-theme' ); ?>
											</button>
										</form>
									<?php elseif ( 'approved' === $w->status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'starter_process_withdrawal_' . $w->id ); ?>
											<input type="hidden" name="action" value="starter_process_withdrawal" />
											<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $w->id ); ?>" />
											<input type="text" name="admin_note" placeholder="<?php esc_attr_e( 'Note', 'starter-theme' ); ?>" style="width:150px;" />
											<button type="submit" name="new_status" value="completed" class="button button-primary button-small">
												<?php esc_html_e( 'Mark Completed', 'starter-theme' ); ?>
											</button>
										</form>
									<?php else : ?>
										<?php echo esc_html( $w->admin_note ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Admin POST handler: process a withdrawal status change.
	 *
	 * @return void
	 */
	public function admin_process_withdrawal() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$withdrawal_id = isset( $_POST['withdrawal_id'] ) ? absint( $_POST['withdrawal_id'] ) : 0;

		if ( ! $withdrawal_id ) {
			wp_die( esc_html__( 'Invalid withdrawal.', 'starter-theme' ) );
		}

		check_admin_referer( 'starter_process_withdrawal_' . $withdrawal_id );

		$new_status = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] ) : '';
		$admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( $_POST['admin_note'] ) : '';

		$this->update_withdrawal_status( $withdrawal_id, $new_status, $admin_note );

		wp_safe_redirect( admin_url( 'admin.php?page=starter-withdrawals&updated=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Frontend assets
	 * ----------------------------------------------------------------*/

	/**
	 * Enqueue frontend scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'starter-revenue-share',
			get_template_directory_uri() . '/assets/js/revenue-share.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script( 'starter-revenue-share', 'starterRevenue', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'starter_revenue_nonce' ),
		) );
	}
}
