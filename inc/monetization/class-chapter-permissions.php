<?php
/**
 * Chapter Permission Checker.
 *
 * Decides whether the current user may read a chapter based on:
 *   - Premium flag + coin balance
 *   - VIP role
 *   - Chapter unlock records
 *   - Admin / uploader bypass
 *
 * @package starter-theme
 * @subpackage Monetization
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Chapter_Permissions
 */
class Starter_Chapter_Permissions {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Chapter_Permissions|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Chapter_Permissions
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		add_action( 'wp_ajax_starter_unlock_chapter',        array( $this, 'ajax_unlock_chapter' ) );
		add_action( 'wp_ajax_starter_check_chapter_access',  array( $this, 'ajax_check_access' ) );
		add_filter( 'starter_before_chapter_load',           array( $this, 'gate_chapter_load' ), 10, 2 );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Public API
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Check whether a user may access a chapter.
	 *
	 * @param int      $chapter_id  Chapter row ID (from custom table).
	 * @param int|null $user_id     WP user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_access( $chapter_id, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		/* Admins and uploaders always have access. */
		if ( $user_id && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_manga_chapters' ) ) ) {
			return true;
		}

		$chapter = $this->get_chapter( $chapter_id );

		if ( ! $chapter ) {
			return false;
		}

		/* Free chapter — everyone can read. */
		if ( ! $chapter->is_premium || (int) $chapter->coin_price <= 0 ) {
			return true;
		}

		/* Guest — no access to premium. */
		if ( ! $user_id ) {
			return false;
		}

		/* VIP readers have global access. */
		if ( user_can( $user_id, 'access_premium_chapters' ) ) {
			return true;
		}

		/* Check if user already unlocked this chapter. */
		if ( $this->is_chapter_unlocked( $chapter_id, $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Unlock a chapter for a user (deduct coins).
	 *
	 * @param int $chapter_id Chapter row ID.
	 * @param int $user_id    WP user ID.
	 * @return true|\WP_Error
	 */
	public function unlock_chapter( $chapter_id, $user_id ) {
		$chapter = $this->get_chapter( $chapter_id );

		if ( ! $chapter ) {
			return new WP_Error( 'not_found', __( 'Chapter not found.', 'starter-theme' ) );
		}

		if ( ! $chapter->is_premium ) {
			return true; /* Already free */
		}

		$price = (int) $chapter->coin_price;

		/* Deduct coins via Starter_Coin_System if available */
		if ( class_exists( 'Starter_Coin_System' ) ) {
			$coin_system = Starter_Coin_System::get_instance();
			$balance     = $coin_system->get_balance( $user_id );

			if ( $balance < $price ) {
				return new WP_Error(
					'insufficient_coins',
					sprintf(
						/* translators: 1: required, 2: available */
						__( 'You need %1$d coins but only have %2$d.', 'starter-theme' ),
						$price,
						$balance
					)
				);
			}

			$deducted = $coin_system->deduct( $user_id, $price, 'chapter_unlock', $chapter_id );

			if ( is_wp_error( $deducted ) ) {
				return $deducted;
			}
		}

		/* Record the unlock. */
		$this->record_unlock( $chapter_id, $user_id );

		return true;
	}

	/**
	 * Get access denial data (for locked-chapter UI).
	 *
	 * @param int      $chapter_id Chapter row ID.
	 * @param int|null $user_id    WP user ID.
	 * @return array { locked: bool, price: int, balance: int, login_required: bool }
	 */
	public function get_access_data( $chapter_id, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$chapter = $this->get_chapter( $chapter_id );
		$price   = $chapter ? (int) $chapter->coin_price : 0;
		$balance = 0;

		if ( $user_id && class_exists( 'Starter_Coin_System' ) ) {
			$balance = Starter_Coin_System::get_instance()->get_balance( $user_id );
		}

		return array(
			'locked'         => ! $this->can_access( $chapter_id, $user_id ),
			'price'          => $price,
			'balance'        => $balance,
			'login_required' => ! $user_id,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Filter callback
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Gate chapter loading in the reader template.
	 *
	 * @param bool  $continue    Whether to continue loading.
	 * @param array $chapter_ctx Context: chapter_id, manga_id.
	 * @return bool|\WP_Error
	 */
	public function gate_chapter_load( $continue, $chapter_ctx ) {
		if ( ! $continue ) {
			return $continue;
		}

		$chapter_id = isset( $chapter_ctx['chapter_id'] ) ? (int) $chapter_ctx['chapter_id'] : 0;

		if ( ! $chapter_id || $this->can_access( $chapter_id ) ) {
			return true;
		}

		return new WP_Error( 'access_denied', __( 'This chapter requires coins to unlock.', 'starter-theme' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * AJAX handlers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Unlock a chapter with coins.
	 */
	public function ajax_unlock_chapter() {
		check_ajax_referer( 'starter_coins_nonce', 'nonce' );

		$user_id    = get_current_user_id();
		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'starter-theme' ) ) );
		}

		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid chapter.', 'starter-theme' ) ) );
		}

		$result = $this->unlock_chapter( $chapter_id, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Chapter unlocked! Enjoy reading.', 'starter-theme' ),
			'balance' => class_exists( 'Starter_Coin_System' )
				? Starter_Coin_System::get_instance()->get_balance( $user_id )
				: 0,
		) );
	}

	/**
	 * AJAX: Check whether current user can access a chapter.
	 */
	public function ajax_check_access() {
		check_ajax_referer( 'starter_reader_nonce', 'nonce' );

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid chapter.', 'starter-theme' ) ) );
		}

		$data = $this->get_access_data( $chapter_id );
		wp_send_json_success( $data );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Private helpers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Fetch a chapter row from the custom table.
	 *
	 * @param int $chapter_id
	 * @return object|null
	 */
	private function get_chapter( $chapter_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'starter_chapters';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, manga_id, is_premium, coin_price FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$chapter_id
		) );
	}

	/**
	 * Check if a user has already unlocked a chapter.
	 *
	 * @param int $chapter_id
	 * @param int $user_id
	 * @return bool
	 */
	private function is_chapter_unlocked( $chapter_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'starter_chapter_unlocks';

		/* If the unlocks table doesn't exist, fall back to user meta.
		 * Use INFORMATION_SCHEMA with a fully prepared query — avoids LIKE injection. */
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
			$table
		) );
		if ( ! $table_exists ) {
			$unlocked = get_user_meta( $user_id, '_unlocked_chapters', true );
			return is_array( $unlocked ) && in_array( (int) $chapter_id, $unlocked, true );
		}

		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE chapter_id = %d AND user_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$chapter_id,
			$user_id
		) );

		return ! empty( $row );
	}

	/**
	 * Record a chapter unlock for a user.
	 *
	 * @param int $chapter_id
	 * @param int $user_id
	 */
	private function record_unlock( $chapter_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'starter_chapter_unlocks';

		$table_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
			$table
		) );
		if ( ! $table_exists ) {
			/* Fallback: store in user meta */
			$unlocked   = get_user_meta( $user_id, '_unlocked_chapters', true );
			$unlocked   = is_array( $unlocked ) ? $unlocked : array();
			$unlocked[] = (int) $chapter_id;
			update_user_meta( $user_id, '_unlocked_chapters', array_unique( $unlocked ) );
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'chapter_id'  => $chapter_id,
				'user_id'     => $user_id,
				'unlocked_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);
	}
}

/* Auto-instantiate. */
Starter_Chapter_Permissions::get_instance();
