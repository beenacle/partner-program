<?php
/**
 * Audit log viewer.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class LogsScreen {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'pp_logs ORDER BY id DESC LIMIT 200', ARRAY_A );

		echo '<div class="wrap"><h1>' . esc_html__( 'Logs', 'partner-program' ) . '</h1>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>ID</th><th>When</th><th>Channel</th><th>Level</th><th>Message</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $r['id'],
				esc_html( (string) $r['created_at'] ),
				esc_html( (string) $r['channel'] ),
				esc_html( (string) $r['level'] ),
				esc_html( (string) $r['message'] )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No logs.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
