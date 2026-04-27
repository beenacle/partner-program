<?php
/**
 * Commission repository.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class CommissionRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_commissions';
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function for_order( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ), ARRAY_A ) ?: [];
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			[
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			],
			$data
		);
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	public static function update_where( array $where, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, $where );
	}

	public static function sum_for_affiliate( int $affiliate_id, string $status, ?string $from = null, ?string $to = null ): int {
		global $wpdb;
		$sql    = 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . self::table() . ' WHERE affiliate_id = %d AND status = %s';
		$params = [ $affiliate_id, $status ];
		if ( $from ) {
			$sql      .= ' AND created_at >= %s';
			$params[]  = $from;
		}
		if ( $to ) {
			$sql      .= ' AND created_at < %s';
			$params[]  = $to;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
	}

	public static function search( array $args = [] ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			[
				'affiliate_id' => 0,
				'status'       => '',
				'order_id'     => 0,
				'from'         => '',
				'to'           => '',
				'per_page'     => 50,
				'page'         => 1,
				'orderby'      => 'created_at',
				'order'        => 'DESC',
			]
		);

		$where  = '1=1';
		$params = [];
		if ( $args['affiliate_id'] ) {
			$where    .= ' AND affiliate_id = %d';
			$params[]  = (int) $args['affiliate_id'];
		}
		if ( $args['status'] ) {
			$where    .= ' AND status = %s';
			$params[]  = $args['status'];
		}
		if ( $args['order_id'] ) {
			$where    .= ' AND order_id = %d';
			$params[]  = (int) $args['order_id'];
		}
		if ( $args['from'] ) {
			$where    .= ' AND created_at >= %s';
			$params[]  = $args['from'];
		}
		if ( $args['to'] ) {
			$where    .= ' AND created_at < %s';
			$params[]  = $args['to'];
		}

		$orderby = in_array( $args['orderby'], [ 'id', 'created_at', 'amount_cents', 'status' ], true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit   = max( 1, (int) $args['per_page'] );

		$sql      = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}
}
