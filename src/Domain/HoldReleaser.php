<?php
/**
 * Moves due 'pending' commissions to 'approved' once the hold expires.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class HoldReleaser {

	public function register(): void {
		// Hook registered in Plugin::boot().
	}

	public static function release_due(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . CommissionRepo::table() . " WHERE status = 'pending' AND hold_release_at IS NOT NULL AND hold_release_at <= %s",
				$now
			)
		) ?: [];

		$count = 0;
		foreach ( $ids as $id ) {
			CommissionRepo::update(
				(int) $id,
				[
					'status' => 'approved',
				]
			);
			do_action( 'partner_program_commission_approved', (int) $id );
			++$count;
		}
		return $count;
	}
}
