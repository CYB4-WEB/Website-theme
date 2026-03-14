<?php
/**
 * Manga Chapter Management.
 *
 * Handles chapter storage, CRUD operations, navigation, and pagination
 * using a custom database table.
 *
 * @package starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Chapter
 *
 * Manages manga chapters stored in a custom database table.
 *
 * @since 1.0.0
 */
class Starter_Manga_Chapter {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'manga_chapters';

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Chapter|null
	 */
	private static $instance = null;

	/**
	 * Full table name with prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Chapter
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;

		add_action( 'wp_ajax_starter_load_chapters', array( $this, 'ajax_load_chapters' ) );
		add_action( 'wp_ajax_nopriv_starter_load_chapters', array( $this, 'ajax_load_chapters' ) );
		add_action( 'wp_ajax_starter_count_chapter_view', array( $this, 'ajax_count_chapter_view' ) );
		add_action( 'wp_ajax_nopriv_starter_count_chapter_view', array( $this, 'ajax_count_chapter_view' ) );
	}

	/**
	 * Create the chapters table using dbDelta.
	 *
	 * Should be called on theme activation.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manga_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chapter_number varchar(20) NOT NULL DEFAULT '',
			chapter_name varchar(255) NOT NULL DEFAULT '',
			chapter_extend_name varchar(255) NOT NULL DEFAULT '',
			volume varchar(50) NOT NULL DEFAULT '',
			chapter_type varchar(20) NOT NULL DEFAULT 'image',
			chapter_data longtext NOT NULL,
			chapter_thumbnail varchar(500) NOT NULL DEFAULT '',
			chapter_seo_title varchar(255) NOT NULL DEFAULT '',
			chapter_seo_description text NOT NULL,
			chapter_warning text NOT NULL,
			chapter_status varchar(20) NOT NULL DEFAULT 'publish',
			chapter_coin int(11) NOT NULL DEFAULT 0,
			chapter_permission text NOT NULL,
			scheduled_date datetime DEFAULT NULL,
			date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			order_index bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY manga_id (manga_id),
			KEY chapter_status (chapter_status),
			KEY order_index (order_index),
			KEY volume (volume),
			KEY manga_status (manga_id, chapter_status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get the full table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		return $this->table;
	}

	/**
	 * Add a new chapter.
	 *
	 * @param array $data Chapter data.
	 * @return int|false Inserted chapter ID or false on failure.
	 */
	public function add_chapter( $data ) {
		global $wpdb;

		$defaults = array(
			'manga_id'              => 0,
			'chapter_number'        => '',
			'chapter_name'          => '',
			'chapter_extend_name'   => '',
			'volume'                => '',
			'chapter_type'          => 'image',
			'chapter_data'          => '[]',
			'chapter_thumbnail'     => '',
			'chapter_seo_title'     => '',
			'chapter_seo_description' => '',
			'chapter_warning'       => '',
			'chapter_status'        => 'publish',
			'chapter_coin'          => 0,
			'chapter_permission'    => '',
			'scheduled_date'        => null,
			'date_created'          => current_time( 'mysql' ),
			'date_modified'         => current_time( 'mysql' ),
			'views'                 => 0,
			'order_index'           => 0,
		);

		$data = wp_parse_args( $data, $defaults );
		$data = $this->sanitize_chapter_data( $data );

		$formats = array(
			'%d', // manga_id
			'%s', // chapter_number
			'%s', // chapter_name
			'%s', // chapter_extend_name
			'%s', // volume
			'%s', // chapter_type
			'%s', // chapter_data
			'%s', // chapter_thumbnail
			'%s', // chapter_seo_title
			'%s', // chapter_seo_description
			'%s', // chapter_warning
			'%s', // chapter_status
			'%d', // chapter_coin
			'%s', // chapter_permission
			'%s', // scheduled_date
			'%s', // date_created
			'%s', // date_modified
			'%d', // views
			'%d', // order_index
		);

		$result = $wpdb->insert( $this->table, $data, $formats );

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update an existing chapter.
	 *
	 * @param int   $chapter_id Chapter ID.
	 * @param array $data       Data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_chapter( $chapter_id, $data ) {
		global $wpdb;

		$chapter_id = absint( $chapter_id );
		if ( ! $chapter_id ) {
			return false;
		}

		$data['date_modified'] = current_time( 'mysql' );
		$data = $this->sanitize_chapter_data( $data );

		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'manga_id', 'chapter_coin', 'views', 'order_index' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $chapter_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a chapter.
	 *
	 * @param int $chapter_id Chapter ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_chapter( $chapter_id ) {
		global $wpdb;

		$chapter_id = absint( $chapter_id );
		if ( ! $chapter_id ) {
			return false;
		}

		$result = $wpdb->delete(
			$this->table,
			array( 'id' => $chapter_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get a single chapter by ID.
	 *
	 * @param int $chapter_id Chapter ID.
	 * @return object|null Chapter row or null.
	 */
	public function get_chapter( $chapter_id ) {
		global $wpdb;

		$chapter_id = absint( $chapter_id );
		if ( ! $chapter_id ) {
			return null;
		}

		$chapter = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$chapter_id
			)
		);

		return $chapter;
	}

	/**
	 * Get chapters for a specific manga.
	 *
	 * @param int    $manga_id Manga post ID.
	 * @param array  $args     Optional. Query arguments.
	 * @return array Array of chapter objects.
	 */
	public function get_chapters_by_manga( $manga_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => 'publish',
			'orderby'  => 'order_index',
			'order'    => 'DESC',
			'limit'    => -1,
			'offset'   => 0,
			'volume'   => '',
		);

		$args     = wp_parse_args( $args, $defaults );
		$manga_id = absint( $manga_id );

		$where = $wpdb->prepare( "WHERE manga_id = %d", $manga_id );

		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( " AND chapter_status = %s", sanitize_text_field( $args['status'] ) );
		}

		if ( ! empty( $args['volume'] ) ) {
			$where .= $wpdb->prepare( " AND volume = %s", sanitize_text_field( $args['volume'] ) );
		}

		$allowed_orderby = array( 'order_index', 'chapter_number', 'date_created', 'views', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'order_index';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM {$this->table} {$where} ORDER BY {$orderby} {$order}";

		if ( $args['limit'] > 0 ) {
			$sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", absint( $args['limit'] ), absint( $args['offset'] ) );
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count chapters for a manga.
	 *
	 * @param int    $manga_id Manga post ID.
	 * @param string $status   Chapter status. Empty for all.
	 * @return int Chapter count.
	 */
	public function count_chapters( $manga_id, $status = 'publish' ) {
		global $wpdb;

		$manga_id = absint( $manga_id );
		$where    = $wpdb->prepare( "WHERE manga_id = %d", $manga_id );

		if ( ! empty( $status ) ) {
			$where .= $wpdb->prepare( " AND chapter_status = %s", sanitize_text_field( $status ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get the previous chapter.
	 *
	 * @param int $chapter_id  Current chapter ID.
	 * @param int $manga_id    Manga post ID.
	 * @return object|null Previous chapter or null.
	 */
	public function get_prev_chapter( $chapter_id, $manga_id ) {
		global $wpdb;

		$current = $this->get_chapter( $chapter_id );
		if ( ! $current ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE manga_id = %d
				AND chapter_status = 'publish'
				AND order_index < %d
				ORDER BY order_index DESC
				LIMIT 1",
				absint( $manga_id ),
				$current->order_index
			)
		);
	}

	/**
	 * Get the next chapter.
	 *
	 * @param int $chapter_id  Current chapter ID.
	 * @param int $manga_id    Manga post ID.
	 * @return object|null Next chapter or null.
	 */
	public function get_next_chapter( $chapter_id, $manga_id ) {
		global $wpdb;

		$current = $this->get_chapter( $chapter_id );
		if ( ! $current ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE manga_id = %d
				AND chapter_status = 'publish'
				AND order_index > %d
				ORDER BY order_index ASC
				LIMIT 1",
				absint( $manga_id ),
				$current->order_index
			)
		);
	}

	/**
	 * Get volumes for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return array Array of distinct volume names.
	 */
	public function get_volumes( $manga_id ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT volume FROM {$this->table}
				WHERE manga_id = %d AND volume != '' AND chapter_status = 'publish'
				ORDER BY volume ASC",
				absint( $manga_id )
			)
		);
	}

	/**
	 * Increment chapter views with IP-based deduplication.
	 *
	 * @param int $chapter_id Chapter ID.
	 * @return bool True if view was counted, false if duplicate.
	 */
	public function count_view( $chapter_id ) {
		global $wpdb;

		$chapter_id = absint( $chapter_id );
		if ( ! $chapter_id ) {
			return false;
		}

		$ip_address    = $this->get_client_ip();
		$transient_key = 'starter_chview_' . md5( $chapter_id . '_' . $ip_address );

		// Check if this IP already viewed this chapter recently.
		if ( false !== get_transient( $transient_key ) ) {
			return false;
		}

		// Set transient to prevent duplicate views for 1 hour.
		set_transient( $transient_key, 1, HOUR_IN_SECONDS );

		// Increment view count.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET views = views + 1 WHERE id = %d",
				$chapter_id
			)
		);

		return true;
	}

	/**
	 * AJAX endpoint: Load chapters with pagination.
	 *
	 * @return void
	 */
	public function ajax_load_chapters() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$manga_id = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50;
		$order    = isset( $_POST['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) ? 'ASC' : 'DESC';
		$volume   = isset( $_POST['volume'] ) ? sanitize_text_field( wp_unslash( $_POST['volume'] ) ) : '';

		if ( ! $manga_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid manga ID.', 'starter' ) ) );
		}

		$offset   = ( $page - 1 ) * $per_page;
		$chapters = $this->get_chapters_by_manga( $manga_id, array(
			'limit'  => $per_page,
			'offset' => $offset,
			'order'  => $order,
			'volume' => $volume,
		) );

		$total    = $this->count_chapters( $manga_id );
		$has_more = ( $offset + $per_page ) < $total;

		// Get global chapter message.
		$global_message = get_option( 'starter_chapter_global_message', '' );

		$chapter_list = array();
		foreach ( $chapters as $chapter ) {
			$chapter_list[] = array(
				'id'             => (int) $chapter->id,
				'number'         => $chapter->chapter_number,
				'name'           => $chapter->chapter_name,
				'extend_name'    => $chapter->chapter_extend_name,
				'volume'         => $chapter->volume,
				'type'           => $chapter->chapter_type,
				'status'         => $chapter->chapter_status,
				'coin'           => (int) $chapter->chapter_coin,
				'date_created'   => $chapter->date_created,
				'views'          => (int) $chapter->views,
				'url'            => $this->get_chapter_url( $manga_id, $chapter ),
			);
		}

		wp_send_json_success( array(
			'chapters'       => $chapter_list,
			'total'          => $total,
			'has_more'       => $has_more,
			'page'           => $page,
			'global_message' => wp_kses_post( $global_message ),
		) );
	}

	/**
	 * AJAX endpoint: Count chapter view.
	 *
	 * @return void
	 */
	public function ajax_count_chapter_view() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;
		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid chapter ID.', 'starter' ) ) );
		}

		$counted = $this->count_view( $chapter_id );

		wp_send_json_success( array( 'counted' => $counted ) );
	}

	/**
	 * Build the URL for a chapter.
	 *
	 * @param int    $manga_id Manga post ID.
	 * @param object $chapter  Chapter object.
	 * @return string Chapter URL.
	 */
	public function get_chapter_url( $manga_id, $chapter ) {
		$manga_slug = get_post_field( 'post_name', $manga_id );
		$chapter_slug = 'chapter-' . $chapter->chapter_number;

		return home_url( '/manga/' . $manga_slug . '/' . $chapter_slug . '/' );
	}

	/**
	 * Sanitize chapter data array.
	 *
	 * @param array $data Raw chapter data.
	 * @return array Sanitized data.
	 */
	private function sanitize_chapter_data( $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				case 'manga_id':
				case 'chapter_coin':
				case 'views':
				case 'order_index':
					$sanitized[ $key ] = absint( $value );
					break;

				case 'chapter_data':
					// Validate JSON.
					if ( is_array( $value ) ) {
						$sanitized[ $key ] = wp_json_encode( $value );
					} else {
						$decoded = json_decode( $value, true );
						$sanitized[ $key ] = ( null !== $decoded ) ? wp_json_encode( $decoded ) : '[]';
					}
					break;

				case 'chapter_type':
					$allowed_types     = array( 'image', 'text', 'video' );
					$sanitized[ $key ] = in_array( $value, $allowed_types, true ) ? $value : 'image';
					break;

				case 'chapter_status':
					$allowed_statuses  = array( 'publish', 'pending', 'scheduled', 'draft' );
					$sanitized[ $key ] = in_array( $value, $allowed_statuses, true ) ? $value : 'draft';
					break;

				case 'chapter_seo_description':
				case 'chapter_warning':
					$sanitized[ $key ] = sanitize_textarea_field( $value );
					break;

				case 'chapter_permission':
					if ( is_array( $value ) ) {
						$sanitized[ $key ] = maybe_serialize( array_map( 'sanitize_text_field', $value ) );
					} else {
						$sanitized[ $key ] = sanitize_text_field( $value );
					}
					break;

				case 'scheduled_date':
				case 'date_created':
				case 'date_modified':
					$sanitized[ $key ] = ( null === $value || '' === $value ) ? null : sanitize_text_field( $value );
					break;

				case 'chapter_thumbnail':
					$sanitized[ $key ] = esc_url_raw( $value );
					break;

				default:
					$sanitized[ $key ] = sanitize_text_field( $value );
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Get the latest chapter for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return object|null Latest chapter or null.
	 */
	public function get_latest_chapter( $manga_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE manga_id = %d AND chapter_status = 'publish'
				ORDER BY order_index DESC
				LIMIT 1",
				absint( $manga_id )
			)
		);
	}

	/**
	 * Get the first chapter for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return object|null First chapter or null.
	 */
	public function get_first_chapter( $manga_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE manga_id = %d AND chapter_status = 'publish'
				ORDER BY order_index ASC
				LIMIT 1",
				absint( $manga_id )
			)
		);
	}
}
