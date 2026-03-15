<?php
/**
 * Coin system for chapter monetization.
 *
 * Manages virtual currency (coins) that readers spend to unlock premium
 * chapters. Integrates with MyCred when available, otherwise uses a
 * built-in points table. Coin purchases go through WooCommerce when
 * active, with fallback PayPal/Stripe buttons.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Coin_System
 */
class Starter_Coin_System {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Coin_System|null
	 */
	private static $instance = null;

	/**
	 * Whether MyCred is active and should be used.
	 *
	 * @var bool
	 */
	private $use_mycred = false;

	/**
	 * User coins table name (without prefix).
	 *
	 * @var string
	 */
	const COINS_TABLE = 'starter_user_coins';

	/**
	 * Transaction log table name (without prefix).
	 *
	 * @var string
	 */
	const TRANSACTIONS_TABLE = 'starter_coin_transactions';

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Coin_System
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
		$this->use_mycred = class_exists( 'myCRED_Core' ) || function_exists( 'mycred' );
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Database tables.
		register_activation_hook( __FILE__, array( $this, 'create_tables' ) );
		add_action( 'after_switch_theme', array( $this, 'create_tables' ) );

		// Shortcodes.
		add_shortcode( 'starter_user_balance', array( $this, 'shortcode_user_balance' ) );
		add_shortcode( 'starter_purchased_manga', array( $this, 'shortcode_purchased_manga' ) );
		add_shortcode( 'starter_coin_rankings', array( $this, 'shortcode_coin_rankings' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_starter_buy_coins', array( $this, 'ajax_buy_coins' ) );
		add_action( 'wp_ajax_starter_unlock_chapter', array( $this, 'ajax_unlock_chapter' ) );
		add_action( 'wp_ajax_starter_check_balance', array( $this, 'ajax_check_balance' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Chapter meta box for coin cost.
		add_action( 'add_meta_boxes', array( $this, 'add_coin_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_coin_meta' ) );

		// Enqueue scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// WooCommerce integration.
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'woo_order_completed' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Database
	 * ----------------------------------------------------------------*/

	/**
	 * Create custom database tables using dbDelta.
	 *
	 * @return void
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$coins_table        = $wpdb->prefix . self::COINS_TABLE;
		$transactions_table = $wpdb->prefix . self::TRANSACTIONS_TABLE;

		$sql = "CREATE TABLE {$coins_table} (
			user_id bigint(20) unsigned NOT NULL,
			balance bigint(20) NOT NULL DEFAULT 0,
			total_earned bigint(20) NOT NULL DEFAULT 0,
			total_spent bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (user_id)
		) {$charset_collate};

		CREATE TABLE {$transactions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			amount bigint(20) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'earn',
			reference_type varchar(50) NOT NULL DEFAULT '',
			reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
			description text NOT NULL,
			date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY type (type),
			KEY date (date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------
	 * Balance helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Get a user's coin balance.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Current balance.
	 */
	public function get_balance( $user_id ) {
		$user_id = absint( $user_id );

		if ( $this->use_mycred && function_exists( 'mycred_get_users_balance' ) ) {
			return (int) mycred_get_users_balance( $user_id );
		}

		global $wpdb;
		$table = $wpdb->prefix . self::COINS_TABLE;

		$balance = $wpdb->get_var(
			$wpdb->prepare( "SELECT balance FROM {$table} WHERE user_id = %d", $user_id )
		);

		return $balance ? (int) $balance : 0;
	}

	/**
	 * Credit coins to a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $amount      Number of coins to credit (positive).
	 * @param string $type        Transaction type: earn|purchase.
	 * @param string $ref_type    Reference type (e.g. 'woo_order', 'admin').
	 * @param int    $ref_id      Reference ID.
	 * @param string $description Human-readable description.
	 * @return bool True on success.
	 */
	public function credit_coins( $user_id, $amount, $type = 'earn', $ref_type = '', $ref_id = 0, $description = '' ) {
		$user_id = absint( $user_id );
		$amount  = absint( $amount );

		if ( ! $amount || ! $user_id ) {
			return false;
		}

		if ( $this->use_mycred && function_exists( 'mycred_add' ) ) {
			$result = mycred_add(
				$ref_type ? $ref_type : 'starter_credit',
				$user_id,
				$amount,
				$description
			);
			if ( $result ) {
				$this->log_transaction( $user_id, $amount, $type, $ref_type, $ref_id, $description );
				return true;
			}
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::COINS_TABLE;

		// Upsert balance row.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, balance, total_earned, total_spent)
				 VALUES (%d, %d, %d, 0)
				 ON DUPLICATE KEY UPDATE balance = balance + %d, total_earned = total_earned + %d",
				$user_id,
				$amount,
				$amount,
				$amount,
				$amount
			)
		);

		$this->log_transaction( $user_id, $amount, $type, $ref_type, $ref_id, $description );
		return true;
	}

	/**
	 * Deduct coins from a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $amount      Number of coins to deduct (positive).
	 * @param string $type        Transaction type: spend|withdraw.
	 * @param string $ref_type    Reference type.
	 * @param int    $ref_id      Reference ID.
	 * @param string $description Human-readable description.
	 * @return bool True on success, false if insufficient balance.
	 */
	public function deduct_coins( $user_id, $amount, $type = 'spend', $ref_type = '', $ref_id = 0, $description = '' ) {
		$user_id = absint( $user_id );
		$amount  = absint( $amount );

		if ( ! $amount || ! $user_id ) {
			return false;
		}

		$balance = $this->get_balance( $user_id );
		if ( $balance < $amount ) {
			return false;
		}

		if ( $this->use_mycred && function_exists( 'mycred_subtract' ) ) {
			$result = mycred_subtract(
				$ref_type ? $ref_type : 'starter_deduct',
				$user_id,
				$amount,
				$description
			);
			if ( $result ) {
				$this->log_transaction( $user_id, -$amount, $type, $ref_type, $ref_id, $description );
				return true;
			}
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::COINS_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET balance = balance - %d, total_spent = total_spent + %d WHERE user_id = %d AND balance >= %d",
				$amount,
				$amount,
				$user_id,
				$amount
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			return false;
		}

		$this->log_transaction( $user_id, -$amount, $type, $ref_type, $ref_id, $description );
		return true;
	}

	/**
	 * Record a transaction in the log.
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $amount      Signed amount.
	 * @param string $type        Transaction type.
	 * @param string $ref_type    Reference type.
	 * @param int    $ref_id      Reference ID.
	 * @param string $description Description.
	 * @return int|false Insert ID or false.
	 */
	private function log_transaction( $user_id, $amount, $type, $ref_type, $ref_id, $description ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TRANSACTIONS_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'        => absint( $user_id ),
				'amount'         => intval( $amount ),
				'type'           => sanitize_key( $type ),
				'reference_type' => sanitize_text_field( $ref_type ),
				'reference_id'   => absint( $ref_id ),
				'description'    => sanitize_text_field( $description ),
				'date'           => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/* ------------------------------------------------------------------
	 * Chapter coin cost
	 * ----------------------------------------------------------------*/

	/**
	 * Get the coin cost for a chapter.
	 *
	 * @param int $chapter_id Post ID of the chapter.
	 * @return int Coin cost (0 = free).
	 */
	public function get_chapter_coin_cost( $chapter_id ) {
		$cost = get_post_meta( absint( $chapter_id ), '_starter_coin_cost', true );
		return $cost ? absint( $cost ) : 0;
	}

	/**
	 * Check whether a user has unlocked a specific chapter.
	 *
	 * @param int $user_id    User ID.
	 * @param int $chapter_id Chapter post ID.
	 * @return bool
	 */
	public function has_unlocked_chapter( $user_id, $chapter_id ) {
		$user_id    = absint( $user_id );
		$chapter_id = absint( $chapter_id );

		if ( ! $user_id ) {
			return false;
		}

		// Free chapters are always accessible.
		$cost = $this->get_chapter_coin_cost( $chapter_id );
		if ( 0 === $cost ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TRANSACTIONS_TABLE;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND reference_type = 'chapter_unlock' AND reference_id = %d LIMIT 1",
				$user_id,
				$chapter_id
			)
		);

		return (bool) $exists;
	}

	/**
	 * Attempt to unlock a chapter for a user.
	 *
	 * @param int $user_id    User ID.
	 * @param int $chapter_id Chapter post ID.
	 * @return true|WP_Error
	 */
	public function unlock_chapter( $user_id, $chapter_id ) {
		$user_id    = absint( $user_id );
		$chapter_id = absint( $chapter_id );

		if ( ! $user_id || ! $chapter_id ) {
			return new WP_Error( 'invalid_args', __( 'Invalid user or chapter.', 'starter-theme' ) );
		}

		if ( $this->has_unlocked_chapter( $user_id, $chapter_id ) ) {
			return true;
		}

		$cost = $this->get_chapter_coin_cost( $chapter_id );
		if ( 0 === $cost ) {
			return true;
		}

		$balance = $this->get_balance( $user_id );
		if ( $balance < $cost ) {
			return new WP_Error(
				'insufficient_balance',
				sprintf(
					/* translators: 1: coin cost, 2: current balance */
					__( 'This chapter costs %1$d coins. Your balance is %2$d coins.', 'starter-theme' ),
					$cost,
					$balance
				)
			);
		}

		$chapter_title = get_the_title( $chapter_id );
		$description   = sprintf(
			/* translators: %s: chapter title */
			__( 'Unlocked chapter: %s', 'starter-theme' ),
			$chapter_title
		);

		$deducted = $this->deduct_coins( $user_id, $cost, 'spend', 'chapter_unlock', $chapter_id, $description );
		if ( ! $deducted ) {
			return new WP_Error( 'deduction_failed', __( 'Could not process payment. Please try again.', 'starter-theme' ) );
		}

		/**
		 * Fires after a chapter is unlocked via coins.
		 *
		 * @param int $user_id    The user who unlocked.
		 * @param int $chapter_id The chapter unlocked.
		 * @param int $cost       Coins spent.
		 */
		do_action( 'starter_chapter_unlocked', $user_id, $chapter_id, $cost );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Coin packages
	 * ----------------------------------------------------------------*/

	/**
	 * Get configured coin packages.
	 *
	 * @return array Array of packages: [ [ 'coins' => int, 'price' => float, 'label' => string ] ... ]
	 */
	public function get_coin_packages() {
		$defaults = array(
			array(
				'coins' => 100,
				'price' => 5.00,
				'label' => __( '100 Coins', 'starter-theme' ),
			),
			array(
				'coins' => 500,
				'price' => 20.00,
				'label' => __( '500 Coins', 'starter-theme' ),
			),
			array(
				'coins' => 1000,
				'price' => 35.00,
				'label' => __( '1000 Coins', 'starter-theme' ),
			),
		);

		$packages = get_option( 'starter_coin_packages', $defaults );

		return apply_filters( 'starter_coin_packages', $packages );
	}

	/**
	 * Get the default coin price per chapter.
	 *
	 * @return int
	 */
	public function get_default_coin_price() {
		return absint( get_option( 'starter_default_coin_price', 10 ) );
	}

	/* ------------------------------------------------------------------
	 * AJAX endpoints
	 * ----------------------------------------------------------------*/

	/**
	 * AJAX: Buy coins (process coin package purchase).
	 *
	 * @return void
	 */
	public function ajax_buy_coins() {
		check_ajax_referer( 'starter_coin_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$package_index = isset( $_POST['package'] ) ? absint( $_POST['package'] ) : -1;
		$packages      = $this->get_coin_packages();

		if ( ! isset( $packages[ $package_index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid package selected.', 'starter-theme' ) ) );
		}

		$package = $packages[ $package_index ];
		$user_id = get_current_user_id();

		// If WooCommerce is active, create an order and redirect.
		if ( class_exists( 'WooCommerce' ) ) {
			$checkout_url = $this->create_woo_coin_order( $user_id, $package );
			if ( is_wp_error( $checkout_url ) ) {
				wp_send_json_error( array( 'message' => $checkout_url->get_error_message() ) );
			}
			wp_send_json_success( array(
				'redirect' => $checkout_url,
			) );
		}

		// Fallback: return PayPal/Stripe button data for client-side processing.
		$payment_data = $this->get_payment_button_data( $user_id, $package );
		wp_send_json_success( $payment_data );
	}

	/**
	 * AJAX: Unlock a chapter.
	 *
	 * @return void
	 */
	public function ajax_unlock_chapter() {
		check_ajax_referer( 'starter_coin_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'starter-theme' ) ) );
		}

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid chapter.', 'starter-theme' ) ) );
		}

		$user_id = get_current_user_id();
		$result  = $this->unlock_chapter( $user_id, $chapter_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Chapter unlocked!', 'starter-theme' ),
			'balance' => $this->get_balance( $user_id ),
		) );
	}

	/**
	 * AJAX: Check current user's balance.
	 *
	 * @return void
	 */
	public function ajax_check_balance() {
		check_ajax_referer( 'starter_coin_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'starter-theme' ) ) );
		}

		wp_send_json_success( array(
			'balance' => $this->get_balance( get_current_user_id() ),
		) );
	}

	/* ------------------------------------------------------------------
	 * WooCommerce integration
	 * ----------------------------------------------------------------*/

	/**
	 * Create a WooCommerce order for a coin package.
	 *
	 * @param int   $user_id User ID.
	 * @param array $package Package data with 'coins' and 'price'.
	 * @return string|WP_Error Checkout URL or error.
	 */
	private function create_woo_coin_order( $user_id, $package ) {
		if ( ! class_exists( 'WC_Order' ) ) {
			return new WP_Error( 'woo_missing', __( 'WooCommerce is not available.', 'starter-theme' ) );
		}

		$order = wc_create_order( array( 'customer_id' => $user_id ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$fee = new WC_Order_Item_Fee();
		$fee->set_name(
			sprintf(
				/* translators: %d: number of coins */
				__( 'Coin Package: %d Coins', 'starter-theme' ),
				$package['coins']
			)
		);
		$fee->set_total( floatval( $package['price'] ) );
		$order->add_item( $fee );

		$order->calculate_totals();
		$order->update_meta_data( '_starter_coin_purchase', true );
		$order->update_meta_data( '_starter_coin_amount', absint( $package['coins'] ) );
		$order->save();

		return $order->get_checkout_payment_url();
	}

	/**
	 * Handle completed WooCommerce orders for coin purchases.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function woo_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$is_coin_purchase = $order->get_meta( '_starter_coin_purchase' );
		if ( ! $is_coin_purchase ) {
			return;
		}

		// Prevent double-crediting.
		if ( $order->get_meta( '_starter_coins_credited' ) ) {
			return;
		}

		$coin_amount = absint( $order->get_meta( '_starter_coin_amount' ) );
		$user_id     = $order->get_customer_id();

		if ( $coin_amount && $user_id ) {
			$this->credit_coins(
				$user_id,
				$coin_amount,
				'purchase',
				'woo_order',
				$order_id,
				sprintf(
					/* translators: 1: coins, 2: order ID */
					__( 'Purchased %1$d coins (Order #%2$d)', 'starter-theme' ),
					$coin_amount,
					$order_id
				)
			);

			$order->update_meta_data( '_starter_coins_credited', true );
			$order->save();
		}
	}

	/**
	 * Get fallback payment button data (PayPal/Stripe) when WooCommerce is not active.
	 *
	 * @param int   $user_id User ID.
	 * @param array $package Package data.
	 * @return array Data for client-side payment buttons.
	 */
	private function get_payment_button_data( $user_id, $package ) {
		$paypal_email  = get_option( 'starter_paypal_email', '' );
		$stripe_key    = get_option( 'starter_stripe_publishable_key', '' );
		$currency      = get_option( 'starter_coin_currency', 'USD' );
		$return_url    = add_query_arg(
			array(
				'starter_coin_callback' => 1,
				'user_id'               => $user_id,
				'coins'                 => $package['coins'],
			),
			home_url( '/' )
		);

		$data = array(
			'coins'    => $package['coins'],
			'price'    => $package['price'],
			'currency' => $currency,
			'methods'  => array(),
		);

		if ( $paypal_email ) {
			$data['methods']['paypal'] = array(
				'email'      => sanitize_email( $paypal_email ),
				'return_url' => esc_url( $return_url ),
				'item_name'  => sprintf(
					/* translators: %d: number of coins */
					__( '%d Coins', 'starter-theme' ),
					$package['coins']
				),
			);
		}

		if ( $stripe_key ) {
			$data['methods']['stripe'] = array(
				'publishable_key' => sanitize_text_field( $stripe_key ),
				'amount_cents'    => intval( $package['price'] * 100 ),
			);
		}

		return $data;
	}

	/* ------------------------------------------------------------------
	 * Shortcodes
	 * ----------------------------------------------------------------*/

	/**
	 * Shortcode: [starter_user_balance]
	 *
	 * Displays the current user's coin balance.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_user_balance( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<span class="starter-coin-balance starter-coin-balance--guest">'
				. esc_html__( 'Please log in to view your balance.', 'starter-theme' )
				. '</span>';
		}

		$balance = $this->get_balance( get_current_user_id() );

		return '<span class="starter-coin-balance">'
			. sprintf(
				/* translators: %s: formatted coin balance */
				esc_html__( 'Balance: %s coins', 'starter-theme' ),
				'<strong>' . esc_html( number_format_i18n( $balance ) ) . '</strong>'
			)
			. '</span>';
	}

	/**
	 * Shortcode: [starter_purchased_manga]
	 *
	 * Lists manga/novels that the current user has unlocked chapters for.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_purchased_manga( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your purchased content.', 'starter-theme' ) . '</p>';
		}

		global $wpdb;
		$table   = $wpdb->prefix . self::TRANSACTIONS_TABLE;
		$user_id = get_current_user_id();

		$chapter_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT reference_id FROM {$table} WHERE user_id = %d AND reference_type = 'chapter_unlock' ORDER BY date DESC",
				$user_id
			)
		);

		if ( empty( $chapter_ids ) ) {
			return '<p>' . esc_html__( 'You have not purchased any chapters yet.', 'starter-theme' ) . '</p>';
		}

		// Group chapters by parent manga/novel.
		$manga_chapters = array();
		foreach ( $chapter_ids as $chapter_id ) {
			$parent_id = wp_get_post_parent_id( $chapter_id );
			if ( ! $parent_id ) {
				$parent_id = $chapter_id;
			}
			$manga_chapters[ $parent_id ][] = $chapter_id;
		}

		$output = '<div class="starter-purchased-manga">';
		foreach ( $manga_chapters as $manga_id => $chapters ) {
			$manga_title = get_the_title( $manga_id );
			$manga_url   = get_permalink( $manga_id );

			$output .= '<div class="starter-purchased-manga__item">';
			$output .= '<h4><a href="' . esc_url( $manga_url ) . '">' . esc_html( $manga_title ) . '</a></h4>';
			$output .= '<ul>';
			foreach ( $chapters as $ch_id ) {
				$output .= '<li><a href="' . esc_url( get_permalink( $ch_id ) ) . '">'
					. esc_html( get_the_title( $ch_id ) ) . '</a></li>';
			}
			$output .= '</ul></div>';
		}
		$output .= '</div>';

		return $output;
	}

	/**
	 * Shortcode: [starter_coin_rankings]
	 *
	 * Displays a leaderboard of top coin spenders.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_coin_rankings( $atts ) {
		$atts = shortcode_atts( array(
			'limit' => 10,
		), $atts, 'starter_coin_rankings' );

		global $wpdb;
		$table = $wpdb->prefix . self::COINS_TABLE;
		$limit = absint( $atts['limit'] );

		if ( $limit < 1 || $limit > 100 ) {
			$limit = 10;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, total_spent FROM {$table} WHERE total_spent > 0 ORDER BY total_spent DESC LIMIT %d",
				$limit
			)
		);

		if ( empty( $results ) ) {
			return '<p>' . esc_html__( 'No rankings available yet.', 'starter-theme' ) . '</p>';
		}

		$output  = '<div class="starter-coin-rankings">';
		$output .= '<table class="starter-coin-rankings__table">';
		$output .= '<thead><tr>';
		$output .= '<th>' . esc_html__( 'Rank', 'starter-theme' ) . '</th>';
		$output .= '<th>' . esc_html__( 'User', 'starter-theme' ) . '</th>';
		$output .= '<th>' . esc_html__( 'Coins Spent', 'starter-theme' ) . '</th>';
		$output .= '</tr></thead><tbody>';

		$rank = 1;
		foreach ( $results as $row ) {
			$user = get_userdata( $row->user_id );
			$name = $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'starter-theme' );

			$output .= '<tr>';
			$output .= '<td>' . esc_html( $rank ) . '</td>';
			$output .= '<td>' . $name . '</td>';
			$output .= '<td>' . esc_html( number_format_i18n( $row->total_spent ) ) . '</td>';
			$output .= '</tr>';

			$rank++;
		}

		$output .= '</tbody></table></div>';

		return $output;
	}

	/* ------------------------------------------------------------------
	 * Chapter meta box (coin cost)
	 * ----------------------------------------------------------------*/

	/**
	 * Register meta box on chapter post type for setting coin cost.
	 *
	 * @return void
	 */
	public function add_coin_meta_box() {
		$post_types = apply_filters( 'starter_coin_post_types', array( 'chapter', 'post' ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'starter_coin_cost',
				__( 'Coin Cost', 'starter-theme' ),
				array( $this, 'render_coin_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the coin cost meta box.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_coin_meta_box( $post ) {
		wp_nonce_field( 'starter_coin_cost_nonce', 'starter_coin_cost_nonce_field' );

		$cost    = $this->get_chapter_coin_cost( $post->ID );
		$default = $this->get_default_coin_price();
		?>
		<p>
			<label for="starter_coin_cost">
				<?php esc_html_e( 'Coins required to unlock:', 'starter-theme' ); ?>
			</label>
			<input
				type="number"
				id="starter_coin_cost"
				name="starter_coin_cost"
				value="<?php echo esc_attr( $cost ); ?>"
				min="0"
				step="1"
				class="widefat"
			/>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: default coin price */
				esc_html__( 'Set to 0 for free. Default price: %d coins.', 'starter-theme' ),
				$default
			);
			?>
		</p>
		<?php
	}

	/**
	 * Save the coin cost meta.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_coin_meta( $post_id ) {
		if ( ! isset( $_POST['starter_coin_cost_nonce_field'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['starter_coin_cost_nonce_field'], 'starter_coin_cost_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['starter_coin_cost'] ) ) {
			$cost = absint( $_POST['starter_coin_cost'] );
			update_post_meta( $post_id, '_starter_coin_cost', $cost );
		}
	}

	/* ------------------------------------------------------------------
	 * Admin pages
	 * ----------------------------------------------------------------*/

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_menus() {
		add_menu_page(
			__( 'Coin System', 'starter-theme' ),
			__( 'Coin System', 'starter-theme' ),
			'manage_options',
			'starter-coins',
			array( $this, 'render_admin_dashboard' ),
			'dashicons-money-alt',
			58
		);

		add_submenu_page(
			'starter-coins',
			__( 'Coin Settings', 'starter-theme' ),
			__( 'Settings', 'starter-theme' ),
			'manage_options',
			'starter-coin-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'starter-coins',
			__( 'Transaction Log', 'starter-theme' ),
			__( 'Transactions', 'starter-theme' ),
			'manage_options',
			'starter-coin-transactions',
			array( $this, 'render_transactions_page' )
		);

		add_submenu_page(
			'starter-coins',
			__( 'Manage Balances', 'starter-theme' ),
			__( 'User Balances', 'starter-theme' ),
			'manage_options',
			'starter-coin-balances',
			array( $this, 'render_balances_page' )
		);

		add_submenu_page(
			'starter-coins',
			__( 'Revenue Reports', 'starter-theme' ),
			__( 'Revenue Reports', 'starter-theme' ),
			'manage_options',
			'starter-coin-reports',
			array( $this, 'render_reports_page' )
		);
	}

	/**
	 * Register theme settings for the coin system.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'starter_coin_settings', 'starter_default_coin_price', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10,
		) );

		register_setting( 'starter_coin_settings', 'starter_coin_packages', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_coin_packages' ),
		) );

		register_setting( 'starter_coin_settings', 'starter_paypal_email', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
		) );

		register_setting( 'starter_coin_settings', 'starter_stripe_publishable_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( 'starter_coin_settings', 'starter_coin_currency', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'USD',
		) );
	}

	/**
	 * Sanitize coin packages option.
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized packages.
	 */
	public function sanitize_coin_packages( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $input as $pkg ) {
			if ( ! isset( $pkg['coins'], $pkg['price'] ) ) {
				continue;
			}
			$sanitized[] = array(
				'coins' => absint( $pkg['coins'] ),
				'price' => floatval( $pkg['price'] ),
				'label' => isset( $pkg['label'] ) ? sanitize_text_field( $pkg['label'] ) : '',
			);
		}

		return $sanitized;
	}

	/**
	 * Render the admin dashboard page (overview & revenue summary).
	 *
	 * @return void
	 */
	public function render_admin_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$stats = $this->get_revenue_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Coin System Dashboard', 'starter-theme' ); ?></h1>

			<div class="starter-admin-cards" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Total Coins Sold', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $stats['total_coins_sold'] ) ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Total Coins Spent', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $stats['total_coins_spent'] ) ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Active Users', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $stats['active_users'] ) ); ?></p>
				</div>
			</div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Top Manga by Revenue', 'starter-theme' ); ?></h2>
			<?php $this->render_top_manga_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$packages = $this->get_coin_packages();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Coin System Settings', 'starter-theme' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'starter_coin_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="starter_default_coin_price"><?php esc_html_e( 'Default Coin Price per Chapter', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="number" id="starter_default_coin_price" name="starter_default_coin_price"
								value="<?php echo esc_attr( $this->get_default_coin_price() ); ?>" min="0" step="1" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="starter_paypal_email"><?php esc_html_e( 'PayPal Email', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="email" id="starter_paypal_email" name="starter_paypal_email"
								value="<?php echo esc_attr( get_option( 'starter_paypal_email', '' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Used for fallback PayPal payments when WooCommerce is not active.', 'starter-theme' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="starter_stripe_publishable_key"><?php esc_html_e( 'Stripe Publishable Key', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="text" id="starter_stripe_publishable_key" name="starter_stripe_publishable_key"
								value="<?php echo esc_attr( get_option( 'starter_stripe_publishable_key', '' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="starter_coin_currency"><?php esc_html_e( 'Currency', 'starter-theme' ); ?></label>
						</th>
						<td>
							<input type="text" id="starter_coin_currency" name="starter_coin_currency"
								value="<?php echo esc_attr( get_option( 'starter_coin_currency', 'USD' ) ); ?>" class="small-text" maxlength="3" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Coin Packages', 'starter-theme' ); ?></h2>
				<table class="widefat" id="starter-coin-packages-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Coins', 'starter-theme' ); ?></th>
							<th><?php esc_html_e( 'Price', 'starter-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $packages as $i => $pkg ) : ?>
						<tr>
							<td><input type="text" name="starter_coin_packages[<?php echo esc_attr( $i ); ?>][label]"
								value="<?php echo esc_attr( $pkg['label'] ); ?>" class="regular-text" /></td>
							<td><input type="number" name="starter_coin_packages[<?php echo esc_attr( $i ); ?>][coins]"
								value="<?php echo esc_attr( $pkg['coins'] ); ?>" min="1" class="small-text" /></td>
							<td><input type="number" name="starter_coin_packages[<?php echo esc_attr( $i ); ?>][price]"
								value="<?php echo esc_attr( $pkg['price'] ); ?>" min="0" step="0.01" class="small-text" /></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the transaction log admin page.
	 *
	 * @return void
	 */
	public function render_transactions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		global $wpdb;
		$table    = $wpdb->prefix . self::TRANSACTIONS_TABLE;
		$per_page = 50;
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY date DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Transaction Log', 'starter-theme' ); ?></h1>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'User', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Type', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Description', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Date', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No transactions found.', 'starter-theme' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $user = get_userdata( $row->user_id ); ?>
							<tr>
								<td><?php echo esc_html( $row->id ); ?></td>
								<td><?php echo $user ? esc_html( $user->display_name ) : esc_html( $row->user_id ); ?></td>
								<td><?php echo esc_html( $row->amount ); ?></td>
								<td><?php echo esc_html( $row->type ); ?></td>
								<td><?php echo esc_html( $row->reference_type . ':' . $row->reference_id ); ?></td>
								<td><?php echo esc_html( $row->description ); ?></td>
								<td><?php echo esc_html( $row->date ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			$total_pages = ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $total_pages,
						'current' => $paged,
					) )
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the user balances admin page.
	 *
	 * @return void
	 */
	public function render_balances_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		// Handle balance adjustment.
		if ( isset( $_POST['starter_adjust_balance'] ) && check_admin_referer( 'starter_adjust_balance_nonce' ) ) {
			$target_user = absint( $_POST['target_user_id'] );
			$adjust_amt  = intval( $_POST['adjust_amount'] );
			$adjust_note = sanitize_text_field( $_POST['adjust_note'] );

			if ( $target_user && 0 !== $adjust_amt ) {
				if ( $adjust_amt > 0 ) {
					$this->credit_coins( $target_user, $adjust_amt, 'earn', 'admin_adjust', 0, $adjust_note ? $adjust_note : __( 'Admin adjustment', 'starter-theme' ) );
				} else {
					$this->deduct_coins( $target_user, abs( $adjust_amt ), 'spend', 'admin_adjust', 0, $adjust_note ? $adjust_note : __( 'Admin adjustment', 'starter-theme' ) );
				}
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Balance updated.', 'starter-theme' ) . '</p></div>';
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . self::COINS_TABLE;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY balance DESC LIMIT 100" );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Balances', 'starter-theme' ); ?></h1>

			<h2><?php esc_html_e( 'Adjust User Balance', 'starter-theme' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'starter_adjust_balance_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="target_user_id"><?php esc_html_e( 'User ID', 'starter-theme' ); ?></label></th>
						<td><input type="number" name="target_user_id" id="target_user_id" min="1" class="small-text" required /></td>
					</tr>
					<tr>
						<th><label for="adjust_amount"><?php esc_html_e( 'Amount (negative to deduct)', 'starter-theme' ); ?></label></th>
						<td><input type="number" name="adjust_amount" id="adjust_amount" class="small-text" required /></td>
					</tr>
					<tr>
						<th><label for="adjust_note"><?php esc_html_e( 'Note', 'starter-theme' ); ?></label></th>
						<td><input type="text" name="adjust_note" id="adjust_note" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Adjust Balance', 'starter-theme' ), 'primary', 'starter_adjust_balance' ); ?>
			</form>

			<h2><?php esc_html_e( 'Current Balances', 'starter-theme' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Total Earned', 'starter-theme' ); ?></th>
						<th><?php esc_html_e( 'Total Spent', 'starter-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No user balances found.', 'starter-theme' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $user = get_userdata( $row->user_id ); ?>
							<tr>
								<td><?php echo $user ? esc_html( $user->display_name . ' (#' . $row->user_id . ')' ) : esc_html( '#' . $row->user_id ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->balance ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->total_earned ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->total_spent ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the revenue reports admin page.
	 *
	 * @return void
	 */
	public function render_reports_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'starter-theme' ) );
		}

		$period     = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'monthly';
		$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Revenue Reports', 'starter-theme' ); ?></h1>

			<form method="get" style="margin-bottom:20px;">
				<input type="hidden" name="page" value="starter-coin-reports" />
				<label>
					<?php esc_html_e( 'Period:', 'starter-theme' ); ?>
					<select name="period">
						<option value="weekly" <?php selected( $period, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'starter-theme' ); ?></option>
						<option value="monthly" <?php selected( $period, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'starter-theme' ); ?></option>
						<option value="custom" <?php selected( $period, 'custom' ); ?>><?php esc_html_e( 'Custom', 'starter-theme' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'From:', 'starter-theme' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'To:', 'starter-theme' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				</label>
				<?php submit_button( __( 'Filter', 'starter-theme' ), 'secondary', 'filter', false ); ?>
			</form>

			<?php
			$report = $this->get_period_report( $period, $date_from, $date_to );
			?>

			<div class="starter-admin-cards" style="display:flex;gap:20px;flex-wrap:wrap;">
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Coins Purchased', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $report['coins_purchased'] ) ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Coins Spent on Chapters', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $report['coins_spent'] ) ); ?></p>
				</div>
				<div class="starter-admin-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;min-width:200px;">
					<h3><?php esc_html_e( 'Chapters Unlocked', 'starter-theme' ); ?></h3>
					<p style="font-size:24px;font-weight:bold;"><?php echo esc_html( number_format_i18n( $report['chapters_unlocked'] ) ); ?></p>
				</div>
			</div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Per-Manga Revenue', 'starter-theme' ); ?></h2>
			<?php $this->render_per_manga_report( $period, $date_from, $date_to ); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Revenue / reporting helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Get overall revenue stats.
	 *
	 * @return array
	 */
	public function get_revenue_stats() {
		global $wpdb;
		$tx_table   = $wpdb->prefix . self::TRANSACTIONS_TABLE;
		$coin_table = $wpdb->prefix . self::COINS_TABLE;

		$total_sold = $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$tx_table} WHERE type = 'purchase' AND amount > 0"
		);

		$total_spent = $wpdb->get_var(
			"SELECT COALESCE(SUM(ABS(amount)), 0) FROM {$tx_table} WHERE type = 'spend'"
		);

		$active_users = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$coin_table} WHERE total_earned > 0"
		);

		return array(
			'total_coins_sold'  => absint( $total_sold ),
			'total_coins_spent' => absint( $total_spent ),
			'active_users'      => absint( $active_users ),
		);
	}

	/**
	 * Get period-based report data.
	 *
	 * @param string $period    Period type: weekly|monthly|custom.
	 * @param string $date_from Start date (Y-m-d) for custom period.
	 * @param string $date_to   End date (Y-m-d) for custom period.
	 * @return array
	 */
	public function get_period_report( $period, $date_from = '', $date_to = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TRANSACTIONS_TABLE;

		$where_date = $this->build_date_where( $period, $date_from, $date_to );

		$coins_purchased = $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE type = 'purchase' AND amount > 0 {$where_date}"
		);

		$coins_spent = $wpdb->get_var(
			"SELECT COALESCE(SUM(ABS(amount)), 0) FROM {$table} WHERE type = 'spend' {$where_date}"
		);

		$chapters_unlocked = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE reference_type = 'chapter_unlock' {$where_date}"
		);

		return array(
			'coins_purchased'   => absint( $coins_purchased ),
			'coins_spent'       => absint( $coins_spent ),
			'chapters_unlocked' => absint( $chapters_unlocked ),
		);
	}

	/**
	 * Build a SQL WHERE clause fragment for date filtering.
	 *
	 * @param string $period    Period type.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return string SQL fragment (already escaped via prepare where needed).
	 */
	private function build_date_where( $period, $date_from, $date_to ) {
		global $wpdb;

		if ( 'custom' === $period && $date_from && $date_to ) {
			return $wpdb->prepare(
				" AND date >= %s AND date <= %s",
				sanitize_text_field( $date_from ) . ' 00:00:00',
				sanitize_text_field( $date_to ) . ' 23:59:59'
			);
		}

		if ( 'weekly' === $period ) {
			return $wpdb->prepare( " AND date >= %s", gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );
		}

		// Default: monthly.
		return $wpdb->prepare( " AND date >= %s", gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
	}

	/**
	 * Render a table of top manga by revenue (coins spent to unlock).
	 *
	 * @return void
	 */
	private function render_top_manga_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TRANSACTIONS_TABLE;

		$results = $wpdb->get_results(
			"SELECT reference_id, COUNT(*) as unlock_count, SUM(ABS(amount)) as total_coins
			 FROM {$table}
			 WHERE reference_type = 'chapter_unlock'
			 GROUP BY reference_id
			 ORDER BY total_coins DESC
			 LIMIT 20"
		);

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'No data available yet.', 'starter-theme' ) . '</p>';
			return;
		}

		// Group by parent manga.
		$manga_data = array();
		foreach ( $results as $row ) {
			$parent_id = wp_get_post_parent_id( $row->reference_id );
			if ( ! $parent_id ) {
				$parent_id = $row->reference_id;
			}

			if ( ! isset( $manga_data[ $parent_id ] ) ) {
				$manga_data[ $parent_id ] = array(
					'title'    => get_the_title( $parent_id ),
					'coins'    => 0,
					'unlocks'  => 0,
				);
			}
			$manga_data[ $parent_id ]['coins']   += absint( $row->total_coins );
			$manga_data[ $parent_id ]['unlocks'] += absint( $row->unlock_count );
		}

		// Sort by coins descending.
		uasort( $manga_data, function ( $a, $b ) {
			return $b['coins'] - $a['coins'];
		} );

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Manga / Novel', 'starter-theme' ) . '</th>';
		echo '<th>' . esc_html__( 'Coins Earned', 'starter-theme' ) . '</th>';
		echo '<th>' . esc_html__( 'Chapters Unlocked', 'starter-theme' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $manga_data as $manga ) {
			echo '<tr>';
			echo '<td>' . esc_html( $manga['title'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $manga['coins'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $manga['unlocks'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render per-manga revenue report for a given period.
	 *
	 * @param string $period    Period type.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return void
	 */
	private function render_per_manga_report( $period, $date_from, $date_to ) {
		global $wpdb;
		$table      = $wpdb->prefix . self::TRANSACTIONS_TABLE;
		$where_date = $this->build_date_where( $period, $date_from, $date_to );

		$results = $wpdb->get_results(
			"SELECT reference_id, COUNT(*) as unlock_count, SUM(ABS(amount)) as total_coins
			 FROM {$table}
			 WHERE reference_type = 'chapter_unlock' {$where_date}
			 GROUP BY reference_id
			 ORDER BY total_coins DESC"
		);

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'No data for this period.', 'starter-theme' ) . '</p>';
			return;
		}

		$manga_data = array();
		foreach ( $results as $row ) {
			$parent_id = wp_get_post_parent_id( $row->reference_id );
			if ( ! $parent_id ) {
				$parent_id = $row->reference_id;
			}

			if ( ! isset( $manga_data[ $parent_id ] ) ) {
				$manga_data[ $parent_id ] = array(
					'title'   => get_the_title( $parent_id ),
					'coins'   => 0,
					'unlocks' => 0,
				);
			}
			$manga_data[ $parent_id ]['coins']   += absint( $row->total_coins );
			$manga_data[ $parent_id ]['unlocks'] += absint( $row->unlock_count );
		}

		uasort( $manga_data, function ( $a, $b ) {
			return $b['coins'] - $a['coins'];
		} );

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Manga / Novel', 'starter-theme' ) . '</th>';
		echo '<th>' . esc_html__( 'Coins', 'starter-theme' ) . '</th>';
		echo '<th>' . esc_html__( 'Unlocks', 'starter-theme' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $manga_data as $manga ) {
			echo '<tr>';
			echo '<td>' . esc_html( $manga['title'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $manga['coins'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $manga['unlocks'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------
	 * Frontend assets
	 * ----------------------------------------------------------------*/

	/**
	 * Enqueue frontend scripts and localize AJAX data.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'starter-coin-system',
			get_template_directory_uri() . '/assets/js/coin-system.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script( 'starter-coin-system', 'starterCoins', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'starter_coin_nonce' ),
			'i18n'     => array(
				'confirm_unlock' => __( 'Unlock this chapter?', 'starter-theme' ),
				'insufficient'   => __( 'Insufficient coins.', 'starter-theme' ),
				'error'          => __( 'An error occurred. Please try again.', 'starter-theme' ),
			),
		) );
	}
}
