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
		$ip_hash = self::ip_hash();

		// Best-effort dedup: a single browser bouncing between pages with the
		// ?ref= param shouldn't add a new visit row each request. We coalesce
		// hits from the same IP+code within a 1-hour window via a transient.
		// Transients fall back to options when no object cache is present, so
		// this also works on vanilla shared hosting.
		$dedup_key = 'pp_visit_' . md5( $code . '|' . $ip_hash );
		if ( $ip_hash && get_transient( $dedup_key ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pp_visits',
			[
				'affiliate_id'  => $affiliate_id,
				'referral_code' => $code,
				'ip_hash'       => $ip_hash,
				'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
				'landing_url'   => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : null,
				'referrer_url'  => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : null,
				'created_at'    => current_time( 'mysql', true ),
			]
		);

		if ( $ip_hash ) {
			set_transient( $dedup_key, 1, HOUR_IN_SECONDS );
		}
	}

	public static function ip_hash(): string {
		$ip = self::client_ip();
		return $ip ? hash( 'sha256', $ip . wp_salt( 'auth' ) ) : '';
	}

	/**
	 * Resolve the visitor's IP, optionally honouring the most common proxy
	 * headers when the admin has explicitly opted in via
	 * tracking.trust_proxy_header. We default to OFF because trusting
	 * proxy headers without one in front of WordPress lets visitors spoof
	 * their IP and bypass the application-form rate limit.
	 *
	 * Order when the toggle is ON:
	 *   1. CF-Connecting-IP (Cloudflare's "real client" header)
	 *   2. X-Forwarded-For (first hop — the leftmost address)
	 *   3. REMOTE_ADDR
	 *
	 * Each candidate is validated as a routable IP; bogus values are
	 * skipped rather than trusted. When the toggle is OFF the function
	 * is identical to a plain REMOTE_ADDR read.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$repo  = new SettingsRepo();
		$trust = (bool) $repo->get( 'tracking.trust_proxy_header', false );
		if ( ! $trust ) {
			// Preserve historical behaviour: trust REMOTE_ADDR as-is so that
			// dev environments / behind-the-VPN visits don't silently lose
			// their visit row. Validation is only meaningful upstream of an
			// untrusted hop.
			return $remote;
		}

		$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
		if ( '' !== $cf && self::valid_public_ip( $cf ) ) {
			return $cf;
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			if ( '' !== $first && self::valid_public_ip( $first ) ) {
				return $first;
			}
		}

		return $remote;
	}

	/**
	 * Validate a header-supplied IP for the proxy-header path. We require
	 * a routable address here because anyone can stuff a private IP into
	 * X-Forwarded-For; we don't want spoofed values to slip past the
	 * application-form rate limit.
	 */
	private static function valid_public_ip( string $ip ): bool {
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
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
