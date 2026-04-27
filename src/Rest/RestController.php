<?php
/**
 * REST API for portal AJAX (stats, link builder).
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Rest;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Support\SettingsRepo;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RestController {

	public const NAMESPACE = 'partner-program/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/me/stats', [
			'methods'  => 'GET',
			'callback' => [ $this, 'me_stats' ],
			'permission_callback' => [ $this, 'is_partner' ],
		] );
		register_rest_route( self::NAMESPACE, '/me/link', [
			'methods'  => 'POST',
			'callback' => [ $this, 'me_link' ],
			'permission_callback' => [ $this, 'is_partner' ],
			'args'     => [
				'url' => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function is_partner(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$aff = AffiliateRepo::find_by_user( get_current_user_id() );
		return $aff && 'approved' === $aff['status'];
	}

	public function me_stats(): WP_REST_Response {
		$aff = AffiliateRepo::find_by_user( get_current_user_id() );
		$id  = (int) $aff['id'];
		return new WP_REST_Response( [
			'pending_cents'  => CommissionRepo::sum_for_affiliate( $id, 'pending' ),
			'approved_cents' => CommissionRepo::sum_for_affiliate( $id, 'approved' ),
			'paid_cents'     => CommissionRepo::sum_for_affiliate( $id, 'paid' ),
			'tier_progress'  => TierResolver::progress_for_affiliate( $id ),
		] );
	}

	public function me_link( WP_REST_Request $req ) {
		$aff = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $aff ) {
			return new WP_Error( 'no_affiliate', 'No affiliate', [ 'status' => 404 ] );
		}
		$url = esc_url_raw( (string) $req->get_param( 'url' ) );
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		$settings = new SettingsRepo();
		$param    = (string) $settings->get( 'tracking.param', 'ref' );
		return new WP_REST_Response( [
			'link' => add_query_arg( [ $param => $aff['referral_code'] ], $url ),
		] );
	}
}
