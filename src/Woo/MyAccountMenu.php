<?php
/**
 * Adds a "Partner Portal" (or "Become a Partner") item to the WooCommerce
 * My Account navigation, shown to the right audience and toggleable in
 * Partner Program → Settings → General.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Woo;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class MyAccountMenu {

	private const KEY_PORTAL = 'pp-portal';
	private const KEY_APPLY  = 'pp-apply';

	public function register(): void {
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_items' ], 20 );
		add_filter( 'woocommerce_get_endpoint_url', [ $this, 'item_url' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_items( $items ): array {
		$items    = is_array( $items ) ? $items : [];
		$settings = new SettingsRepo();
		if ( ! (bool) $settings->get( 'account_menu.enabled', true ) ) {
			return $items;
		}

		$affiliate  = is_user_logged_in() ? AffiliateRepo::find_by_user( get_current_user_id() ) : null;
		$is_partner = $affiliate && 'approved' === $affiliate['status'];

		// Non-partners only get an item if the "Become a Partner" link is on.
		if ( ! $is_partner && ! (bool) $settings->get( 'account_menu.show_apply', false ) ) {
			return $items;
		}

		// Keep the Logout item last.
		$logout = [];
		if ( isset( $items['customer-logout'] ) ) {
			$logout = [ 'customer-logout' => $items['customer-logout'] ];
			unset( $items['customer-logout'] );
		}

		if ( $is_partner ) {
			$items[ self::KEY_PORTAL ] = (string) $settings->get( 'account_menu.label', __( 'Partner Portal', 'partner-program' ) );
		} else {
			$items[ self::KEY_APPLY ] = (string) $settings->get( 'account_menu.apply_label', __( 'Become a Partner', 'partner-program' ) );
		}

		return $items + $logout;
	}

	/**
	 * Our menu keys aren't WooCommerce endpoints, so point them at the real
	 * portal / application pages.
	 *
	 * @param string $url
	 * @param string $endpoint
	 * @return string
	 */
	public function item_url( $url, $endpoint ) {
		if ( self::KEY_PORTAL === $endpoint ) {
			$id = (int) get_option( 'partner_program_portal_page_id' );
			return $id ? get_permalink( $id ) : home_url( '/partner-portal/' );
		}
		if ( self::KEY_APPLY === $endpoint ) {
			$id = (int) get_option( 'partner_program_application_page_id' );
			return $id ? get_permalink( $id ) : home_url( '/partner-application/' );
		}
		return $url;
	}
}
