<?php
/**
 * Money helpers - all internal math is integer cents.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Money {

	public static function to_cents( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	public static function to_decimal( int $cents ): float {
		return round( $cents / 100, 2 );
	}

	public static function format( int $cents, string $currency = '' ): string {
		$amount = self::to_decimal( $cents );
		if ( function_exists( 'wc_price' ) ) {
			// wc_price() renders the currency symbol as an HTML entity
			// (e.g. "&#36;"). Decode it so the value is clean plain text — in
			// email subjects and other non-HTML contexts the raw entity would
			// otherwise show literally as "&#36;15.15".
			return html_entity_decode(
				wp_strip_all_tags( wc_price( $amount, $currency ? [ 'currency' => $currency ] : [] ) ),
				ENT_QUOTES,
				'UTF-8'
			);
		}
		return ( $currency ? $currency . ' ' : '' ) . number_format_i18n( $amount, 2 );
	}

	/**
	 * Locale-neutral fixed-format amount for CSV exports / data feeds:
	 * always `1234.56` regardless of WP locale or WooCommerce currency
	 * settings. Use Money::format() for human-facing UI; this is the
	 * machine-readable variant.
	 */
	public static function to_fixed( int $cents ): string {
		return number_format( self::to_decimal( $cents ), 2, '.', '' );
	}
}
