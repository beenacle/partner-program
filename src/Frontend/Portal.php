<?php
/**
 * Partner Portal frontend (shortcode + block).
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Frontend;

use PartnerProgram\Domain\AffiliateRepo;
use PartnerProgram\Domain\AgreementRepo;
use PartnerProgram\Domain\CertificationRepo;
use PartnerProgram\Domain\CommissionRepo;
use PartnerProgram\Domain\PayoutRepo;
use PartnerProgram\Domain\TierResolver;
use PartnerProgram\Rest\RestController;
use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Encryption;
use PartnerProgram\Support\Money;
use PartnerProgram\Support\SettingsRepo;
use PartnerProgram\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Portal {

	public function register(): void {
		add_shortcode( 'partner_program_portal', [ $this, 'render_portal' ] );
		add_shortcode( 'partner_program_login', [ $this, 'render_login' ] );

		add_action( 'admin_post_nopriv_pp_portal_login', [ $this, 'handle_login' ] );
		add_action( 'admin_post_pp_portal_login', [ $this, 'handle_login' ] );
		add_action( 'admin_post_pp_portal_logout', [ $this, 'handle_logout' ] );
		add_action( 'admin_post_pp_portal_save_payout', [ $this, 'handle_save_payout_method' ] );
		add_action( 'admin_post_pp_portal_accept_agreement', [ $this, 'handle_accept_agreement' ] );
		add_action( 'admin_post_pp_portal_certify', [ $this, 'handle_certify' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		wp_register_style( 'partner-program-components', PARTNER_PROGRAM_URL . 'assets/css/components.css', [], PARTNER_PROGRAM_VERSION );
		wp_register_style( 'partner-program-portal', PARTNER_PROGRAM_URL . 'assets/css/portal.css', [ 'partner-program-components' ], PARTNER_PROGRAM_VERSION );
		wp_register_script( 'partner-program-portal', PARTNER_PROGRAM_URL . 'assets/js/portal.js', [], PARTNER_PROGRAM_VERSION, true );
		wp_register_script( 'partner-program-forms', PARTNER_PROGRAM_URL . 'assets/js/forms.js', [], PARTNER_PROGRAM_VERSION, true );
	}

	public function render_login(): string {
		if ( is_user_logged_in() ) {
			$portal_id = (int) get_option( 'partner_program_portal_page_id' );
			$url       = $portal_id ? get_permalink( $portal_id ) : home_url( '/partner-portal/' );
			return '<p>' . sprintf(
				wp_kses_post( __( 'You are logged in. <a href="%s">Go to the partner portal</a>.', 'partner-program' ) ),
				esc_url( $url )
			) . '</p>';
		}
		wp_enqueue_style( 'partner-program-portal' );
		wp_enqueue_script( 'partner-program-forms' );
		wp_localize_script(
			'partner-program-forms',
			'partnerProgramForms',
			[ 'restUrl' => rest_url( RestController::NAMESPACE . '/form-nonce' ) ]
		);
		return Template::render( 'portal/login.php', [
			'action' => esc_url( admin_url( 'admin-post.php' ) ),
			'nonce'  => wp_nonce_field( 'pp_portal_login', '_pp_login_nonce', true, false ),
			'error'  => isset( $_GET['login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['login_error'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		] );
	}

	public function render_portal(): string {
		wp_enqueue_style( 'partner-program-portal' );
		wp_enqueue_script( 'partner-program-portal' );
		wp_enqueue_script( 'partner-program-forms' );
		wp_localize_script(
			'partner-program-forms',
			'partnerProgramForms',
			[ 'restUrl' => rest_url( RestController::NAMESPACE . '/form-nonce' ) ]
		);
		wp_localize_script(
			'partner-program-portal',
			'partnerProgramPortal',
			[
				'restUrl' => rest_url( 'partner-program/v1/me/link' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
		$settings = new SettingsRepo();

		if ( ! is_user_logged_in() ) {
			$login_id = (int) get_option( 'partner_program_login_page_id' );
			$url      = $login_id ? get_permalink( $login_id ) : wp_login_url( get_permalink() );
			return '<p>' . sprintf( wp_kses_post( __( 'Please <a href="%s">log in</a> to access the partner portal.', 'partner-program' ) ), esc_url( $url ) ) . '</p>';
		}

		$user_id   = get_current_user_id();
		$affiliate = AffiliateRepo::find_by_user( $user_id );

		if ( ! $affiliate ) {
			return '<p>' . esc_html__( 'No partner account is linked to your user.', 'partner-program' ) . '</p>';
		}

		if ( 'approved' !== $affiliate['status'] ) {
			return '<p>' . esc_html__( 'Your partner account is not active.', 'partner-program' ) . '</p>';
		}

		$current_agr = AgreementRepo::current();
		$needs_accept = $current_agr && (int) $affiliate['agreement_version_accepted'] !== (int) $current_agr['id'];
		if ( $needs_accept ) {
			return Template::render(
				'portal/accept-agreement.php',
				[
					'agreement' => $current_agr,
					'action'    => esc_url( admin_url( 'admin-post.php' ) ),
					'nonce'     => wp_nonce_field( 'pp_accept_agreement', '_pp_agreement_nonce', true, false ),
				]
			);
		}

		$tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = [ 'overview', 'links', 'training', 'materials', 'compliance', 'commissions', 'payouts' ];
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'overview';
		}

		$ctx = $this->build_context( $affiliate, $settings );

		// Full-portal gate: when configured, an uncertified partner only sees
		// the training tab until they pass the quiz and e-sign.
		if ( $ctx['cert_enabled'] && 'portal' === $ctx['cert_gate_mode'] && ! $ctx['is_certified'] ) {
			$tab = 'training';
		}

		$ctx['active_tab']     = $tab;
		$ctx['portal_url']     = get_permalink();
		$ctx['logout_url']     = wp_nonce_url( add_query_arg( [ 'action' => 'pp_portal_logout' ], admin_url( 'admin-post.php' ) ), 'pp_portal_logout' );
		$ctx['certify_action'] = esc_url( admin_url( 'admin-post.php' ) );
		$ctx['certify_nonce']  = wp_nonce_field( 'pp_portal_certify', '_pp_certify_nonce', true, false );
		$ctx['settings_arr']   = $settings->all();

		return Template::render( 'portal/wrapper.php', $ctx );
	}

	public function handle_login(): void {
		// Soft nonce check: the login page is public and can be served from a
		// full-page cache with a long-expired nonce. Bounce back with a
		// friendly notice instead of WP's hard "Are you sure?" die screen.
		// assets/js/forms.js also refreshes the nonce client-side.
		$nonce = isset( $_POST['_pp_login_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_pp_login_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pp_portal_login' ) ) {
			wp_safe_redirect( add_query_arg( [ 'login_error' => 'expired' ], wp_get_referer() ?: home_url( '/' ) ) );
			exit;
		}
		$creds = [
			'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( (string) $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
			'remember'      => ! empty( $_POST['rememberme'] ),
		];
		$user = wp_signon( $creds, is_ssl() );
		$ref  = wp_get_referer() ?: home_url( '/' );
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( [ 'login_error' => 'invalid' ], $ref ) );
			exit;
		}
		$portal_id = (int) get_option( 'partner_program_portal_page_id' );
		wp_safe_redirect( $portal_id ? get_permalink( $portal_id ) : $ref );
		exit;
	}

	public function handle_logout(): void {
		check_admin_referer( 'pp_portal_logout' );
		wp_logout();
		$login_id = (int) get_option( 'partner_program_login_page_id' );
		wp_safe_redirect( $login_id ? get_permalink( $login_id ) : home_url( '/' ) );
		exit;
	}

	public function handle_save_payout_method(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		check_admin_referer( 'pp_save_payout', '_pp_save_payout_nonce' );

		$affiliate = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
			exit;
		}

		$method  = isset( $_POST['payout_method'] ) ? sanitize_key( wp_unslash( (string) $_POST['payout_method'] ) ) : '';
		$details = isset( $_POST['payout_details'] ) && is_array( $_POST['payout_details'] )
			? array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $_POST['payout_details'] ) )
			: [];

		$settings = new SettingsRepo();
		$enabled  = (array) $settings->get( 'hold_payouts.enabled_methods', [] );
		if ( '' === $method || ! in_array( $method, $enabled, true ) ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'tab' => 'payouts', 'saved' => 0, 'pp_error' => 'invalid_method' ],
					wp_get_referer() ?: home_url( '/' )
				)
			);
			exit;
		}

		// Refuse to write payout PII when libsodium is unavailable: storing
		// it as base64 would be worse than telling the partner to come back
		// once the host enables sodium.
		if ( ! Encryption::is_available() ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'tab' => 'payouts', 'saved' => 0, 'pp_error' => 'encryption_unavailable' ],
					wp_get_referer() ?: home_url( '/' )
				)
			);
			exit;
		}

		try {
			$encrypted = AffiliateRepo::encrypt_payout_details( $details );
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'tab' => 'payouts', 'saved' => 0, 'pp_error' => 'encryption_unavailable' ],
					wp_get_referer() ?: home_url( '/' )
				)
			);
			exit;
		}

		AffiliateRepo::update(
			(int) $affiliate['id'],
			[
				'payout_method'  => $method,
				'payout_details' => $encrypted,
			]
		);
		wp_safe_redirect( add_query_arg( [ 'tab' => 'payouts', 'saved' => 1 ], wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	public function handle_accept_agreement(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		check_admin_referer( 'pp_accept_agreement', '_pp_agreement_nonce' );
		$affiliate = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$current = AgreementRepo::current();
		if ( $current ) {
			AffiliateRepo::update( (int) $affiliate['id'], [ 'agreement_version_accepted' => (int) $current['id'] ] );
			AgreementRepo::record_acceptance( (int) $affiliate['id'], (int) $current['id'], \PartnerProgram\Tracking\Tracker::ip_hash() );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}

	/**
	 * Grade the certification quiz, require the e-signature/acknowledgment,
	 * and record the attempt as immutable training evidence.
	 */
	public function handle_certify(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$base = remove_query_arg(
			[ 'certified', 'cert_failed', 'cert_error', 'cert_score' ],
			wp_get_referer() ?: home_url( '/' )
		);

		// Soft nonce check, mirroring the public forms: bounce back with a
		// notice instead of WordPress's hard "Are you sure?" die screen, so a
		// partner who left the quiz tab open past the nonce lifetime doesn't
		// lose a completed, graded attempt.
		$nonce = isset( $_POST['_pp_certify_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_pp_certify_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pp_portal_certify' ) ) {
			wp_safe_redirect( add_query_arg( [ 'tab' => 'training', 'cert_error' => 'expired' ], $base ) );
			exit;
		}

		$affiliate = AffiliateRepo::find_by_user( get_current_user_id() );
		if ( ! $affiliate ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$settings    = new SettingsRepo();
		// Grade against the same gradable subset the quiz template renders, so
		// a malformed question can't sit in the denominator and make a perfect
		// score impossible.
		$questions   = CertificationRepo::gradable_questions( (array) $settings->get( 'certification.questions', [] ) );
		$pass_pct    = (float) $settings->get( 'certification.pass_pct', 80 );
		$require_sig = (bool) $settings->get( 'certification.require_signature', true );
		$version     = (int) $settings->get( 'certification.quiz_version', 1 );

		$answers_in   = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( (array) $_POST['answers'] ) : [];
		$signature    = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['signature'] ) ) : '';
		$acknowledged = ! empty( $_POST['acknowledge'] );

		// The signature and acknowledgment are the legal evidence — never
		// grade an attempt that is missing them.
		if ( ! $acknowledged || ( $require_sig && '' === $signature ) ) {
			wp_safe_redirect( add_query_arg( [ 'tab' => 'training', 'cert_error' => 'incomplete' ], $base ) );
			exit;
		}

		$total            = count( $questions );
		$correct          = 0;
		$recorded_answers = [];
		foreach ( $questions as $i => $q ) {
			$picked               = isset( $answers_in[ $i ] ) && '' !== $answers_in[ $i ] ? (int) $answers_in[ $i ] : -1;
			$recorded_answers[ $i ] = $picked;
			if ( isset( $q['correct'] ) && $picked === (int) $q['correct'] ) {
				$correct++;
			}
		}
		$score  = $total > 0 ? ( $correct / $total ) * 100 : 0.0;
		$passed = $score >= $pass_pct;

		CertificationRepo::record(
			(int) $affiliate['id'],
			$version,
			round( $score, 2 ),
			$passed,
			'' !== $signature ? $signature : null,
			\PartnerProgram\Tracking\Tracker::ip_hash(),
			[
				'answers' => $recorded_answers,
				'correct' => $correct,
				'total'   => $total,
			]
		);

		if ( $passed ) {
			do_action( 'partner_program_certified', (int) $affiliate['id'], $version, $score );
			wp_safe_redirect( add_query_arg( [ 'tab' => 'training', 'certified' => 1 ], $base ) );
		} else {
			// floor (not round) so a failing score is never displayed as the
			// pass mark, e.g. 79.6% with an 80% cut-off shows "79%", not "80%".
			wp_safe_redirect( add_query_arg( [ 'tab' => 'training', 'cert_failed' => 1, 'cert_score' => (int) floor( $score ) ], $base ) );
		}
		exit;
	}

	private function build_context( array $affiliate, SettingsRepo $settings ): array {
		$affiliate_id = (int) $affiliate['id'];
		$pending = CommissionRepo::sum_for_affiliate( $affiliate_id, 'pending' );
		$approved = CommissionRepo::sum_for_affiliate( $affiliate_id, 'approved' );
		$paid     = CommissionRepo::sum_for_affiliate( $affiliate_id, 'paid' );

		$tier_progress   = TierResolver::progress_for_affiliate( $affiliate_id );
		$stored_tier_key = (string) ( $affiliate['current_tier_key'] ?? '' );
		$stored_tier     = '' !== $stored_tier_key ? TierResolver::tier_for_key( $stored_tier_key ) : null;

		$prefix        = (string) $settings->get( 'customer_coupon.prefix', 'PARTNER-' );
		$coupon_code   = '';
		$coupon_exists = false;
		if ( ! empty( $affiliate['coupon_id'] ) && class_exists( 'WC_Coupon' ) ) {
			$wc_coupon = new \WC_Coupon( (int) $affiliate['coupon_id'] );
			if ( $wc_coupon->get_id() ) {
				$coupon_code   = $wc_coupon->get_code();
				$coupon_exists = true;
			}
		}
		if ( '' === $coupon_code ) {
			$coupon_code = strtoupper( $prefix . $affiliate['referral_code'] );
		}
		$ref_param   = (string) $settings->get( 'tracking.param', 'ref' );
		$site_url    = home_url( '/' );
		$ref_link    = add_query_arg( [ $ref_param => $affiliate['referral_code'] ], $site_url );

		$commissions = CommissionRepo::search( [ 'affiliate_id' => $affiliate_id, 'per_page' => 50 ] );
		$payouts     = PayoutRepo::search( [ 'affiliate_id' => $affiliate_id, 'per_page' => 50 ] );

		$materials = get_posts(
			[
				'post_type'      => 'pp_material',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
			]
		);

		$modules = get_posts(
			[
				'post_type'      => 'pp_module',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			]
		);

		$cert_enabled = (bool) $settings->get( 'certification.enabled', false );
		$cert_version = (int) $settings->get( 'certification.quiz_version', 1 );
		// A quiz with no answerable questions can never be passed, so never
		// gate on it — otherwise a misconfiguration would lock partners out
		// with no way to certify.
		$has_quiz     = ! empty( CertificationRepo::gradable_questions( (array) $settings->get( 'certification.questions', [] ) ) );
		// When certification is disabled (or unconfigured) there is nothing to
		// gate, so everyone counts as "certified" for gating purposes.
		$is_certified = ! $cert_enabled || ! $has_quiz || CertificationRepo::is_certified( $affiliate_id, $cert_version );
		$latest_cert  = CertificationRepo::latest_passed( $affiliate_id, $cert_version );

		return [
			'affiliate'     => $affiliate,
			'user'          => wp_get_current_user(),
			'pending_cents' => $pending,
			'approved_cents'=> $approved,
			'paid_cents'    => $paid,
			'tier_progress' => $tier_progress,
			'stored_tier'   => $stored_tier,
			'tiers'         => TierResolver::tiers(),
			'coupon_code'   => $coupon_code,
			'coupon_exists' => $coupon_exists,
			'ref_link'      => $ref_link,
			'ref_param'     => $ref_param,
			'commissions'   => $commissions,
			'payouts'       => $payouts,
			'materials'     => $materials,
			'modules'       => $modules,
			'cert_enabled'   => $cert_enabled,
			'cert_gate_mode' => (string) $settings->get( 'certification.gate_mode', 'links' ),
			'cert_version'   => $cert_version,
			'is_certified'   => $is_certified,
			'latest_cert'    => $latest_cert,
			'cert_settings'  => (array) $settings->get( 'certification', [] ),
			'agreement'     => AgreementRepo::current(),
			'settings'      => $settings,
			'enabled_methods' => (array) $settings->get( 'hold_payouts.enabled_methods', [] ),
			'min_threshold_cents' => Money::to_cents( (float) $settings->get( 'hold_payouts.min_threshold', 100 ) ),
		];
	}
}
