<?php
/**
 * Native WP_List_Table for the payouts list.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Support\Money;

defined( 'ABSPATH' ) || exit;

final class PayoutsTable extends AbstractListTable {

	/**
	 * Affiliate rows keyed by affiliate id, resolved in prepare_items() so the
	 * affiliate column can show the linked user's email without per-row queries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $affiliates = [];

	/**
	 * CSV batch ids collected while rendering rows. The screen reads this after
	 * display() to print the "CSV exports" download list — preserving the exact
	 * behaviour of the original procedural screen.
	 *
	 * @var array<string,bool>
	 */
	private array $batches = [];

	public function __construct() {
		parent::__construct( [ 'singular' => 'payout', 'plural' => 'payouts', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'id'        => __( 'ID', 'partner-program' ),
			'affiliate' => __( 'Affiliate', 'partner-program' ),
			'period'    => __( 'Period', 'partner-program' ),
			'method'    => __( 'Method', 'partner-program' ),
			'amount'    => __( 'Amount', 'partner-program' ),
			'status'    => __( 'Status', 'partner-program' ),
			'reference' => __( 'Reference', 'partner-program' ),
		];
	}

	public function get_sortable_columns(): array {
		// PayoutRepo::search() always orders by created_at DESC and accepts no
		// orderby argument; leave columns non-sortable rather than inject an
		// ordering the repo cannot honor.
		return [];
	}

	/**
	 * CSV batch ids gathered during the last display(), in first-seen order.
	 *
	 * @return string[]
	 */
	public function get_batch_ids(): array {
		return array_keys( $this->batches );
	}

	public function column_id( $item ): string {
		return '#' . (int) $item['id'];
	}

	public function column_affiliate( $item ): string {
		$aff  = $this->affiliates[ (int) $item['affiliate_id'] ] ?? null;
		$user = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
		return esc_html( $user ? $user->user_email : '#' . $item['affiliate_id'] );
	}

	public function column_period( $item ): string {
		return esc_html( ( $item['period_start'] ?? '' ) . ' / ' . ( $item['period_end'] ?? '' ) );
	}

	public function column_method( $item ): string {
		return esc_html( (string) $item['method'] );
	}

	public function column_amount( $item ): string {
		// Record the CSV batch while iterating; mirrors the original screen which
		// collected batches in the row loop.
		if ( $item['csv_batch_id'] ) {
			$this->batches[ $item['csv_batch_id'] ] = true;
		}

		$amount = esc_html( Money::format( (int) $item['total_amount_cents'], (string) $item['currency'] ) );

		// Row actions live in the primary "amount" column and reproduce the exact
		// URLs + per-row nonces the original screen built.
		$actions = [];
		if ( 'queued' === $item['status'] ) {
			$mark_paid_url = wp_nonce_url(
				add_query_arg( [ 'pp_action' => 'mark_paid', 'id' => (int) $item['id'] ], admin_url( 'admin.php?page=partner-program-payouts' ) ),
				'pp_payout_action_' . $item['id']
			);
			$actions['mark_paid'] = '<a href="' . esc_url( $mark_paid_url ) . '">' . esc_html__( 'Mark paid', 'partner-program' ) . '</a>';

			$revert_url = wp_nonce_url(
				add_query_arg( [ 'pp_action' => 'revert', 'id' => (int) $item['id'] ], admin_url( 'admin.php?page=partner-program-payouts' ) ),
				'pp_payout_action_' . $item['id']
			);
			$actions['revert'] = '<a href="' . esc_url( $revert_url ) . '">' . esc_html__( 'Revert', 'partner-program' ) . '</a>';
		}

		return $amount . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function column_status( $item ): string {
		$status_labels = [
			'queued'   => __( 'Queued', 'partner-program' ),
			'paid'     => __( 'Paid', 'partner-program' ),
			'reverted' => __( 'Reverted', 'partner-program' ),
			'failed'   => __( 'Failed', 'partner-program' ),
		];
		$status_label = $status_labels[ (string) $item['status'] ] ?? ucfirst( (string) $item['status'] );
		return esc_html( $status_label );
	}

	public function column_reference( $item ): string {
		return esc_html( (string) $item['reference'] );
	}

	protected function get_primary_column_name(): string {
		return 'amount';
	}

	public function no_items(): void {
		esc_html_e( 'No payouts yet.', 'partner-program' );
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'pp_payouts_per_page', 100 );
		$paged    = $this->get_pagenum();

		$total_items = PayoutRepo::count();
		$rows        = PayoutRepo::search( [ 'per_page' => $per_page, 'page' => $paged ] );

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
}
