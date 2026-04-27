<?php
/**
 * Roles and capability wiring.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const ROLE_PARTNER = 'partner_program_partner';
	public const CAP_MANAGE   = 'manage_partner_program';
	public const CAP_PORTAL   = 'access_partner_portal';

	public static function register_role(): void {
		if ( ! get_role( self::ROLE_PARTNER ) ) {
			add_role(
				self::ROLE_PARTNER,
				__( 'Partner', 'partner-program' ),
				[
					'read'                       => true,
					self::CAP_PORTAL             => true,
				]
			);
		} else {
			$role = get_role( self::ROLE_PARTNER );
			$role->add_cap( 'read' );
			$role->add_cap( self::CAP_PORTAL );
		}
	}

	public static function grant_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_PORTAL );
		}
	}

	public static function user_is_partner( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( self::ROLE_PARTNER, (array) $user->roles, true );
	}
}
