<?php
/**
 * Auto-create a Woo coupon per approved affiliate so coupon attribution and
 * customer discount live in one place.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Woo;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class CouponManager {

	public function register(): void {
		add_action( 'partner_program_affiliate_approved', [ $this, 'ensure_coupon_for_affiliate' ], 10, 1 );
	}

	public function ensure_coupon_for_affiliate( int $affiliate_id ): ?int {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}

		$affiliate = AffiliateRepo::find( $affiliate_id );
		if ( ! $affiliate ) {
			return null;
		}
		if ( ! empty( $affiliate['coupon_id'] ) ) {
			return (int) $affiliate['coupon_id'];
		}

		$settings = new SettingsRepo();
		if ( ! (bool) $settings->get( 'customer_coupon.auto_create', true ) ) {
			return null;
		}

		$prefix = (string) $settings->get( 'customer_coupon.prefix', 'PARTNER-' );
		$code   = strtoupper( $prefix . $affiliate['referral_code'] );

		$existing_id = wc_get_coupon_id_by_code( $code );
		if ( $existing_id ) {
			AffiliateRepo::update( $affiliate_id, [ 'coupon_id' => (int) $existing_id ] );
			return (int) $existing_id;
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( (string) $settings->get( 'customer_coupon.discount_type', 'percent' ) );
		$coupon->set_amount( (float) $settings->get( 'customer_coupon.discount_value', 10 ) );
		$coupon->set_individual_use( false );
		$coupon->set_usage_limit( 0 );
		$coupon->set_description( sprintf( __( 'Auto-generated coupon for partner %s', 'partner-program' ), $affiliate['referral_code'] ) );
		$coupon->update_meta_data( '_pp_affiliate_id', (string) $affiliate_id );
		$coupon_id = $coupon->save();

		if ( $coupon_id ) {
			AffiliateRepo::update( $affiliate_id, [ 'coupon_id' => (int) $coupon_id ] );
		}
		return $coupon_id ? (int) $coupon_id : null;
	}

	public static function affiliate_id_for_code( string $code ): ?int {
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$meta = get_post_meta( $id, '_pp_affiliate_id', true );
		return $meta ? (int) $meta : null;
	}
}
