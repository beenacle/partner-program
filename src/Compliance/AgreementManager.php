<?php
/**
 * Agreement bootstrap - ensures we have an initial agreement row matching settings.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Compliance;

use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class AgreementManager {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'ensure_initial_agreement' ] );
	}

	public function ensure_initial_agreement(): void {
		if ( AgreementRepo::current() ) {
			return;
		}
		$settings = new SettingsRepo();
		$body     = (string) $settings->get( 'compliance.agreement_body', '' );
		if ( '' === $body ) {
			return;
		}
		AgreementRepo::create( $body, 'Initial agreement', get_current_user_id() );
	}
}
