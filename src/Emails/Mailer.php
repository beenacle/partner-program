<?php
/**
 * Central transactional mailer.
 *
 * Subscribes to lifecycle action hooks (`partner_program_*`), builds the
 * per-event tokens, and dispatches each event to its native {@see EventEmail}
 * (a WC_Email subclass registered via the `woocommerce_email_classes` filter),
 * which owns the enable / recipient / subject controls and renders through the
 * store's email template. Subject/body defaults come from {@see EventRegistry}.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Emails;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	public function register(): void {
		add_action( 'partner_program_application_submitted', [ $this, 'on_application_submitted' ], 10, 2 );
		add_action( 'partner_program_affiliate_approved',    [ $this, 'on_affiliate_approved' ] );
		add_action( 'partner_program_application_rejected',  [ $this, 'on_application_rejected' ], 10, 2 );
		add_action( 'partner_program_affiliate_suspended',   [ $this, 'on_affiliate_suspended' ] );
		add_action( 'partner_program_violation_flagged',     [ $this, 'on_violation_flagged' ], 10, 2 );
		add_action( 'partner_program_payout_paid',           [ $this, 'on_payout_paid' ] );
		add_action( 'partner_program_commission_approved',   [ $this, 'on_commission_approved' ] );

		// Expose every event as a native WC_Email so it shows under
		// WooCommerce > Settings > Emails (enable / recipient / subject /
		// preview / test). The handlers above still build the tokens and call
		// send(), which now dispatches to the matching WC_Email instance.
		add_filter( 'woocommerce_email_classes', [ $this, 'register_wc_emails' ] );
	}

	/**
	 * @param array<string, \WC_Email> $emails
	 * @return array<string, mixed>
	 */
	public function register_wc_emails( $emails ) {
		if ( ! class_exists( '\WC_Email' ) ) {
			return $emails;
		}
		foreach ( array_keys( EventRegistry::all() ) as $event ) {
			$emails[ 'PP_Email_' . $event ] = new EventEmail( (string) $event );
		}
		return $emails;
	}

	/**
	 * One-time upgrade migration: carry any *customized* per-event enable/subject
	 * choices from the legacy Emails settings tab into the corresponding native
	 * WC_Email option (`woocommerce_pp_<event>_settings`), which is now the source
	 * of truth. Only values that differ from the EventRegistry defaults are
	 * copied, so future default changes still apply to un-customized events.
	 * Idempotent via the `partner_program_emails_migrated` flag.
	 */
	public static function migrate_legacy_email_settings(): void {
		if ( get_option( 'partner_program_emails_migrated' ) ) {
			return;
		}

		$stored = get_option( 'partner_program_settings', [] );
		$events = ( is_array( $stored ) && isset( $stored['emails']['events'] ) && is_array( $stored['emails']['events'] ) )
			? $stored['emails']['events']
			: [];

		foreach ( $events as $event => $cfg ) {
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$def             = EventRegistry::get( (string) $event );
			$default_enabled = ! empty( $def['default_enabled'] );
			$default_subject = (string) ( $def['subject'] ?? '' );

			$opt = 'woocommerce_pp_' . sanitize_key( (string) $event ) . '_settings';
			$wc  = get_option( $opt, [] );
			$wc  = is_array( $wc ) ? $wc : [];

			if ( array_key_exists( 'enabled', $cfg ) && (bool) $cfg['enabled'] !== $default_enabled ) {
				$wc['enabled'] = ! empty( $cfg['enabled'] ) ? 'yes' : 'no';
			}
			$subject = isset( $cfg['subject'] ) ? trim( (string) $cfg['subject'] ) : '';
			if ( '' !== $subject && $subject !== $default_subject ) {
				$wc['subject'] = $subject;
			}

			if ( $wc ) {
				update_option( $opt, $wc );
			}
		}

		update_option( 'partner_program_emails_migrated', 1 );
	}

	/* ------------------------------------------------------------------
	 * Public dispatcher
	 * ----------------------------------------------------------------*/

	/**
	 * Render and send one event email.
	 *
	 * @param string               $event     Event key from EventRegistry.
	 * @param string|string[]      $to        Recipient(s). Empty/invalid => no-op.
	 * @param array<string,mixed>  $tokens    Token replacements.
	 * @param array<string,mixed>  $context   Extra context exposed to filters.
	 * @return bool True if wp_mail returned true, false otherwise.
	 */
	public static function send( string $event, $to, array $tokens, array $context = [] ): bool {
		$definition = EventRegistry::get( $event );
		if ( ! $definition ) {
			return false;
		}

		// Whether the email is enabled is owned entirely by the native WC_Email
		// instance (WooCommerce > Settings > Emails); EventEmail::trigger_event()
		// checks is_enabled() before sending. Legacy per-event enable choices are
		// migrated into those WC options once on upgrade.

		/**
		 * Suppress sending of a specific event. Return false to silently drop.
		 */
		if ( ! (bool) apply_filters( 'partner_program_email_enabled', true, $event, $context ) ) {
			return false;
		}

		$recipients = self::normalize_recipients( $to );
		/**
		 * Filter the recipient list. Return an empty array to skip the send.
		 *
		 * @param string[]            $recipients
		 * @param string              $event
		 * @param array<string,mixed> $context
		 */
		$recipients = self::normalize_recipients( (array) apply_filters( 'partner_program_email_recipients', $recipients, $event, $context ) );

		// Partner-audience emails are addressed from $recipients, so an empty
		// list (e.g. a filter returning [] to suppress, or an invalid address)
		// must skip the send — honoring the filter's documented contract. Admin
		// emails ignore $recipients and use their own configured WC recipient.
		$is_partner = 'partner' === ( $definition['audience'] ?? 'partner' );
		if ( $is_partner && ! $recipients ) {
			return false;
		}

		// Dispatch to the matching native WC_Email instance, which owns the
		// enable / recipient / subject controls and renders through the store's
		// email template.
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->mailer() ) {
			return false;
		}
		foreach ( WC()->mailer()->get_emails() as $mail ) {
			if ( $mail instanceof EventEmail && 'pp_' . $event === $mail->id ) {
				$mail->trigger_event( $recipients, $tokens );
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Lifecycle handlers
	 * ----------------------------------------------------------------*/

	public function on_application_submitted( int $application_id, array $data ): void {
		$settings = new SettingsRepo();
		$program  = self::program_name( $settings );

		$lines = [];
		foreach ( $data as $k => $v ) {
			$lines[] = $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}

		$tokens = [
			'{program_name}'    => $program,
			'{applicant_name}'  => (string) ( $data['full_name'] ?? '' ),
			'{applicant_email}' => (string) ( $data['email'] ?? '' ),
			'{application_id}'  => (string) $application_id,
			'{fields_dump}'     => implode( "\n", $lines ),
			'{review_url}'      => admin_url( 'admin.php?page=partner-program-applications&id=' . $application_id ),
		];

		$to = (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) );
		self::send( 'application_received', $to, $tokens, [ 'application_id' => $application_id, 'data' => $data ] );
	}

	public function on_affiliate_approved( int $affiliate_id ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{partner_name}'  => $user->display_name,
			'{partner_email}' => $user->user_email,
			'{referral_code}' => (string) $affiliate['referral_code'],
			'{portal_url}'    => self::portal_url(),
			'{login_url}'     => self::login_url( $settings ),
		];

		self::send( 'application_approved', $user->user_email, $tokens, [ 'affiliate_id' => $affiliate_id ] );
	}

	public function on_application_rejected( int $application_id, string $notes ): void {
		$application = ApplicationRepo::find( $application_id );
		if ( ! $application ) {
			return;
		}
		$data     = json_decode( (string) ( $application['submitted_data'] ?? '' ), true ) ?: [];
		$email    = (string) ( $application['email'] ?? ( $data['email'] ?? '' ) );
		if ( '' === $email ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'    => self::program_name( $settings ),
			'{applicant_name}'  => (string) ( $data['full_name'] ?? $email ),
			'{applicant_email}' => $email,
			'{review_notes}'    => $notes,
			'{support_email}'   => (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) ),
		];

		self::send( 'application_rejected', $email, $tokens, [ 'application_id' => $application_id ] );
	}

	public function on_affiliate_suspended( int $affiliate_id ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{partner_name}'  => $user->display_name,
			'{partner_email}' => $user->user_email,
			'{support_email}' => (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) ),
			'{portal_url}'    => self::portal_url(),
		];

		self::send( 'affiliate_suspended', $user->user_email, $tokens, [ 'affiliate_id' => $affiliate_id ] );
	}

	public function on_violation_flagged( int $affiliate_id, string $reason ): void {
		$affiliate = AffiliateRepo::find( $affiliate_id );
		$user      = $affiliate ? get_user_by( 'id', (int) $affiliate['user_id'] ) : null;
		$settings  = new SettingsRepo();

		$tokens = [
			'{program_name}'  => self::program_name( $settings ),
			'{affiliate_id}'  => (string) $affiliate_id,
			'{partner_name}'  => $user ? $user->display_name : '',
			'{partner_email}' => $user ? $user->user_email : '',
			'{reason}'        => $reason,
			'{affiliate_url}' => admin_url( 'admin.php?page=partner-program-affiliates&id=' . $affiliate_id ),
		];

		$to = (string) $settings->get( 'general.support_email', get_option( 'admin_email' ) );
		self::send( 'violation_flagged', $to, $tokens, [ 'affiliate_id' => $affiliate_id, 'reason' => $reason ] );
	}

	public function on_payout_paid( int $payout_id ): void {
		$payout = PayoutRepo::find( $payout_id );
		if ( ! $payout ) {
			return;
		}
		$affiliate = AffiliateRepo::find( (int) $payout['affiliate_id'] );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}' => self::program_name( $settings ),
			'{partner_name}' => $user->display_name,
			// The payouts table column is total_amount_cents — `amount_cents`
			// doesn't exist, so this email used to say "$0.00" every time.
			'{amount}'       => Money::format( (int) ( $payout['total_amount_cents'] ?? 0 ) ),
			'{method}'       => (string) ( $payout['method'] ?? '' ),
			'{reference}'    => (string) ( $payout['reference'] ?? '' ),
			'{portal_url}'   => self::portal_url(),
		];

		self::send( 'payout_paid', $user->user_email, $tokens, [ 'payout_id' => $payout_id ] );
	}

	public function on_commission_approved( int $commission_id ): void {
		$commission = CommissionRepo::find( $commission_id );
		if ( ! $commission ) {
			return;
		}
		$affiliate = AffiliateRepo::find( (int) $commission['affiliate_id'] );
		if ( ! $affiliate ) {
			return;
		}
		$user = get_user_by( 'id', (int) $affiliate['user_id'] );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();

		$tokens = [
			'{program_name}' => self::program_name( $settings ),
			'{partner_name}' => $user->display_name,
			'{amount}'       => Money::format( (int) ( $commission['amount_cents'] ?? 0 ) ),
			'{portal_url}'   => self::portal_url(),
		];

		self::send( 'commission_approved', $user->user_email, $tokens, [ 'commission_id' => $commission_id ] );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * @param string|string[] $to
	 * @return string[]
	 */
	private static function normalize_recipients( $to ): array {
		$out = [];
		foreach ( (array) $to as $addr ) {
			$addr = is_string( $addr ) ? trim( $addr ) : '';
			if ( '' !== $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function program_name( SettingsRepo $settings ): string {
		return (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
	}

	private static function portal_url(): string {
		$portal_id = (int) get_option( 'partner_program_portal_page_id' );
		return $portal_id ? (string) get_permalink( $portal_id ) : home_url( '/partner-portal/' );
	}

	private static function login_url( SettingsRepo $settings ): string {
		$override = (string) $settings->get( 'general.login_url', '' );
		return '' !== $override ? $override : wp_login_url();
	}
}
