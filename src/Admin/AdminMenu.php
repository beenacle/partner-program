<?php
/**
 * Top-level admin menu.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	/**
	 * Admin list screens backed by a native WP_List_Table. The slug maps to the
	 * screen class, which exposes configure_screen_options() for the per-page +
	 * column-hide Screen Options panel.
	 *
	 * @var array<string,class-string>
	 */
	private const LIST_SCREENS = [
		'partner-program-affiliates'   => AffiliatesScreen::class,
		'partner-program-applications' => ApplicationsScreen::class,
		'partner-program-commissions'  => CommissionsScreen::class,
		'partner-program-payouts'      => PayoutsScreen::class,
		'partner-program-logs'         => LogsScreen::class,
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// Persist the per-page Screen Options for our native list tables.
		add_filter( 'set-screen-option', [ $this, 'save_screen_option' ], 10, 3 );

		// CPT for marketing materials.
		add_action( 'init', [ $this, 'register_material_cpt' ] );
		// CPT for portal training modules.
		add_action( 'init', [ $this, 'register_module_cpt' ] );
	}

	/**
	 * Save the per-page value for our WP_List_Table screen options.
	 *
	 * @param mixed  $status
	 * @param string $option
	 * @param mixed  $value
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		if ( is_string( $option ) && 0 === strpos( $option, 'pp_' ) && '_per_page' === substr( $option, -9 ) ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $status;
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'partner-program' ) ) {
			return;
		}
		wp_enqueue_style( 'partner-program-components', PARTNER_PROGRAM_URL . 'assets/css/components.css', [], PARTNER_PROGRAM_VERSION );
		wp_enqueue_style( 'partner-program-admin', PARTNER_PROGRAM_URL . 'assets/css/admin.css', [ 'partner-program-components' ], PARTNER_PROGRAM_VERSION );
	}

	public function add_menu(): void {
		$cap = Capabilities::CAP_MANAGE;

		add_menu_page(
			__( 'Partner Program', 'partner-program' ),
			__( 'Partner Program', 'partner-program' ),
			$cap,
			'partner-program',
			[ $this, 'render_dashboard' ],
			'dashicons-groups',
			56
		);

		add_submenu_page( 'partner-program', __( 'Dashboard', 'partner-program' ), __( 'Dashboard', 'partner-program' ), $cap, 'partner-program', [ $this, 'render_dashboard' ] );

		$hooks = [];
		$hooks['partner-program-affiliates']   = add_submenu_page( 'partner-program', __( 'Affiliates', 'partner-program' ), __( 'Affiliates', 'partner-program' ), $cap, 'partner-program-affiliates', [ AffiliatesScreen::class, 'render' ] );
		$hooks['partner-program-applications'] = add_submenu_page( 'partner-program', __( 'Applications', 'partner-program' ), __( 'Applications', 'partner-program' ), $cap, 'partner-program-applications', [ ApplicationsScreen::class, 'render' ] );
		$hooks['partner-program-commissions']  = add_submenu_page( 'partner-program', __( 'Commissions', 'partner-program' ), __( 'Commissions', 'partner-program' ), $cap, 'partner-program-commissions', [ CommissionsScreen::class, 'render' ] );
		$hooks['partner-program-payouts']      = add_submenu_page( 'partner-program', __( 'Payouts', 'partner-program' ), __( 'Payouts', 'partner-program' ), $cap, 'partner-program-payouts', [ PayoutsScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Materials', 'partner-program' ), __( 'Materials', 'partner-program' ), $cap, 'edit.php?post_type=pp_material' );
		add_submenu_page( 'partner-program', __( 'Training Modules', 'partner-program' ), __( 'Training Modules', 'partner-program' ), $cap, 'edit.php?post_type=pp_module' );
		add_submenu_page( 'partner-program', __( 'Compliance', 'partner-program' ), __( 'Compliance', 'partner-program' ), $cap, 'partner-program-compliance', [ ComplianceScreen::class, 'render' ] );
		add_submenu_page( 'partner-program', __( 'Settings', 'partner-program' ), __( 'Settings', 'partner-program' ), $cap, 'partner-program-settings', [ Settings::class, 'render_page' ] );
		$hooks['partner-program-logs']         = add_submenu_page( 'partner-program', __( 'Logs', 'partner-program' ), __( 'Logs', 'partner-program' ), $cap, 'partner-program-logs', [ LogsScreen::class, 'render' ] );

		// Register Screen Options (per-page + column visibility) for each list
		// screen that has been migrated to a native WP_List_Table.
		foreach ( self::LIST_SCREENS as $slug => $screen_class ) {
			$hook = $hooks[ $slug ] ?? '';
			if ( $hook && method_exists( $screen_class, 'configure_screen_options' ) ) {
				add_action( "load-{$hook}", [ $screen_class, 'configure_screen_options' ] );
			}
		}
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		\PartnerProgram\Admin\DashboardScreen::render();
	}

	public function register_material_cpt(): void {
		register_post_type(
			'pp_material',
			[
				'labels'             => [
					'name'          => __( 'Marketing Materials', 'partner-program' ),
					'singular_name' => __( 'Marketing Material', 'partner-program' ),
					'add_new_item'  => __( 'Add new material', 'partner-program' ),
					'edit_item'     => __( 'Edit material', 'partner-program' ),
				],
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				// Dedicated capability_type so a site editor with the default
				// `edit_posts` cap can't suddenly publish/edit partner
				// marketing materials. map_meta_cap routes meta-caps
				// (edit_post, read_post, delete_post) through these primitives.
				'capability_type'    => [ 'pp_material', 'pp_materials' ],
				'map_meta_cap'       => true,
				'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'menu_icon'          => 'dashicons-megaphone',
			]
		);
	}

	public function register_module_cpt(): void {
		register_post_type(
			'pp_module',
			[
				'labels'             => [
					'name'          => __( 'Training Modules', 'partner-program' ),
					'singular_name' => __( 'Training Module', 'partner-program' ),
					'add_new_item'  => __( 'Add new module', 'partner-program' ),
					'edit_item'     => __( 'Edit module', 'partner-program' ),
				],
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				// Off so core doesn't expose published training modules at
				// /wp/v2/pp_module to anonymous readers — this content is meant
				// to live behind the gated portal. Uses the classic editor.
				'show_in_rest'       => false,
				// Dedicated capability_type so partners only ever get read
				// access to training content; matches the pp_material pattern.
				'capability_type'    => [ 'pp_module', 'pp_modules' ],
				'map_meta_cap'       => true,
				// page-attributes adds the menu_order box so merchants can
				// sequence Module 1, Module 2, ... in the portal.
				'supports'           => [ 'title', 'editor', 'excerpt', 'page-attributes' ],
				'menu_icon'          => 'dashicons-welcome-learn-more',
			]
		);
	}
}
