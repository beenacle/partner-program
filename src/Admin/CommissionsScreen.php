<?php
/**
 * Admin commissions list with bulk actions.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Admin\Tables\CommissionsTable;
use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\Ui;

defined( 'ABSPATH' ) || exit;

final class CommissionsScreen {

	/**
	 * Registered on the screen's load hook (see AdminMenu) so the per-page and
	 * column-visibility Screen Options panel works natively.
	 */
	public static function configure_screen_options(): void {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Commissions per page', 'partner-program' ),
				'default' => 100,
				'option'  => 'pp_commissions_per_page',
			]
		);
		( new CommissionsTable() )->register_screen_columns();
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::handle_bulk();
		self::handle_manual_adjustment();

		$list = new CommissionsTable();
		$list->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html__( 'Commissions', 'partner-program' ) . '</h1>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'partner-program' ) . '</p></div>';
		}

		$list->views();

		// The native bulk-action controls + the 'bulk-commissions' nonce are
		// rendered by $list->display() inside this form; handle_bulk() reads them.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=partner-program-commissions' ) ) . '">';
		$list->display();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Manual adjustment', 'partner-program' ) . '</h2>';
		echo '<form method="post"><input type="hidden" name="manual_adjustment" value="1" />';
		wp_nonce_field( 'pp_manual_adjustment' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Affiliate ID', 'partner-program' ) . '</th><td><input type="number" name="affiliate_id" required /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Amount (e.g. 25 or -10)', 'partner-program' ) . '</th><td><input type="number" step="0.01" name="amount" required /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Notes', 'partner-program' ) . '</th><td><input type="text" name="notes" class="regular-text" /></td></tr>';
		echo '</table>';
		submit_button( __( 'Add manual adjustment', 'partner-program' ) );
		echo '</form>';

		echo '</div>';
	}

	private static function handle_bulk(): void {
		// Native WP_List_Table bulk submit: the selected action is in `action`
		// (top control) or `action2` (bottom); row ids are in ids[].
		$action = '';
		if ( isset( $_POST['action'] ) && '-1' !== (string) $_POST['action'] ) {
			$action = sanitize_key( (string) $_POST['action'] );
		} elseif ( isset( $_POST['action2'] ) && '-1' !== (string) $_POST['action2'] ) {
			$action = sanitize_key( (string) $_POST['action2'] );
		}
		if ( '' === $action || empty( $_POST['ids'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-commissions' );
		$ids = array_map( 'intval', (array) $_POST['ids'] );
		$map    = [ 'approve' => 'approved', 'reject' => 'rejected', 'clawback' => 'clawback' ];
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		// `partner_program_commission_<status>` lets integrations
		// (notification emails, accounting exports) react to admin-driven
		// changes the same way they react to cron-driven ones.
		$action_map = [
			'approved' => 'partner_program_commission_approved',
			'rejected' => 'partner_program_commission_rejected',
			'clawback' => 'partner_program_commission_clawback',
		];
		$new_status = $map[ $action ];
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$before = CommissionRepo::find( $id );
			if ( ! $before || $before['status'] === $new_status ) {
				continue; // No-op: skip the row + its action.
			}
			// Never silently mutate a commission that was already paid out (or
			// already claimed into a queued payout). Overwriting its status
			// desyncs the payout ledger and corrupts paid totals with no audit
			// trail. Reverse the payout first if it really needs clawing back.
			if ( 'paid' === $before['status'] || ! empty( $before['payout_id'] ) ) {
				continue;
			}
			CommissionRepo::update( $id, [ 'status' => $new_status ] );
			if ( isset( $action_map[ $new_status ] ) ) {
				do_action( $action_map[ $new_status ], $id );
			}
		}
		wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-commissions' ) ) );
		exit;
	}

	private static function handle_manual_adjustment(): void {
		if ( empty( $_POST['manual_adjustment'] ) ) {
			return;
		}
		check_admin_referer( 'pp_manual_adjustment' );
		$affiliate_id = (int) ( $_POST['affiliate_id'] ?? 0 );
		$amount       = (float) ( $_POST['amount'] ?? 0 );
		$notes        = sanitize_text_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) );
		if ( ! $affiliate_id || 0.0 === $amount ) {
			return;
		}
		// Refuse adjustments against a non-existent affiliate id so we don't
		// leave orphan commission rows that no UI can clean up.
		if ( ! AffiliateRepo::find( $affiliate_id ) ) {
			wp_safe_redirect( add_query_arg( 'done', 0, admin_url( 'admin.php?page=partner-program-commissions' ) ) );
			exit;
		}
		$commission_id = CommissionRepo::create(
			[
				'affiliate_id'      => $affiliate_id,
				// NULL keeps each adjustment independent under the unique
				// (order_id) index; real orders are still 1-row-per-order.
				'order_id'          => null,
				'base_amount_cents' => 0,
				'rate'              => 0,
				'amount_cents'      => Money::to_cents( $amount ),
				'currency'          => get_woocommerce_currency() ?: 'USD',
				'status'            => 'approved',
				'source'            => 'adjustment',
				'notes'             => $notes ?: 'Manual adjustment',
			]
		);
		// Adjustments skip the engine (no order_id) so we fire the
		// "recorded" + "approved" actions ourselves; integrations that
		// listen for either one see manual entries too.
		if ( $commission_id > 0 ) {
			do_action( 'partner_program_commission_recorded', $commission_id, $affiliate_id, 0 );
			do_action( 'partner_program_commission_approved', $commission_id );
		}
		wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=partner-program-commissions' ) ) );
		exit;
	}
}
