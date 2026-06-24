<?php
/**
 * Certification repository - immutable training-quiz attempt records.
 *
 * Each passing row is the audit trail that a partner completed compliance
 * training and electronically acknowledged it, mirroring the agreement
 * acceptance evidence in AgreementRepo.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Domain;

defined( 'ABSPATH' ) || exit;

final class CertificationRepo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_certifications';
	}

	/**
	 * @param array<string, mixed> $extra answers / metadata, JSON-encoded.
	 */
	public static function record(
		int $affiliate_id,
		int $quiz_version,
		float $score_pct,
		bool $passed,
		?string $signature,
		?string $ip_hash,
		array $extra = []
	): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'affiliate_id' => $affiliate_id,
				'quiz_version' => $quiz_version,
				'score_pct'    => $score_pct,
				'passed'       => $passed ? 1 : 0,
				'signature'    => $signature,
				'ip_hash'      => $ip_hash,
				'answers'      => $extra ? wp_json_encode( $extra ) : null,
				'created_at'   => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function latest_for_affiliate( int $affiliate_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE affiliate_id = %d ORDER BY created_at DESC LIMIT 1',
				$affiliate_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Most recent passing attempt that satisfies the required quiz version.
	 */
	public static function latest_passed( int $affiliate_id, int $required_version = 1 ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE affiliate_id = %d AND passed = 1 AND quiz_version >= %d ORDER BY created_at DESC LIMIT 1',
				$affiliate_id,
				$required_version
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Whether the affiliate holds a current certification. A partner who
	 * passed an older quiz version must re-certify once the merchant bumps
	 * `certification.quiz_version`, so the evidence always matches the
	 * compliance content that was actually in force.
	 */
	public static function is_certified( int $affiliate_id, int $required_version = 1 ): bool {
		return null !== self::latest_passed( $affiliate_id, $required_version );
	}

	/**
	 * The subset of quiz questions that are actually answerable: a non-empty
	 * prompt and at least two options. Single source of truth so the quiz
	 * template, the grader, and the gate all count the same questions — a
	 * mismatch would let a malformed question silently cap the achievable
	 * score below the pass mark and lock a partner out for good.
	 *
	 * @param array<int, mixed> $questions
	 * @return array<int, array<string, mixed>>
	 */
	public static function gradable_questions( array $questions ): array {
		return array_values(
			array_filter(
				$questions,
				static function ( $q ): bool {
					return is_array( $q )
						&& '' !== trim( (string) ( $q['q'] ?? '' ) )
						&& ! empty( $q['options'] )
						&& count( (array) $q['options'] ) >= 2;
				}
			)
		);
	}

	/**
	 * Whether the certification gate currently withholds referral links and
	 * coupon promotion from this affiliate. Single source of truth shared by
	 * the portal Links tab and the /me/link REST endpoint so the AJAX route
	 * can't be used to bypass the on-screen lock.
	 */
	public static function links_locked( int $affiliate_id ): bool {
		$s = new \PartnerProgram\Support\SettingsRepo();
		if ( ! (bool) $s->get( 'certification.enabled', false ) ) {
			return false;
		}
		if ( 'none' === (string) $s->get( 'certification.gate_mode', 'links' ) ) {
			return false;
		}
		return ! self::is_certified( $affiliate_id, (int) $s->get( 'certification.quiz_version', 1 ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_affiliate( int $affiliate_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE affiliate_id = %d ORDER BY created_at DESC',
				$affiliate_id
			),
			ARRAY_A
		) ?: [];
	}
}
