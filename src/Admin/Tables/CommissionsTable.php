<?php
/**
 * Native WP_List_Table for the admin commissions list.
 *
 * Mirrors the previous hand-rolled table in CommissionsScreen exactly: same
 * columns, same per-row data, same money/status formatting, and the same data
 * source (CommissionRepo::count()/::search() with the status filter). Bulk
 * actions are native: get_bulk_actions() returns approve/reject/clawback,
 * submitted through core's action/action2 controls plus the ids[] checkboxes
 * from column_cb(), and processed by CommissionsScreen::handle_bulk() against
 * the 'bulk-commissions' nonce that WP_List_Table::display() emits.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class CommissionsTable extends AbstractListTable {

	/**
	 * Affiliate rows keyed by affiliate_id, resolved in bulk in prepare_items()
	 * so each row render avoids a per-row query (same as the original screen).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $affiliates = [];

	public function __construct() {
		parent::__construct( [ 'singular' => 'commission', 'plural' => 'commissions', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'cb'              => '<input type="checkbox" />',
			'id'              => __( 'ID', 'partner-program' ),
			'affiliate'       => __( 'Affiliate', 'partner-program' ),
			'order'           => __( 'Order', 'partner-program' ),
			'source'          => __( 'Source', 'partner-program' ),
			'base'            => __( 'Base', 'partner-program' ),
			'rate'            => __( 'Rate %', 'partner-program' ),
			'amount'          => __( 'Amount', 'partner-program' ),
			'status'          => __( 'Status', 'partner-program' ),
			'hold_release_at' => __( 'Releases', 'partner-program' ),
			'created_at'      => __( 'Created', 'partner-program' ),
		];
	}

	/**
	 * Only the columns the repo's search() can safely ORDER BY are sortable.
	 * search() whitelists orderby to id|created_at|amount_cents|status, so we
	 * map our column keys onto those DB columns; everything else stays
	 * non-sortable to avoid injecting an unsupported sort into the query.
	 */
	public function get_sortable_columns(): array {
		return [
			'id'         => [ 'id', false ],
			'amount'     => [ 'amount_cents', false ],
			'status'     => [ 'status', false ],
			'created_at' => [ 'created_at', true ],
		];
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '" />';
	}

	/**
	 * Native bulk actions. Submitted via core's action/action2 controls and the
	 * ids[] checkboxes from column_cb(); processed by
	 * CommissionsScreen::handle_bulk() against the table's 'bulk-commissions' nonce.
	 */
	public function get_bulk_actions(): array {
		return [
			'approve'  => __( 'Approve', 'partner-program' ),
			'reject'   => __( 'Reject', 'partner-program' ),
			'clawback' => __( 'Mark clawback', 'partner-program' ),
		];
	}

	protected function get_views(): array {
		$current  = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$statuses = [
			''         => __( 'All', 'partner-program' ),
			'pending'  => __( 'Pending', 'partner-program' ),
			'approved' => __( 'Approved', 'partner-program' ),
			'paid'     => __( 'Paid', 'partner-program' ),
			'rejected' => __( 'Rejected', 'partner-program' ),
			'clawback' => __( 'Clawback', 'partner-program' ),
		];

		$views = [];
		foreach ( $statuses as $value => $label ) {
			$args = [ 'page' => 'partner-program-commissions' ];
			if ( '' !== $value ) {
				$args['status'] = $value;
			}
			$class = $current === $value ? ' class="current"' : '';
			$views[ '' === $value ? 'all' : $value ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
				$class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
				esc_html( $label )
			);
		}
		return $views;
	}

	public function column_id( $item ): string {
		return '#' . (int) $item['id'];
	}

	public function column_affiliate( $item ): string {
		$aff  = $this->affiliates[ (int) $item['affiliate_id'] ] ?? null;
		$user = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
		return esc_html( $user ? $user->user_email : '#' . $item['affiliate_id'] );
	}

	public function column_order( $item ): string {
		$order_id = isset( $item['order_id'] ) && '' !== $item['order_id'] && null !== $item['order_id'] ? (int) $item['order_id'] : 0;
		if ( $order_id > 0 ) {
			return '<a href="' . esc_url( $this->order_edit_url( $order_id ) ) . '">#' . $order_id . '</a>';
		}
		return '—';
	}

	public function column_source( $item ): string {
		return esc_html( (string) $item['source'] ) . ( $item['coupon_used'] ? ' ★' : '' );
	}

	public function column_base( $item ): string {
		return esc_html( Money::format( (int) $item['base_amount_cents'], (string) $item['currency'] ) );
	}

	public function column_rate( $item ): string {
		return esc_html( (string) $item['rate'] );
	}

	public function column_amount( $item ): string {
		return esc_html( Money::format( (int) $item['amount_cents'], (string) $item['currency'] ) );
	}

	public function column_status( $item ): string {
		return esc_html( (string) $item['status'] );
	}

	public function column_hold_release_at( $item ): string {
		return esc_html( (string) ( $item['hold_release_at'] ?? '' ) );
	}

	public function column_created_at( $item ): string {
		return esc_html( (string) $item['created_at'] );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		esc_html_e( 'None.', 'partner-program' );
	}

	public function prepare_items(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'pp_commissions_per_page', 100 );
		$paged    = $this->get_pagenum();

		// resolve_orderby() returns "<db_column> ASC|DESC" with both sides
		// whitelisted against get_sortable_columns(); split it back into the
		// orderby/order args search() expects (search() re-validates orderby
		// against its own allow-list, so this is safe regardless).
		$resolved = $this->resolve_orderby( 'created_at', 'desc' );
		[ $orderby, $order ] = array_pad( explode( ' ', $resolved, 2 ), 2, 'DESC' );

		$total_items = CommissionRepo::count( [ 'status' => $status ] );
		$rows        = CommissionRepo::search(
			[
				'status'   => $status,
				'per_page' => $per_page,
				'page'     => $paged,
				'orderby'  => $orderby,
				'order'    => $order,
			]
		);

		$this->affiliates = AffiliateRepo::find_many( array_map( static fn ( $r ): int => (int) $r['affiliate_id'], $rows ) );
		cache_users( array_values( array_filter( array_map( static fn ( $a ): int => (int) ( $a['user_id'] ?? 0 ), $this->affiliates ) ) ) );

		$this->items = $rows;

		$this->finalize_headers();
		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / max( 1, $per_page ) ),
			]
		);
	}

	/**
	 * HPOS-aware "Edit order" URL. With HPOS enabled, orders aren't
	 * `wp_posts` rows so `get_edit_post_link()` returns null and produces
	 * a broken `#` link. `WC_Order::get_edit_order_url()` knows about both
	 * storage modes; we fall back to `get_edit_post_link()` only when
	 * Woo isn't loaded for some reason (defensive).
	 */
	private function order_edit_url( int $order_id ): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return $order->get_edit_order_url();
			}
		}
		return (string) ( get_edit_post_link( $order_id ) ?: '#' );
	}
}
