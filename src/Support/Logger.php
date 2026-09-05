<?php
/**
 * Audit log writer (writes to pp_logs table).
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public function log(
		string $message,
		string $channel = 'general',
		string $level = 'info',
		?int $subject_id = null,
		?string $subject_type = null,
		array $context = []
	): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pp_logs',
			[
				'level'        => $level,
				'channel'      => $channel,
				'actor_id'     => get_current_user_id() ?: null,
				'subject_type' => $subject_type,
				'subject_id'   => $subject_id,
				'message'      => $message,
				'context'      => $context ? wp_json_encode( $context ) : null,
				'created_at'   => current_time( 'mysql', true ),
			]
		);
	}

	public function info( string $msg, string $channel = 'general', array $ctx = [] ): void {
		$this->log( $msg, $channel, 'info', null, null, $ctx );
	}

	public function warn( string $msg, string $channel = 'general', array $ctx = [] ): void {
		$this->log( $msg, $channel, 'warning', null, null, $ctx );
	}

	public function error( string $msg, string $channel = 'general', array $ctx = [] ): void {
		$this->log( $msg, $channel, 'error', null, null, $ctx );
	}

	/**
	 * Delete log rows older than $days. Returns the number of rows actually
	 * removed. $days <= 0 means "keep forever" — caller is expected to
	 * pre-check the retention setting in that case; we still bail out
	 * defensively here so a misconfigured cron can never wipe the table.
	 *
	 * Deletion is chunked: an unbounded DELETE on a multi-million-row
	 * pp_logs table can hold a lock long enough for the daily cron worker
	 * to time out before it commits. We loop in batches of CHUNK_SIZE and
	 * stop once a batch deletes fewer rows than requested (= no more
	 * candidates) or we've spent MAX_SECONDS in the loop.
	 */
	public static function prune_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'pp_logs';

		$chunk_size  = 5000;
		$max_seconds = 30;
		$started     = microtime( true );
		$total       = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
					$cutoff,
					$chunk_size
				)
			);
			$deleted = (int) $wpdb->rows_affected;
			$total  += $deleted;
			if ( $deleted < $chunk_size ) {
				break;
			}
			if ( ( microtime( true ) - $started ) > $max_seconds ) {
				break;
			}
		} while ( true );

		return $total;
	}

	/**
	 * Cron entry-point. Pulls retention from settings and short-circuits
	 * when retention_days is 0 (keep forever).
	 */
	public static function run_scheduled_prune(): void {
		$repo = new SettingsRepo();
		$days = (int) $repo->get( 'logs.retention_days', 90 );
		if ( $days > 0 ) {
			self::prune_older_than( $days );
		}
	}
}
