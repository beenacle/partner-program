<?php
/**
 * Uninstall handler. Only deletes data when PARTNER_PROGRAM_DELETE_ALL is true,
 * to prevent accidental destruction of historical commissions / payouts.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'PARTNER_PROGRAM_DELETE_ALL' ) || ! PARTNER_PROGRAM_DELETE_ALL ) {
	return;
}

global $wpdb;

$tables = [
	'pp_affiliates', 'pp_applications', 'pp_visits', 'pp_commissions',
	'pp_payouts', 'pp_payout_items', 'pp_agreements',
	'pp_agreement_acceptances', 'pp_certifications', 'pp_logs',
];
foreach ( $tables as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $t );
}

delete_option( 'partner_program_settings' );
delete_option( 'partner_program_db_version' );
delete_option( 'partner_program_encryption_key' );
delete_option( 'partner_program_portal_page_id' );
delete_option( 'partner_program_application_page_id' );
delete_option( 'partner_program_login_page_id' );
delete_option( 'partner_program_last_payout_period' );
delete_option( 'partner_program_emails_migrated' );

// Delete plugin CPT posts (training modules + marketing materials) and their
// meta — they're not in the pp_* tables, so the DROP TABLE above misses them.
$pp_posts = get_posts(
	[
		'post_type'   => [ 'pp_module', 'pp_material' ],
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	]
);
foreach ( $pp_posts as $pp_post_id ) {
	wp_delete_post( (int) $pp_post_id, true );
}

if ( get_role( 'partner_program_partner' ) ) {
	remove_role( 'partner_program_partner' );
}
