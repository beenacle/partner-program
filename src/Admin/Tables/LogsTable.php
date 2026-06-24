<?php
/**
 * Native WP_List_Table for the audit log.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin\Tables;

defined( 'ABSPATH' ) || exit;

final class LogsTable extends AbstractListTable {

	public function __construct() {
		parent::__construct( [ 'singular' => 'log', 'plural' => 'logs', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'id'         => __( 'ID', 'partner-program' ),
			'created_at' => __( 'When', 'partner-program' ),
			'channel'    => __( 'Channel', 'partner-program' ),
			'level'      => __( 'Level', 'partner-program' ),
			'message'    => __( 'Message', 'partner-program' ),
		];
	}

	public function get_sortable_columns(): array {
		return [
			'id'         => [ 'id', true ],
			'created_at' => [ 'created_at', false ],
			'channel'    => [ 'channel', false ],
			'level'      => [ 'level', false ],
		];
	}

	protected function column_default( $item, $column_name ) {
		if ( 'id' === $column_name ) {
			return '#' . (int) $item['id'];
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		esc_html_e( 'No logs.', 'partner-program' );
	}

	public function prepare_items(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pp_logs';

		$per_page = $this->get_items_per_page( 'pp_logs_per_page', 100 );
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;
		$orderby  = $this->resolve_orderby( 'id', 'desc' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// $orderby is whitelisted (column + ASC|DESC) by resolve_orderby().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY {$orderby} LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		) ?: [];

		$this->finalize_headers();
		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			]
		);
	}
}
