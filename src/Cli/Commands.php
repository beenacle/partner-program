<?php
/**
 * WP-CLI commands.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Cli;

use PartnerProgram\Domain\HoldReleaser;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Payouts\PayoutManager;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		\WP_CLI::add_command( 'partner-program release-holds', [ self::class, 'release_holds' ] );
		\WP_CLI::add_command( 'partner-program recalculate-tiers', [ self::class, 'recalc_tiers' ] );
		\WP_CLI::add_command( 'partner-program generate-payouts', [ self::class, 'generate_payouts' ] );
	}

	public static function release_holds(): void {
		$n = HoldReleaser::release_due();
		\WP_CLI::success( sprintf( 'Released %d commissions.', $n ) );
	}

	public static function recalc_tiers(): void {
		TierResolver::recalculate_all();
		\WP_CLI::success( 'Tiers recalculated.' );
	}

	public static function generate_payouts( array $args, array $assoc ): void {
		$period = isset( $assoc['period'] ) ? (string) $assoc['period'] : null;
		$res    = PayoutManager::generate_batch( $period );
		\WP_CLI::success( sprintf( 'Created %d payouts in batch %s.', $res['count'], $res['batch_id'] ) );
	}
}
