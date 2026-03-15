<?php
/**
 * Custom user roles and capabilities for the manga/novel/video theme.
 *
 * Defines four custom roles (starter_member, starter_author, starter_editor,
 * starter_vip) and provides helpers for role-based content access.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_User_Roles
 */
class Starter_User_Roles {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_User_Roles|null
	 */
	private static $instance = null;

	/**
	 * Role definitions.
	 *
	 * @var array
	 */
	private $roles = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_User_Roles
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
		// Defer define_roles() to init so translated display names are available.
		add_action( 'init', array( $this, 'define_roles' ), 0 );
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'after_switch_theme', array( $this, 'add_roles' ) );
		add_action( 'switch_theme', array( $this, 'maybe_remove_roles' ) );

		// Ensure roles exist on admin_init (handles edge cases like theme update).
		add_action( 'admin_init', array( $this, 'ensure_roles_exist' ) );
	}

	/*------------------------------------------------------------------
	 * Role definitions
	 *-----------------------------------------------------------------*/

	/**
	 * Define the custom roles and their capabilities.
	 *
	 * @return void
	 */
	public function define_roles() {
		// Base member capabilities.
		$member_caps = array(
			'read'                       => true,
			'starter_read_manga'         => true,
			'starter_read_novel'         => true,
			'starter_watch_video'        => true,
			'starter_rate'               => true,
			'starter_bookmark'           => true,
			'starter_comment'            => true,
			'starter_edit_own_profile'   => true,
		);

		// Author: all member caps + upload/manage own content.
		$author_caps = array_merge( $member_caps, array(
			'starter_upload_manga'       => true,
			'starter_upload_novel'       => true,
			'starter_manage_own_chapters' => true,
			'starter_edit_own_manga'     => true,
			'starter_edit_own_novel'     => true,
			'starter_delete_own_manga'   => true,
			'starter_delete_own_novel'   => true,
			'upload_files'               => true,
		) );

		// Editor: all author caps + edit any content, approve submissions.
		$editor_caps = array_merge( $author_caps, array(
			'starter_edit_any_manga'     => true,
			'starter_edit_any_novel'     => true,
			'starter_delete_any_manga'   => true,
			'starter_delete_any_novel'   => true,
			'starter_approve_submissions' => true,
			'starter_manage_any_chapters' => true,
			'moderate_comments'          => true,
		) );

		// VIP: all member caps + access premium/coin content for free.
		$vip_caps = array_merge( $member_caps, array(
			'starter_access_premium'     => true,
			'starter_access_coin_chapters' => true,
			'starter_vip_badge'          => true,
		) );

		$this->roles = array(
			'starter_member' => array(
				'display_name' => __( 'Member', 'starter-theme' ),
				'capabilities' => $member_caps,
			),
			'starter_author' => array(
				'display_name' => __( 'Author', 'starter-theme' ),
				'capabilities' => $author_caps,
			),
			'starter_editor' => array(
				'display_name' => __( 'Editor', 'starter-theme' ),
				'capabilities' => $editor_caps,
			),
			'starter_vip' => array(
				'display_name' => __( 'VIP', 'starter-theme' ),
				'capabilities' => $vip_caps,
			),
		);

		/**
		 * Filter custom role definitions.
		 *
		 * @since 1.0.0
		 *
		 * @param array $roles Role slug => definition array.
		 */
		$this->roles = apply_filters( 'starter_user_roles', $this->roles );
	}

	/*------------------------------------------------------------------
	 * Role management
	 *-----------------------------------------------------------------*/

	/**
	 * Add custom roles on theme activation.
	 *
	 * @return void
	 */
	public function add_roles() {
		foreach ( $this->roles as $role_slug => $role_data ) {
			// Remove first to update capabilities on re-activation.
			remove_role( $role_slug );
			add_role( $role_slug, $role_data['display_name'], $role_data['capabilities'] );
		}

		// Grant custom capabilities to the built-in administrator role.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $this->get_all_custom_capabilities() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Store a flag so we know roles were installed.
		update_option( 'starter_roles_version', STARTER_THEME_VERSION );
	}

	/**
	 * Ensure roles exist (run on admin_init).
	 *
	 * If the stored roles version does not match the theme version,
	 * re-install roles to pick up any changes.
	 *
	 * @return void
	 */
	public function ensure_roles_exist() {
		$installed_version = get_option( 'starter_roles_version', '' );

		if ( $installed_version !== STARTER_THEME_VERSION ) {
			$this->add_roles();
		}
	}

	/**
	 * Optionally remove custom roles on theme deactivation.
	 *
	 * Controlled by the 'starter_remove_roles_on_deactivation' filter.
	 * Defaults to false (keep roles).
	 *
	 * @return void
	 */
	public function maybe_remove_roles() {
		/**
		 * Filter whether to remove custom roles when the theme is deactivated.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $remove Whether to remove roles. Default false.
		 */
		$should_remove = apply_filters( 'starter_remove_roles_on_deactivation', false );

		if ( ! $should_remove ) {
			return;
		}

		foreach ( array_keys( $this->roles ) as $role_slug ) {
			// Reassign users to default WordPress subscriber role.
			$users = get_users( array( 'role' => $role_slug ) );
			foreach ( $users as $user ) {
				$user->set_role( 'subscriber' );
			}
			remove_role( $role_slug );
		}

		// Remove custom caps from administrator.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $this->get_all_custom_capabilities() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		delete_option( 'starter_roles_version' );
	}

	/*------------------------------------------------------------------
	 * Capability helpers
	 *-----------------------------------------------------------------*/

	/**
	 * Get all custom capabilities across all roles.
	 *
	 * @return array Flat array of unique capability names.
	 */
	private function get_all_custom_capabilities() {
		$caps = array();

		foreach ( $this->roles as $role_data ) {
			foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
				if ( 0 === strpos( $cap, 'starter_' ) ) {
					$caps[] = $cap;
				}
			}
		}

		return array_unique( $caps );
	}

	/**
	 * Get the default role assigned on registration.
	 *
	 * @return string Role slug.
	 */
	public static function get_default_role() {
		/**
		 * Filter the default role assigned to new registrations.
		 *
		 * @since 1.0.0
		 *
		 * @param string $role Default role slug.
		 */
		return apply_filters( 'starter_default_registration_role', 'starter_member' );
	}

	/**
	 * Check if the current user (or a given user) can read a specific chapter.
	 *
	 * Checks:
	 * 1. Chapter is free => anyone with starter_read_manga.
	 * 2. Chapter is premium => user needs starter_access_premium or admin.
	 * 3. Chapter is coin-locked => user needs starter_access_coin_chapters, or
	 *    has purchased/unlocked the chapter, or is admin.
	 *
	 * @param int $chapter_id Chapter post ID.
	 * @param int $user_id    Optional. Defaults to current user.
	 * @return bool
	 */
	public static function current_user_can_read_chapter( $chapter_id, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Administrators can always read.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Must be logged in and have basic read capability.
		if ( ! $user_id || ! user_can( $user_id, 'starter_read_manga' ) ) {
			/**
			 * Filter whether non-logged-in users can read free chapters.
			 *
			 * @since 1.0.0
			 *
			 * @param bool $allow    Whether to allow. Default false.
			 * @param int  $chapter_id Chapter post ID.
			 */
			$allow_guest = apply_filters( 'starter_allow_guest_read', false, $chapter_id );

			if ( $allow_guest ) {
				// Check if chapter is free.
				$price_type = get_post_meta( $chapter_id, 'starter_chapter_price_type', true );
				return empty( $price_type ) || 'free' === $price_type;
			}

			return false;
		}

		$price_type = get_post_meta( $chapter_id, 'starter_chapter_price_type', true );

		// Free chapter.
		if ( empty( $price_type ) || 'free' === $price_type ) {
			return true;
		}

		// Premium chapter.
		if ( 'premium' === $price_type ) {
			return user_can( $user_id, 'starter_access_premium' );
		}

		// Coin-locked chapter.
		if ( 'coin' === $price_type ) {
			// VIP users get free access.
			if ( user_can( $user_id, 'starter_access_coin_chapters' ) ) {
				return true;
			}

			// Check if user has purchased/unlocked this chapter.
			$unlocked = get_user_meta( $user_id, 'starter_unlocked_chapters', true );
			if ( is_array( $unlocked ) && in_array( $chapter_id, $unlocked, true ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Filter chapter access for custom price types.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $can_read   Whether user can read. Default false.
		 * @param int    $chapter_id Chapter post ID.
		 * @param int    $user_id    User ID.
		 * @param string $price_type Price type slug.
		 */
		return apply_filters( 'starter_can_read_chapter', false, $chapter_id, $user_id, $price_type );
	}

	/**
	 * Check if a user has a specific custom role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug.
	 * @return bool
	 */
	public static function user_has_role( $user_id, $role ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Check if a user can manage a specific manga post (owner, editor, or admin).
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Optional. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage_post( $post_id, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		// Admins can manage everything.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Editors can manage any manga/novel.
		if ( user_can( $user_id, 'starter_edit_any_manga' ) ) {
			return true;
		}

		// Authors can manage their own posts.
		$post = get_post( $post_id );
		if ( $post && (int) $post->post_author === $user_id ) {
			if ( user_can( $user_id, 'starter_edit_own_manga' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all custom role definitions.
	 *
	 * @return array
	 */
	public function get_roles() {
		return $this->roles;
	}
}
