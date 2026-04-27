<?php
/**
 * Payout batch generator + CSV exporter + status transitions.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Payouts;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class PayoutManager {

	public function register(): void {}

	/**
	 * Generate one queued payout per affiliate whose approved-and-unpaid total ≥ threshold.
	 *
	 * @param string|null $period_yyyymm e.g. "2026-04". Null = use prior month.
	 * @return array{count:int, batch_id:string}
	 */
	public static function generate_batch( ?string $period_yyyymm = null ): array {
		global $wpdb;
		$settings  = new SettingsRepo();
		$threshold = Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) );
		$batch_id  = 'batch_' . gmdate( 'YmdHis' ) . '_' . substr( md5( wp_generate_password( 12, false, false ) ), 0, 6 );

		if ( $period_yyyymm && preg_match( '/^(\d{4})-(\d{2})$/', $period_yyyymm, $m ) ) {
			$start = sprintf( '%04d-%02d-01', (int) $m[1], (int) $m[2] );
			$end   = gmdate( 'Y-m-01', strtotime( $start . ' +1 month' ) );
		} else {
			$start = gmdate( 'Y-m-01', strtotime( '-1 month' ) );
			$end   = gmdate( 'Y-m-01' );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT affiliate_id, currency, COALESCE(SUM(amount_cents),0) as total, COUNT(*) as cnt FROM ' . CommissionRepo::table()
					. " WHERE status = 'approved' AND payout_id IS NULL GROUP BY affiliate_id, currency"
			),
			ARRAY_A
		) ?: [];

		$count = 0;
		foreach ( $rows as $row ) {
			$affiliate_id = (int) $row['affiliate_id'];
			$total        = (int) $row['total'];
			$currency     = (string) $row['currency'];
			if ( $total < $threshold ) {
				continue;
			}

			$affiliate = AffiliateRepo::find( $affiliate_id );
			if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
				continue;
			}
			$method = (string) ( $affiliate['payout_method'] ?? '' );

			$payout_id = PayoutRepo::create(
				[
					'affiliate_id'       => $affiliate_id,
					'period_start'       => $start,
					'period_end'         => $end,
					'total_amount_cents' => $total,
					'currency'           => $currency,
					'method'             => $method,
					'status'             => 'queued',
					'csv_batch_id'       => $batch_id,
				]
			);

			$cids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM ' . CommissionRepo::table() . " WHERE status = 'approved' AND payout_id IS NULL AND affiliate_id = %d AND currency = %s",
				$affiliate_id, $currency
			) ) ?: [];

			foreach ( $cids as $cid ) {
				$cid = (int) $cid;
				$c   = CommissionRepo::find( $cid );
				if ( ! $c ) { continue; }
				PayoutRepo::add_item( $payout_id, $cid, (int) $c['amount_cents'] );
				CommissionRepo::update( $cid, [ 'payout_id' => $payout_id ] );
			}
			++$count;
			do_action( 'partner_program_payout_created', $payout_id );
		}

		return [ 'count' => $count, 'batch_id' => $batch_id ];
	}

	public static function mark_paid( int $payout_id, ?string $reference = null ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout || 'queued' !== $payout['status'] ) {
			return;
		}
		PayoutRepo::update(
			$payout_id,
			[
				'status'    => 'paid',
				'reference' => $reference,
				'paid_at'   => current_time( 'mysql', true ),
			]
		);
		$items = PayoutRepo::items_for( $payout_id );
		foreach ( $items as $item ) {
			CommissionRepo::update( (int) $item['commission_id'], [ 'status' => 'paid' ] );
		}
		do_action( 'partner_program_payout_paid', $payout_id );
	}

	public static function revert( int $payout_id ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout || 'queued' !== $payout['status'] ) {
			return;
		}
		$items = PayoutRepo::items_for( $payout_id );
		foreach ( $items as $item ) {
			CommissionRepo::update( (int) $item['commission_id'], [ 'payout_id' => null ] );
		}
		PayoutRepo::update( $payout_id, [ 'status' => 'failed', 'notes' => 'Reverted by admin' ] );
		do_action( 'partner_program_payout_reverted', $payout_id );
	}

	public static function stream_csv_for_batch( string $batch_id ): void {
		global $wpdb;
		$payouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PayoutRepo::table() . ' WHERE csv_batch_id = %s ORDER BY method, affiliate_id', $batch_id
		), ARRAY_A ) ?: [];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $batch_id ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'payout_id', 'affiliate_id', 'partner_email', 'partner_name', 'method', 'amount', 'currency', 'period_start', 'period_end', 'payout_account', 'payout_extra' ] );

		foreach ( $payouts as $p ) {
			$aff   = AffiliateRepo::find( (int) $p['affiliate_id'] );
			$user  = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
			$details = $aff ? AffiliateRepo::decrypt_payout_details( $aff['payout_details'] ?? null ) : [];
			fputcsv( $out, [
				(int) $p['id'],
				(int) $p['affiliate_id'],
				$user ? $user->user_email : '',
				$user ? $user->display_name : '',
				(string) $p['method'],
				number_format( (int) $p['total_amount_cents'] / 100, 2, '.', '' ),
				(string) $p['currency'],
				(string) ( $p['period_start'] ?? '' ),
				(string) ( $p['period_end'] ?? '' ),
				(string) ( $details['account'] ?? $details['email'] ?? $details['handle'] ?? '' ),
				wp_json_encode( $details ) ?: '',
			] );
		}
		fclose( $out );
	}
}
