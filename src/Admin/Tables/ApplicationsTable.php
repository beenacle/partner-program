<?php
/**
 * Native WP_List_Table for the partner applications list.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

use PartnerProgram\Domain\ApplicationRepo;

defined( 'ABSPATH' ) || exit;

final class ApplicationsTable extends AbstractListTable {

	/**
	 * Status filter resolved from the request, mirroring the original screen's
	 * sanitize_key( $_GET['status'] ) handling. Passed verbatim to the repo.
	 */
	private string $status = '';

	public function __construct() {
		parent::__construct( [ 'singular' => 'application', 'plural' => 'applications', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'id'         => __( 'ID', 'partner-program' ),
			'email'      => __( 'Email', 'partner-program' ),
			'status'     => __( 'Status', 'partner-program' ),
			'created_at' => __( 'Submitted', 'partner-program' ),
		];
	}

	/**
	 * ApplicationRepo::search() always orders by created_at DESC and accepts no
	 * orderby argument, so no column can be safely sorted without changing the
	 * repo's business logic. Keep every column non-sortable.
	 */
	public function get_sortable_columns(): array {
		return [];
	}

	protected function get_primary_column_name(): string {
		return 'email';
	}

	protected function get_views(): array {
		$current  = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$statuses = [
			''         => __( 'All', 'partner-program' ),
			'pending'  => __( 'Pending', 'partner-program' ),
			'approved' => __( 'Approved', 'partner-program' ),
			'rejected' => __( 'Rejected', 'partner-program' ),
		];

		$views = [];
		foreach ( $statuses as $value => $label ) {
			$args = [ 'page' => 'partner-program-applications' ];
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

	protected function column_default( $item, $column_name ) {
		if ( 'id' === $column_name ) {
			return '#' . (int) $item['id'];
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Primary column: the applicant email plus the row's "Review" action,
	 * preserving the exact URL and markup the original list rendered.
	 *
	 * @param array<string, mixed> $item
	 */
	public function column_email( $item ): string {
		$review_url = admin_url( 'admin.php?page=partner-program-applications&id=' . (int) $item['id'] );
		$actions    = [
			'review' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $review_url ),
				esc_html__( 'Review', 'partner-program' )
			),
		];

		return esc_html( (string) $item['email'] ) . $this->row_actions( $actions );
	}

	public function no_items(): void {
		esc_html_e( 'No applications.', 'partner-program' );
	}

	public function prepare_items(): void {
		$this->status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'pp_applications_per_page', 50 );
		$page     = $this->get_pagenum();

		// Reuse the exact repo calls and filter args the original screen used.
		$total_items = ApplicationRepo::count( [ 'status' => $this->status ] );
		$this->items = ApplicationRepo::search(
			[
				'status'   => $this->status,
				'per_page' => $per_page,
				'page'     => $page,
			]
		);

		$this->finalize_headers();
		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total_items / $per_page ) ),
			]
		);
	}
}
