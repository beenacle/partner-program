<?php
/**
 * Symmetric encryption helper for sensitive payout details.
 * Uses libsodium when available; falls back to base64 (with warning) otherwise.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Encryption {

	private const PREFIX_SODIUM = 'pps1:';
	private const PREFIX_PLAIN  = 'ppp1:';

	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$key   = $this->derive_key();
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
		}

		return self::PREFIX_PLAIN . base64_encode( $plaintext );
	}

	public function decrypt( string $blob ): string {
		if ( '' === $blob ) {
			return '';
		}

		if ( 0 === strpos( $blob, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $blob, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key    = $this->derive_key();
			$result = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $result ? '' : $result;
		}

		if ( 0 === strpos( $blob, self::PREFIX_PLAIN ) ) {
			return (string) base64_decode( substr( $blob, strlen( self::PREFIX_PLAIN ) ), true );
		}

		return '';
	}

	private function derive_key(): string {
		$salt = wp_salt( 'auth' );
		return substr( hash( 'sha256', 'partner-program|' . $salt, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
