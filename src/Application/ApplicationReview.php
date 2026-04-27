<?php
/**
 * Admin actions for application approval / rejection.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Application;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Domain\ApplicationRepo;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class ApplicationReview {

	public function register(): void {
		add_action( 'admin_post_partner_program_review_application', [ $this, 'handle_review' ] );
	}

	public function handle_review(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ) );
		}
		check_admin_referer( 'pp_review_application' );

		$application_id = isset( $_POST['application_id'] ) ? (int) $_POST['application_id'] : 0;
		$action         = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$notes          = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['review_notes'] ) ) : '';

		$application = ApplicationRepo::find( $application_id );
		if ( ! $application ) {
			wp_safe_redirect( admin_url( 'admin.php?page=partner-program-applications' ) );
			exit;
		}

		if ( 'approve' === $action ) {
			$this->approve( $application, $notes );
		} elseif ( 'reject' === $action ) {
			ApplicationRepo::update(
				$application_id,
				[
					'status'       => 'rejected',
					'reviewer_id'  => get_current_user_id(),
					'review_notes' => $notes,
					'reviewed_at'  => current_time( 'mysql', true ),
				]
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=partner-program-applications&id=' . $application_id . '&reviewed=1' ) );
		exit;
	}

	private function approve( array $application, string $notes ): void {
		$data  = json_decode( (string) $application['submitted_data'], true ) ?: [];
		$email = (string) $application['email'];

		$user_id = email_exists( $email );
		if ( ! $user_id ) {
			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			$login      = $base_login;
			$i          = 1;
			while ( username_exists( $login ) ) {
				$login = $base_login . $i++;
			}
			$password = wp_generate_password( 16 );
			$user_id  = wp_insert_user(
				[
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => $password,
					'display_name' => (string) ( $data['full_name'] ?? $login ),
					'role'         => Capabilities::ROLE_PARTNER,
				]
			);
			if ( is_wp_error( $user_id ) ) {
				return;
			}
			wp_new_user_notification( (int) $user_id, null, 'both' );
		} else {
			$user = get_user_by( 'id', $user_id );
			if ( $user && ! in_array( Capabilities::ROLE_PARTNER, (array) $user->roles, true ) ) {
				$user->add_role( Capabilities::ROLE_PARTNER );
			}
		}

		$existing = AffiliateRepo::find_by_user( (int) $user_id );
		if ( $existing ) {
			$affiliate_id = (int) $existing['id'];
			AffiliateRepo::update(
				$affiliate_id,
				[
					'status' => 'approved',
				]
			);
		} else {
			$hint        = (string) ( $data['business_name'] ?? $data['full_name'] ?? '' );
			$code        = AffiliateRepo::generate_unique_code( $hint );
			$current_agr = AgreementRepo::current();
			$affiliate_id = AffiliateRepo::create(
				[
					'user_id'                    => (int) $user_id,
					'status'                     => 'approved',
					'referral_code'              => $code,
					'agreement_version_accepted' => $current_agr ? (int) $current_agr['id'] : null,
				]
			);
		}

		ApplicationRepo::update(
			(int) $application['id'],
			[
				'affiliate_id' => $affiliate_id,
				'status'       => 'approved',
				'reviewer_id'  => get_current_user_id(),
				'review_notes' => $notes,
				'reviewed_at'  => current_time( 'mysql', true ),
			]
		);

		do_action( 'partner_program_affiliate_approved', $affiliate_id );
		$this->send_welcome_email( (int) $user_id, $affiliate_id );
	}

	private function send_welcome_email( int $user_id, int $affiliate_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		$settings = new SettingsRepo();
		$program  = (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
		$portal_id = (int) get_option( 'partner_program_portal_page_id' );
		$portal_url = $portal_id ? get_permalink( $portal_id ) : home_url( '/partner-portal/' );

		$subject = sprintf( __( 'You are approved for %s', 'partner-program' ), $program );
		$body    = sprintf( __( 'Hi %s,', 'partner-program' ), $user->display_name ) . "\n\n";
		$body   .= sprintf( __( 'Your application for the %s has been approved.', 'partner-program' ), $program ) . "\n";
		$body   .= __( 'Log in to your partner portal to grab your referral link, coupon code, and marketing materials:', 'partner-program' ) . "\n";
		$body   .= $portal_url . "\n\n";

		wp_mail( $user->user_email, $subject, $body );
	}
}
