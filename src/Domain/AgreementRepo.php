<?php
/**
 * Agreement repository - immutable versioned compliance text.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class AgreementRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_agreements';
	}

	public static function acceptances_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_agreement_acceptances';
	}

	public static function current(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . ' ORDER BY version DESC LIMIT 1', ARRAY_A );
		return $row ?: null;
	}

	public static function next_version(): int {
		global $wpdb;
		return ( (int) $wpdb->get_var( 'SELECT COALESCE(MAX(version),0) FROM ' . self::table() ) ) + 1;
	}

	public static function create( string $body_html, ?string $summary = null, ?int $created_by = null ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'version'    => self::next_version(),
				'body_html'  => $body_html,
				'summary'    => $summary,
				'created_by' => $created_by,
				'created_at' => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function record_acceptance( int $affiliate_id, int $agreement_id, ?string $ip_hash = null ): void {
		global $wpdb;
		$wpdb->insert(
			self::acceptances_table(),
			[
				'affiliate_id' => $affiliate_id,
				'agreement_id' => $agreement_id,
				'accepted_at'  => current_time( 'mysql', true ),
				'ip_hash'      => $ip_hash,
			]
		);
	}
}
