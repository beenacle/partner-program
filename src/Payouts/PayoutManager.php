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
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class PayoutManager {

	public function register(): void {}

	/**
	 * Daily wp-cron entry point: honour the "Schedule" / "Payout day" settings,
	 * which were previously inert (batches only ran on a manual admin click).
	 *
	 * Exactly one batch per period, ever: we persist the last generated period
	 * key and bail if it already matches, so a daily tick that fires twice in a
	 * day (re-scheduled event, DST, manual cron) cannot double-generate, while a
	 * missed due-day tick is still caught up on a later day in the same period.
	 * Dates use the site timezone (wp_date) to match the configured payout day.
	 */
	public static function run_scheduled_generation(): void {
		$settings = new SettingsRepo();
		$schedule = (string) $settings->get( 'hold_payouts.schedule', 'monthly' );
		if ( 'manual' === $schedule ) {
			return;
		}

		$label_start = null;
		$label_end   = null;
		if ( 'weekly' === $schedule ) {
			$period      = (string) wp_date( 'o-\WW' );                 // ISO year-week, e.g. 2026-W26.
			$due         = true;                                        // First tick of a new ISO week.
			$label_start = (string) wp_date( 'Y-m-d', strtotime( '-7 days' ) ); // Label the batch with the week it covers,
			$label_end   = (string) wp_date( 'Y-m-d' );                 // not last month (generate_batch's default).
		} else { // monthly (default).
			$day    = max( 1, min( 28, (int) $settings->get( 'hold_payouts.payout_day', 1 ) ) );
			$period = (string) wp_date( 'Y-m' );   // e.g. 2026-06.
			$due    = ( (int) wp_date( 'j' ) >= $day ); // On/after the configured day (catch-up safe).
		}

		$key           = $schedule . ':' . $period;
		$last          = (string) get_option( 'partner_program_last_payout_period', '' );
		$last_schedule = '' !== $last ? (string) strstr( $last, ':', true ) : '';

		// First ever run, or the schedule changed since the last batch: arm for
		// the new schedule's next period instead of (a) retro-generating a batch
		// the moment the plugin is deployed past a due day, or (b) emitting an
		// extra batch right after an admin switches monthly<->weekly mid-period.
		if ( '' === $last || $last_schedule !== $schedule ) {
			update_option( 'partner_program_last_payout_period', $key, false );
			return;
		}

		if ( $last === $key || ! $due ) {
			return; // Already generated this period, or not yet due.
		}

		self::generate_batch( null, $label_start, $label_end );
		update_option( 'partner_program_last_payout_period', $key, false );
	}

	/**
	 * Generate one queued payout per affiliate whose approved-and-unpaid total ≥ threshold.
	 *
	 * Concurrency model:
	 *   1. We acquire a MySQL advisory lock (`GET_LOCK`) so two parallel
	 *      callers serialize at the DB level — no two batches see the
	 *      same approved-unclaimed commissions.
	 *   2. Each affiliate's claim is `UPDATE … WHERE payout_id IS NULL`,
	 *      and we use `rows_affected` plus a re-SELECT of the rows we
	 *      actually own to populate `pp_payout_items` and the payout
	 *      total. So even if the lock acquisition somehow fails, the
	 *      claim-by-update is still safe — never more than one payout
	 *      can claim a given commission.
	 *   3. We wrap each affiliate's claim in a transaction so a partial
	 *      failure rolls back instead of leaving a payout row pointing
	 *      at half-claimed commissions.
	 *
	 * @param string|null $period_yyyymm e.g. "2026-04". Null = use prior month.
	 * @param string|null $label_start   Y-m-d override for the batch's period_start
	 *                                   label on the unscoped path (claim is still
	 *                                   all approved+unclaimed). Used by the weekly
	 *                                   cron so batches aren't labeled "last month".
	 * @param string|null $label_end     Y-m-d override for period_end (unscoped path).
	 * @return array{count:int, batch_id:string}
	 */
	public static function generate_batch( ?string $period_yyyymm = null, ?string $label_start = null, ?string $label_end = null ): array {
		global $wpdb;
		$settings  = new SettingsRepo();
		$threshold = Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) );
		$batch_id  = 'batch_' . gmdate( 'YmdHis' ) . '_' . substr( md5( wp_generate_password( 12, false, false ) ), 0, 6 );

		// When the caller supplied an explicit YYYY-MM, scope the candidate
		// commissions to that month so reruns of an old period don't sweep
		// up commissions that arrived after the original batch. Without
		// --period the historical behaviour is preserved (claim every
		// approved + unclaimed commission, label the batch with last month).
		$scoped_to_period = (bool) ( $period_yyyymm && preg_match( '/^(\d{4})-(\d{2})$/', $period_yyyymm, $m ) );
		if ( $scoped_to_period ) {
			$start = sprintf( '%04d-%02d-01', (int) $m[1], (int) $m[2] );
			$end   = gmdate( 'Y-m-01', strtotime( $start . ' +1 month' ) );
		} else {
			// Unscoped claim (every approved + unclaimed commission). Labels
			// default to the prior month, but a caller may override them (the
			// weekly cron labels the batch with the week it just closed).
			$start = $label_start ?: gmdate( 'Y-m-01', strtotime( '-1 month' ) );
			$end   = $label_end ?: gmdate( 'Y-m-01' );
		}

		$lock_name = 'pp_generate_batch';
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
		if ( 1 !== $got_lock ) {
			// Another caller holds the lock; bail rather than racing.
			return [ 'count' => 0, 'batch_id' => $batch_id, 'skipped_no_method' => 0 ];
		}

		$skipped_no_method = 0;
		try {
			$base_sql = 'SELECT affiliate_id, currency, COALESCE(SUM(amount_cents),0) as total FROM ' . CommissionRepo::table()
				. " WHERE status = 'approved' AND payout_id IS NULL";
			if ( $scoped_to_period ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						$base_sql . ' AND created_at >= %s AND created_at < %s GROUP BY affiliate_id, currency',
						$start,
						$end
					),
					ARRAY_A
				) ?: [];
			} else {
				$rows = $wpdb->get_results( $base_sql . ' GROUP BY affiliate_id, currency', ARRAY_A ) ?: [];
			}

			$count  = 0;
			$logger = new Logger();
			foreach ( $rows as $row ) {
				$affiliate_id = (int) $row['affiliate_id'];
				$preview      = (int) $row['total'];
				$currency     = (string) $row['currency'];
				if ( $preview < $threshold ) {
					continue;
				}

				$affiliate = AffiliateRepo::find( $affiliate_id );
				if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
					continue;
				}
				$method = (string) ( $affiliate['payout_method'] ?? '' );
				if ( '' === $method ) {
					// Don't generate a payout the partner can't be paid via;
					// log so admins can chase the partner to fill out their
					// payout method, then leave the commissions unclaimed
					// for the next batch.
					$logger->warn(
						sprintf( 'Skipped affiliate #%d in batch %s: no payout method set.', $affiliate_id, $batch_id ),
						'payouts',
						[ 'affiliate_id' => $affiliate_id, 'preview_cents' => $preview, 'batch_id' => $batch_id ]
					);
					++$skipped_no_method;
					continue;
				}

				$wpdb->query( 'START TRANSACTION' );

				$payout_id = PayoutRepo::create(
					[
						'affiliate_id'       => $affiliate_id,
						'period_start'       => $start,
						'period_end'         => $end,
						'total_amount_cents' => 0,
						'currency'           => $currency,
						'method'             => $method,
						'status'             => 'queued',
						'csv_batch_id'       => $batch_id,
					]
				);
				if ( ! $payout_id ) {
					$wpdb->query( 'ROLLBACK' );
					continue;
				}

				$claim_sql = 'UPDATE ' . CommissionRepo::table() . " SET payout_id = %d, updated_at = %s "
					. "WHERE status = 'approved' AND payout_id IS NULL AND affiliate_id = %d AND currency = %s";
				$args      = [ $payout_id, current_time( 'mysql', true ), $affiliate_id, $currency ];
				if ( $scoped_to_period ) {
					$claim_sql .= ' AND created_at >= %s AND created_at < %s';
					$args[]     = $start;
					$args[]     = $end;
				}

				// Atomic claim: only commissions still unclaimed and approved
				// (and within --period if scoped) get tagged with our
				// payout_id. We never overwrite another batch.
				$claimed = (int) $wpdb->query( $wpdb->prepare( $claim_sql, ...$args ) );

				if ( 0 === $claimed ) {
					// Another concurrent run grabbed everything between the
					// preview SELECT and our UPDATE. Drop the empty payout.
					$wpdb->delete( PayoutRepo::table(), [ 'id' => $payout_id ] );
					$wpdb->query( 'COMMIT' );
					continue;
				}

				// Re-read the rows we now own to compute the real total
				// and populate payout_items. SUM is computed from the rows
				// we actually claimed, not the pre-claim preview.
				$claimed_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, amount_cents FROM ' . CommissionRepo::table() . ' WHERE payout_id = %d',
						$payout_id
					),
					ARRAY_A
				) ?: [];

				$real_total = 0;
				foreach ( $claimed_rows as $cr ) {
					$amt         = (int) $cr['amount_cents'];
					$real_total += $amt;
					PayoutRepo::add_item( $payout_id, (int) $cr['id'], $amt );
				}

				if ( $real_total < $threshold ) {
					// Below threshold after the real claim: release the
					// commissions and drop the payout row.
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . CommissionRepo::table() . ' SET payout_id = NULL, updated_at = %s WHERE payout_id = %d',
							current_time( 'mysql', true ),
							$payout_id
						)
					);
					$wpdb->delete( PayoutRepo::items_table(), [ 'payout_id' => $payout_id ] );
					$wpdb->delete( PayoutRepo::table(), [ 'id' => $payout_id ] );
					$wpdb->query( 'COMMIT' );
					continue;
				}

				PayoutRepo::update( $payout_id, [ 'total_amount_cents' => $real_total ] );
				$wpdb->query( 'COMMIT' );

				++$count;
				do_action( 'partner_program_payout_created', $payout_id );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		return [
			'count'             => $count,
			'batch_id'          => $batch_id,
			'skipped_no_method' => $skipped_no_method,
		];
	}

	/**
	 * Approved affiliates whose approved-and-unpaid commission balance is
	 * already at or above the payout threshold but who haven't picked a
	 * payout method yet. Surfaced on the admin dashboard so admins can
	 * chase the partner before the next batch runs.
	 *
	 * @return array<int, array{affiliate_id:int, email:string, balance_cents:int}>
	 */
	public static function affiliates_pending_payout_method(): array {
		global $wpdb;
		$settings  = new SettingsRepo();
		$threshold = Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id AS affiliate_id, a.user_id, a.payout_method, COALESCE(SUM(c.amount_cents),0) AS balance '
					. 'FROM ' . AffiliateRepo::table() . ' a '
					. 'LEFT JOIN ' . CommissionRepo::table() . " c ON c.affiliate_id = a.id AND c.status = 'approved' AND c.payout_id IS NULL "
					. "WHERE a.status = 'approved' "
					. 'GROUP BY a.id '
					. 'HAVING (a.payout_method IS NULL OR a.payout_method = %s) AND balance >= %d',
				'',
				$threshold
			),
			ARRAY_A
		) ?: [];

		$out = [];
		foreach ( $rows as $row ) {
			$user  = get_userdata( (int) $row['user_id'] );
			$out[] = [
				'affiliate_id'  => (int) $row['affiliate_id'],
				'email'         => $user ? $user->user_email : '',
				'balance_cents' => (int) $row['balance'],
			];
		}
		return $out;
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
		global $wpdb;
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout || 'queued' !== $payout['status'] ) {
			return;
		}
		$items = PayoutRepo::items_for( $payout_id );
		foreach ( $items as $item ) {
			CommissionRepo::update( (int) $item['commission_id'], [ 'payout_id' => null ] );
		}
		// Delete the item rows too. pp_payout_items has UNIQUE(commission_id),
		// so leaving them behind makes the next batch's add_item() INSERT
		// collide and silently drop — and mark_paid() then never flips that
		// commission to 'paid', so it gets paid again in a later batch.
		$wpdb->delete( PayoutRepo::items_table(), [ 'payout_id' => $payout_id ] );
		PayoutRepo::update( $payout_id, [ 'status' => 'reverted', 'notes' => 'Reverted by admin' ] );
		do_action( 'partner_program_payout_reverted', $payout_id );
	}

	public static function stream_csv_for_batch( string $batch_id ): void {
		global $wpdb;
		// Only queued/paid payouts are payable. A reverted payout's commissions
		// have been returned to the eligible pool (and may be in a newer batch),
		// so it must never reach a finance CSV — else it gets paid twice.
		$payouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PayoutRepo::table() . " WHERE csv_batch_id = %s AND status IN ( 'queued', 'paid' ) ORDER BY method, affiliate_id", $batch_id
		), ARRAY_A ) ?: [];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $batch_id ) . '.csv"' );

		// Neutralize spreadsheet formula injection: partners control their
		// display name and payout-detail fields, and a cell beginning with
		// = + - @ (or tab/CR) executes when finance opens the CSV in Excel/Sheets.
		$csv_safe = static function ( $v ): string {
			$v = (string) $v;
			return ( '' !== $v && false !== strpbrk( $v[0], "=+-@\t\r" ) ) ? "'" . $v : $v;
		};

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'payout_id', 'affiliate_id', 'partner_email', 'partner_name', 'method', 'amount', 'currency', 'period_start', 'period_end', 'payout_account', 'payout_extra' ] );

		foreach ( $payouts as $p ) {
			$aff   = AffiliateRepo::find( (int) $p['affiliate_id'] );
			$user  = $aff ? get_userdata( (int) $aff['user_id'] ) : null;
			$details = $aff ? AffiliateRepo::decrypt_payout_details( $aff['payout_details'] ?? null ) : [];
			fputcsv( $out, [
				(int) $p['id'],
				(int) $p['affiliate_id'],
				$csv_safe( $user ? $user->user_email : '' ),
				$csv_safe( $user ? $user->display_name : '' ),
				(string) $p['method'],
				Money::to_fixed( (int) $p['total_amount_cents'] ),
				(string) $p['currency'],
				(string) ( $p['period_start'] ?? '' ),
				(string) ( $p['period_end'] ?? '' ),
				$csv_safe( $details['account'] ?? $details['routing'] ?? $details['email'] ?? $details['handle'] ?? '' ),
				$csv_safe( wp_json_encode( $details ) ?: '' ),
			] );
		}
		fclose( $out );
	}
}
