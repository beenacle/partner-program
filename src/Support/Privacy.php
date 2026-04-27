<?php
/**
 * GDPR exporter / eraser hooks for affiliate data.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['partner-program'] = [
			'exporter_friendly_name' => __( 'Partner Program', 'partner-program' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['partner-program'] = [
			'eraser_friendly_name' => __( 'Partner Program', 'partner-program' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	public static function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return [ 'data' => [], 'done' => true ];
		}
		$aff = AffiliateRepo::find_by_user( (int) $user->ID );
		$out = [];
		if ( $aff ) {
			$out[] = [
				'group_id'    => 'partner-program',
				'group_label' => __( 'Partner Program', 'partner-program' ),
				'item_id'     => 'affiliate-' . $aff['id'],
				'data'        => [
					[ 'name' => 'Referral code', 'value' => $aff['referral_code'] ],
					[ 'name' => 'Status', 'value' => $aff['status'] ],
					[ 'name' => 'Created', 'value' => $aff['created_at'] ],
				],
			];
		}
		return [ 'data' => $out, 'done' => true ];
	}

	public static function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return [ 'items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true ];
		}
		$aff = AffiliateRepo::find_by_user( (int) $user->ID );
		if ( ! $aff ) {
			return [ 'items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true ];
		}
		// Wipe payout PII but keep commissions for accounting.
		AffiliateRepo::update( (int) $aff['id'], [ 'payout_details' => null ] );
		return [
			'items_removed'  => 1,
			'items_retained' => CommissionRepo::sum_for_affiliate( (int) $aff['id'], 'paid' ) > 0 ? 1 : 0,
			'messages'       => [ __( 'Removed payout details. Historical commission records retained for accounting compliance.', 'partner-program' ) ],
			'done'           => true,
		];
	}
}
