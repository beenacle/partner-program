<?php
/**
 * Application repository.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class ApplicationRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_applications';
	}

	public static function create( array $data ): int {
		global $wpdb;
		$data = array_merge(
			[
				'status'     => 'pending',
				'created_at' => current_time( 'mysql', true ),
			],
			$data
		);
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Whether a still-pending application already exists for this email — used
	 * to keep the same person from filing duplicates.
	 */
	public static function pending_for_email( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . " WHERE email = %s AND status = 'pending' LIMIT 1",
				$email
			)
		);
		return (bool) $id;
	}

	public static function count( array $args = [] ): int {
		global $wpdb;
		$where  = '1=1';
		$params = [];
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = (string) $args['status'];
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
	}

	public static function search( array $args = [] ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			[
				'status'   => '',
				'per_page' => 50,
				'page'     => 1,
			]
		);
		$where  = '1=1';
		$params = [];
		if ( $args['status'] ) {
			$where    .= ' AND status = %s';
			$params[]  = $args['status'];
		}
		$offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit  = max( 1, (int) $args['per_page'] );
		$sql    = 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}
}
