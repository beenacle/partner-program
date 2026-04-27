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
}
