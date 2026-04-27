<?php
/**
 * Template loader with theme override support.
 *
 *   templates/portal/overview.php  -> can be overridden at
 *   {theme}/partner-program/portal/overview.php
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Template {

	public static function render( string $relative_path, array $vars = [] ): string {
		$located = self::locate( $relative_path );
		if ( ! $located ) {
			return '';
		}
		ob_start();
		\extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $located;
		return (string) ob_get_clean();
	}

	public static function output( string $relative_path, array $vars = [] ): void {
		echo self::render( $relative_path, $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function locate( string $relative_path ): ?string {
		$relative_path = ltrim( $relative_path, '/' );
		$theme_path    = trailingslashit( get_stylesheet_directory() ) . 'partner-program/' . $relative_path;
		if ( file_exists( $theme_path ) ) {
			return $theme_path;
		}
		$plugin_path = PARTNER_PROGRAM_DIR . 'templates/' . $relative_path;
		return file_exists( $plugin_path ) ? $plugin_path : null;
	}
}
