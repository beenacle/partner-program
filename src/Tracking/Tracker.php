<?php
/**
 * Referral cookie + visit tracking.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Tracking;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	public function register(): void {
		add_action( 'init', [ $this, 'capture_referral' ], 11 );
	}

	public function capture_referral(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$settings = new SettingsRepo();
		$param    = (string) $settings->get( 'tracking.param', 'ref' );
		if ( empty( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return;
		}

		$affiliate = AffiliateRepo::find_by_code( $code );
		if ( ! $affiliate || 'approved' !== $affiliate['status'] ) {
			return;
		}

		$cookie_name     = (string) $settings->get( 'tracking.cookie_name', 'pp_ref' );
		$cookie_lifetime = (int) $settings->get( 'tracking.cookie_lifetime', 30 );
		$expire          = time() + ( max( 1, $cookie_lifetime ) * DAY_IN_SECONDS );

		if ( ! headers_sent() ) {
			setcookie(
				$cookie_name,
				$code,
				[
					'expires'  => $expire,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				]
			);
		}
		$_COOKIE[ $cookie_name ] = $code;

		$this->record_visit( (int) $affiliate['id'], $code );
	}

	private function record_visit( int $affiliate_id, string $code ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pp_visits',
			[
				'affiliate_id' => $affiliate_id,
				'referral_code' => $code,
				'ip_hash'      => self::ip_hash(),
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
				'landing_url'  => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : null,
				'referrer_url' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : null,
				'created_at'   => current_time( 'mysql', true ),
			]
		);
	}

	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return $ip ? hash( 'sha256', $ip . wp_salt( 'auth' ) ) : '';
	}

	public static function current_referral_code(): ?string {
		$settings    = new SettingsRepo();
		$cookie_name = (string) $settings->get( 'tracking.cookie_name', 'pp_ref' );
		if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );
		}
		return null;
	}
}
