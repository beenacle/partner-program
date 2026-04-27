<?php
/**
 * Tier resolver - sums prior month's approved commission BASE for each affiliate
 * (gross sales attributed) and locks current_tier_id to the matching tier index.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class TierResolver {

	public function register(): void {
		// Hooks registered in Plugin::boot().
	}

	/** @return array<int, array{min:int,max:?int,rate:float,label?:string}> */
	public static function tiers(): array {
		$settings = new SettingsRepo();
		$tiers    = $settings->get( 'tiers', [] );
		return is_array( $tiers ) ? array_values( $tiers ) : [];
	}

	public static function tier_at( int $index ): ?array {
		$tiers = self::tiers();
		return $tiers[ $index ] ?? null;
	}

	public static function tier_index_for_sales_cents( int $sales_cents ): ?int {
		$tiers = self::tiers();
		$dollars = $sales_cents / 100;
		$picked = null;
		foreach ( $tiers as $i => $t ) {
			$min = (float) ( $t['min'] ?? 0 );
			$max = isset( $t['max'] ) && null !== $t['max'] && '' !== $t['max'] ? (float) $t['max'] : null;
			if ( $dollars >= $min && ( null === $max || $dollars <= $max ) ) {
				$picked = $i;
			}
		}
		return $picked;
	}

	public static function recalculate_all(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id FROM ' . AffiliateRepo::table() . " WHERE status = 'approved'", ARRAY_A ) ?: [];
		foreach ( $rows as $row ) {
			self::recalculate_for( (int) $row['id'] );
		}
	}

	public static function recalculate_for( int $affiliate_id ): void {
		$tz = wp_timezone();

		$now        = new \DateTimeImmutable( 'now', $tz );
		$prev_start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
		$prev_end   = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		global $wpdb;
		$sales_cents = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(base_amount_cents),0) FROM ' . CommissionRepo::table() . " WHERE affiliate_id = %d AND status IN ('approved','paid') AND created_at >= %s AND created_at < %s",
				$affiliate_id,
				$prev_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				$prev_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
			)
		);

		$tier_index = self::tier_index_for_sales_cents( $sales_cents );
		AffiliateRepo::update( $affiliate_id, [ 'current_tier_id' => $tier_index ] );

		do_action( 'partner_program_tier_recalculated', $affiliate_id, $tier_index, $sales_cents );
	}

	public static function progress_for_affiliate( int $affiliate_id ): array {
		$tz       = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $tz );
		$start    = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		global $wpdb;
		$sales_cents = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(base_amount_cents),0) FROM ' . CommissionRepo::table() . " WHERE affiliate_id = %d AND status IN ('pending','approved','paid') AND created_at >= %s",
				$affiliate_id,
				$start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
			)
		);

		$tiers       = self::tiers();
		$current_idx = self::tier_index_for_sales_cents( $sales_cents );
		$current     = null !== $current_idx ? $tiers[ $current_idx ] : null;
		$next        = null !== $current_idx && isset( $tiers[ $current_idx + 1 ] ) ? $tiers[ $current_idx + 1 ] : null;

		return [
			'current_sales_cents' => $sales_cents,
			'current_tier_index'  => $current_idx,
			'current_tier'        => $current,
			'next_tier'           => $next,
		];
	}
}
