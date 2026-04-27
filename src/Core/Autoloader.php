<?php
/**
 * PSR-4 autoloader so the plugin works without `composer install` on shared hosts.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX  = 'PartnerProgram\\';
	private const BASEDIR = PARTNER_PROGRAM_DIR . 'src/';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = self::BASEDIR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
