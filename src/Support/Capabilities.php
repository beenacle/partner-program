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

	/**
	 * Primitive caps for the pp_material CPT, paired with the
	 * `capability_type => ['pp_material','pp_materials']` registration in
	 * AdminMenu. We ship the pluralised set so map_meta_cap can derive
	 * the meta-caps (edit_post, delete_post, read_post). Granted to the
	 * administrator role; the partner role only gets read access.
	 *
	 * @return array<int, string>
	 */
	public static function material_admin_caps(): array {
		return [
			'edit_pp_material',
			'read_pp_material',
			'delete_pp_material',
			'edit_pp_materials',
			'edit_others_pp_materials',
			'publish_pp_materials',
			'read_private_pp_materials',
			'delete_pp_materials',
			'delete_private_pp_materials',
			'delete_published_pp_materials',
			'delete_others_pp_materials',
			'edit_private_pp_materials',
			'edit_published_pp_materials',
		];
	}

	/**
	 * Primitive caps for the pp_module (training) CPT, mirroring
	 * material_admin_caps(). Granted to the administrator; the partner role
	 * only gets read access.
	 *
	 * @return array<int, string>
	 */
	public static function module_admin_caps(): array {
		return [
			'edit_pp_module',
			'read_pp_module',
			'delete_pp_module',
			'edit_pp_modules',
			'edit_others_pp_modules',
			'publish_pp_modules',
			'read_private_pp_modules',
			'delete_pp_modules',
			'delete_private_pp_modules',
			'delete_published_pp_modules',
			'delete_others_pp_modules',
			'edit_private_pp_modules',
			'edit_published_pp_modules',
		];
	}

	public static function register_role(): void {
		if ( ! get_role( self::ROLE_PARTNER ) ) {
			add_role(
				self::ROLE_PARTNER,
				__( 'Partner', 'partner-program' ),
				[
					'read'                       => true,
					self::CAP_PORTAL             => true,
					'read_pp_material'           => true,
					'read_pp_module'             => true,
				]
			);
		} else {
			$role = get_role( self::ROLE_PARTNER );
			$role->add_cap( 'read' );
			$role->add_cap( self::CAP_PORTAL );
			$role->add_cap( 'read_pp_material' );
			$role->add_cap( 'read_pp_module' );
		}
	}

	public static function grant_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_PORTAL );
			foreach ( self::material_admin_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
			foreach ( self::module_admin_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function user_is_partner( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( self::ROLE_PARTNER, (array) $user->roles, true );
	}
}
