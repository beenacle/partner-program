<?php
/**
 * WP-CLI commands.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Cli;

use PartnerProgram\Core\Seeder;
use PartnerProgram\Domain\HoldReleaser;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Payouts\PayoutManager;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		\WP_CLI::add_command( 'partner-program release-holds', [ self::class, 'release_holds' ] );
		\WP_CLI::add_command( 'partner-program recalculate-tiers', [ self::class, 'recalc_tiers' ] );
		\WP_CLI::add_command( 'partner-program generate-payouts', [ self::class, 'generate_payouts' ] );
		\WP_CLI::add_command( 'partner-program seed-training', [ self::class, 'seed_training' ] );
	}

	/**
	 * Create the default portal training modules. Idempotent.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite the body of already-seeded modules with the current default.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function seed_training( array $args, array $assoc ): void {
		$force  = isset( $assoc['force'] );
		$result = Seeder::seed_modules( $force );
		foreach ( $result as $key => $status ) {
			\WP_CLI::log( sprintf( '%s: %s', $key, $status ) );
		}
		\WP_CLI::success( sprintf( 'Processed %d training module(s).', count( $result ) ) );
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
