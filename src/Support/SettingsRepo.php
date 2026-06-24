<?php
/**
 * Settings repository - single JSON blob in wp_options with default fallbacks.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

use PartnerProgram\Emails\EventRegistry;

defined( 'ABSPATH' ) || exit;

final class SettingsRepo {

	public const OPTION = 'partner_program_settings';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * Built-in defaults. Anything not set in wp_options falls through to these.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'general'      => [
				'program_name'   => __( 'Partner Program', 'partner-program' ),
				'logo_url'       => '',
				'support_email'  => get_option( 'admin_email' ),
				'accent_color'   => '#2563eb',
				'terms_url'      => '',
				'login_url'      => '',
			],
			'account_menu' => [
				// Show a Partner Portal link in the WooCommerce My Account menu
				// (only to approved partners). Toggle off to hide it entirely.
				'enabled'     => true,
				'label'       => __( 'Partner Portal', 'partner-program' ),
				// Also show a "Become a Partner" link to logged-in non-partners.
				'show_apply'  => false,
				'apply_label' => __( 'Become a Partner', 'partner-program' ),
			],
			'commissions'  => [
				'base_rate'              => 15.0,
				'calculation_basis'      => 'subtotal_after_discount',
				'exclude_shipping'       => true,
				'exclude_tax'            => true,
				'partial_refund_clawback' => true,
			],
			'tiers'        => [
				[ 'key' => 'bronze', 'min' => 0,     'max' => 4999,  'rate' => 15.0, 'label' => __( 'Bronze', 'partner-program' ) ],
				[ 'key' => 'silver', 'min' => 5000,  'max' => 14999, 'rate' => 18.0, 'label' => __( 'Silver', 'partner-program' ) ],
				[ 'key' => 'gold',   'min' => 15000, 'max' => null,  'rate' => 22.0, 'label' => __( 'Gold',   'partner-program' ) ],
			],
			'coupon_bonus' => [
				'enabled'    => true,
				'bonus_rate' => 2.0,
			],
			'customer_coupon' => [
				'auto_create'    => true,
				'discount_type'  => 'percent',
				'discount_value' => 10.0,
				'prefix'         => 'PARTNER-',
			],
			'tracking'     => [
				'cookie_name'         => 'pp_ref',
				'cookie_lifetime'     => 30,
				'param'               => 'ref',
				'rewrite_slug'        => '',
				'trust_proxy_header'  => false,
				// Days of click/visit history to keep in pp_visits before the
				// daily prune cron deletes it. 0 = keep forever.
				'visit_retention_days' => 90,
			],
			'attribution'  => [
				// Inherit attribution from the parent subscription onto each
				// renewal order. Only takes effect when WooCommerce
				// Subscriptions is active; harmless otherwise.
				'subscription_renewals' => true,
			],
			'hold_payouts' => [
				'hold_days'        => 15,
				'schedule'         => 'monthly',
				'payout_day'       => 1,
				'min_threshold'    => 100.0,
				'enabled_methods'  => [ 'ach', 'paypal', 'zelle', 'cashapp', 'wise' ],
			],
			'application'  => [
				'intro_html' => self::default_application_intro(),
				'policy_url' => '',
				'fields'     => self::default_application_fields(),
			],
			'certification' => [
				// Master switch for the training quiz / certification gate.
				// Off by default so upgrading sites don't silently lock every
				// existing partner's links; merchants opt in from the
				// Certification settings tab.
				'enabled'             => false,
				// 'none'   - track completions but never block anything.
				// 'links'  - hide referral links/coupon until certified.
				// 'portal' - block the whole portal (except training) until certified.
				'gate_mode'           => 'links',
				'pass_pct'            => 80,
				'require_signature'   => true,
				// Bump this when quiz content changes so every partner must
				// re-certify against the compliance material currently in force.
				'quiz_version'        => 1,
				'acknowledgment_html' => self::default_certification_acknowledgment(),
				'questions'           => self::default_certification_questions(),
			],
			'compliance'   => [
				'prohibited_terms' => [
					'human use', 'dosing', 'mixing', 'administration',
					'weight loss', 'fertility', 'bodybuilding results',
					'medical claim', 'therapeutic',
				],
				'penalty_text' => __(
					'Violations result in immediate termination, forfeiture of unpaid commissions, and possible clawback of paid commissions.',
					'partner-program'
				),
				'agreement_body' => self::default_agreement_body(),
				'clawback_days'  => 90,
				'auto_suspend_on_violation' => true,
			],
			'exclusions'   => [
				'reject_refunded'     => true,
				'reject_cancelled'    => true,
				'reject_failed'       => true,
				'fraud_meta_key'      => '_pp_fraud_risk',
				'compliance_meta_key' => '_pp_compliance_violation',
			],
			'logs'         => [
				// Days of history to retain in the pp_logs table. 0 = keep
				// forever; the daily prune cron is a no-op in that case.
				'retention_days' => 90,
			],
			'emails'       => [
				'from_name'   => '',
				'from_email'  => '',
				'footer_text' => '',
				'events'      => EventRegistry::settings_defaults(),
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function default_application_fields(): array {
		return [
			[ 'key' => 'full_name', 'label' => __( 'Full name', 'partner-program' ), 'type' => 'text', 'required' => true ],
			[ 'key' => 'business_name', 'label' => __( 'Business / brand name', 'partner-program' ), 'type' => 'text', 'required' => false ],
			[ 'key' => 'email', 'label' => __( 'Email', 'partner-program' ), 'type' => 'email', 'required' => true ],
			[ 'key' => 'phone', 'label' => __( 'Phone', 'partner-program' ), 'type' => 'text', 'required' => false ],
			[ 'key' => 'website', 'label' => __( 'Website / social links', 'partner-program' ), 'type' => 'textarea', 'required' => true ],
			[ 'key' => 'audience_type', 'label' => __( 'Audience type', 'partner-program' ), 'type' => 'select', 'required' => true,
				'options' => [
					[ 'value' => 'researchers',     'label' => __( 'Researchers / scientists', 'partner-program' ) ],
					[ 'value' => 'lab',             'label' => __( 'Lab / institution', 'partner-program' ) ],
					[ 'value' => 'content_creator', 'label' => __( 'Content creator', 'partner-program' ) ],
					[ 'value' => 'newsletter',      'label' => __( 'Newsletter / mailing list', 'partner-program' ) ],
					[ 'value' => 'social_media',    'label' => __( 'Social media', 'partner-program' ) ],
					[ 'value' => 'other',           'label' => __( 'Other', 'partner-program' ) ],
				],
			],
			[ 'key' => 'promotion_plan', 'label' => __( 'How will you promote the program?', 'partner-program' ), 'type' => 'textarea', 'required' => true ],
			[ 'key' => 'id_upload', 'label' => __( 'ID / business proof (PDF or image)', 'partner-program' ), 'type' => 'file', 'required' => false ],
			[ 'key' => 'compliance_agreement', 'label' => __( 'I have read and agree to the Affiliate Compliance Policy.', 'partner-program' ), 'type' => 'checkbox', 'required' => true ],
		];
	}

	/**
	 * Research-Use-Only acknowledgment shown above the public application
	 * form. `{program_name}` is substituted at render time so the copy
	 * white-labels per site.
	 */
	private static function default_application_intro(): string {
		return '<p>' . esc_html__(
			'By applying to the {program_name}, you acknowledge that our products are sold for research use only and agree not to market products for human consumption, weight loss, treatment, diagnosis, or medical purposes.',
			'partner-program'
		) . '</p>';
	}

	/**
	 * Electronic-signature acknowledgment shown at the end of the
	 * certification quiz. `{program_name}` is substituted at render time.
	 */
	private static function default_certification_acknowledgment(): string {
		return '<p>' . esc_html__( 'I certify that I have read and understood the Research-Use-Only (RUO) Affiliate Compliance Guide for the {program_name}, and I agree to the following:', 'partner-program' ) . '</p>'
			. '<ul>'
			. '<li>' . esc_html__( 'I will describe products only as research-use-only materials and never market them for human consumption, dosing, injection, treatment, diagnosis, or weight loss.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'I will include the required affiliate and research-use-only disclosure on every post or endorsement.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'I will not provide dosing, reconstitution, injection, or personal-use instructions, or position any product as a substitute for Ozempic, Wegovy, Mounjaro, or Zepbound.', 'partner-program' ) . '</li>'
			. '<li>' . esc_html__( 'I understand that violations may result in termination from the program and forfeiture of unpaid commissions.', 'partner-program' ) . '</li>'
			. '</ul>';
	}

	/**
	 * Default 15-question compliance quiz. Each question is
	 * `[ 'q' => string, 'options' => string[], 'correct' => int ]` where
	 * `correct` is the zero-based index of the right option.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function default_certification_questions(): array {
		return [
			[
				'q'       => __( '"Research Use Only" (RUO) means the products are intended for:', 'partner-program' ),
				'options' => [
					__( 'Personal wellness and supplementation', 'partner-program' ),
					__( 'Laboratory research use only, by qualified professionals', 'partner-program' ),
					__( 'Treating medical conditions under a doctor\'s care', 'partner-program' ),
					__( 'Weight loss when diet and exercise fail', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Which statement is compliant when describing a product?', 'partner-program' ),
				'options' => [
					__( '"This peptide helps you lose weight fast."', 'partner-program' ),
					__( '"Here is how to dose it for the best results."', 'partner-program' ),
					__( '"This is a research-use-only compound, not intended for human consumption."', 'partner-program' ),
					__( '"It works just like Ozempic."', 'partner-program' ),
				],
				'correct' => 2,
			],
			[
				'q'       => __( 'A customer asks how much to inject. You should:', 'partner-program' ),
				'options' => [
					__( 'Give them a typical starting amount', 'partner-program' ),
					__( 'Explain that you cannot provide dosing, injection, or personal-use instructions', 'partner-program' ),
					__( 'Refer them to a forum with dosing charts', 'partner-program' ),
					__( 'Tell them to start low and increase weekly', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Which word should you AVOID and replace with compliant language?', 'partner-program' ),
				'options' => [
					__( 'research compound', 'partner-program' ),
					__( 'study concentration', 'partner-program' ),
					__( 'dose', 'partner-program' ),
					__( 'investigational compound', 'partner-program' ),
				],
				'correct' => 2,
			],
			[
				'q'       => __( 'The compliant alternative for "weight loss" is:', 'partner-program' ),
				'options' => [
					__( 'fat burning', 'partner-program' ),
					__( 'metabolic research', 'partner-program' ),
					__( 'slimming results', 'partner-program' ),
					__( 'appetite control', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Every affiliate post must include:', 'partner-program' ),
				'options' => [
					__( 'A discount code only', 'partner-program' ),
					__( 'A disclosure that you may earn a commission and that products are research use only, not for human consumption', 'partner-program' ),
					__( 'A photo of the product', 'partner-program' ),
					__( 'Nothing in particular', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'The FTC requires that paid endorsements and health-related claims be:', 'partner-program' ),
				'options' => [
					__( 'Exciting and persuasive', 'partner-program' ),
					__( 'Truthful, non-misleading, and properly disclosed', 'partner-program' ),
					__( 'Reviewed by a doctor', 'partner-program' ),
					__( 'Posted only on weekdays', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Which of the following can an affiliate help a customer with?', 'partner-program' ),
				'options' => [
					__( 'How to reconstitute a compound for personal use', 'partner-program' ),
					__( 'How much to take and how often', 'partner-program' ),
					__( 'Where to find published scientific literature about a compound', 'partner-program' ),
					__( 'Which product to buy for weight loss', 'partner-program' ),
				],
				'correct' => 2,
			],
			[
				'q'       => __( 'A follower asks you to recommend a product as a substitute for Wegovy or Mounjaro. You should:', 'partner-program' ),
				'options' => [
					__( 'Recommend the closest matching product', 'partner-program' ),
					__( 'Decline — you cannot position products as medical substitutes for GLP-1 drugs', 'partner-program' ),
					__( 'Compare the two in detail', 'partner-program' ),
					__( 'Provide a dosing equivalent', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( '"Reconstitute for lab research only" is the compliant replacement for:', 'partner-program' ),
				'options' => [
					__( 'inject', 'partner-program' ),
					__( 'research', 'partner-program' ),
					__( 'study', 'partner-program' ),
					__( 'evaluate', 'partner-program' ),
				],
				'correct' => 0,
			],
			[
				'q'       => __( 'Which statement is a prohibited claim?', 'partner-program' ),
				'options' => [
					__( '"Published studies have investigated this compound\'s mechanism of action."', 'partner-program' ),
					__( '"These products are research-use-only materials."', 'partner-program' ),
					__( '"This treatment is safe and effective for weight loss."', 'partner-program' ),
					__( '"I cannot provide personal-use instructions."', 'partner-program' ),
				],
				'correct' => 2,
			],
			[
				'q'       => __( 'When discussing what a compound does, you should refer to:', 'partner-program' ),
				'options' => [
					__( 'the "results" people experienced', 'partner-program' ),
					__( '"research findings" and "published data"', 'partner-program' ),
					__( '"before and after" transformations', 'partner-program' ),
					__( '"guaranteed outcomes"', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Products sold for research use only are:', 'partner-program' ),
				'options' => [
					__( 'FDA-approved for personal use', 'partner-program' ),
					__( 'Dietary supplements', 'partner-program' ),
					__( 'Not medications, supplements, or weight-loss products', 'partner-program' ),
					__( 'Intended to diagnose and treat disease', 'partner-program' ),
				],
				'correct' => 2,
			],
			[
				'q'       => __( 'If you are unsure whether a post is compliant, the safest action is to:', 'partner-program' ),
				'options' => [
					__( 'Post it and see what happens', 'partner-program' ),
					__( 'Keep it research-focused, include the required disclosures, and avoid human-use or dosing language', 'partner-program' ),
					__( 'Copy what other affiliates post', 'partner-program' ),
					__( 'Add more enthusiastic claims', 'partner-program' ),
				],
				'correct' => 1,
			],
			[
				'q'       => __( 'Violating the compliance policy can result in:', 'partner-program' ),
				'options' => [
					__( 'A friendly reminder only', 'partner-program' ),
					__( 'No consequences', 'partner-program' ),
					__( 'Termination from the program and forfeiture of unpaid commissions', 'partner-program' ),
					__( 'A bonus commission', 'partner-program' ),
				],
				'correct' => 2,
			],
		];
	}

	private static function default_agreement_body(): string {
		return '<p>' . esc_html__( 'By participating in this Partner Program, you agree to promote products in accordance with all applicable Research Use Only (RUO) regulations and compliance standards.', 'partner-program' ) . '</p>'
			. '<h3>' . esc_html__( 'Prohibited claims', 'partner-program' ) . '</h3>'
			. '<p>' . esc_html__( 'You may not reference human use, dosing, mixing, administration, weight loss, fertility, bodybuilding results, or any medical or therapeutic claims.', 'partner-program' ) . '</p>'
			. '<h3>' . esc_html__( 'Penalty for violation', 'partner-program' ) . '</h3>'
			. '<p>' . esc_html__( 'Violations result in immediate termination, forfeiture of unpaid commissions, and possible clawback of paid commissions.', 'partner-program' ) . '</p>';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = self::deep_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return self::$cache;
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$parts = explode( '.', $path );
		$value = $this->all();
		foreach ( $parts as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $values Whole settings tree (sections).
	 */
	public function save_section( string $section, array $values ): void {
		$all              = $this->all();
		$all[ $section ]  = self::deep_merge( $all[ $section ] ?? [], $values );
		self::$cache      = $all;
		update_option( self::OPTION, $all, false );
	}

	public function replace_all( array $values ): void {
		self::$cache = self::deep_merge( self::defaults(), $values );
		update_option( self::OPTION, self::$cache, false );
	}

	/**
	 * Filter an arbitrary array down to the set of known top-level
	 * sections, dropping anything we don't recognise. Used by the import
	 * handler so a malformed or hostile JSON file can't poison the
	 * settings blob with arbitrary keys.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function filter_for_import( array $values ): array {
		$allowed = array_keys( self::defaults() );
		$out     = [];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $values ) && is_array( $values[ $key ] ) ) {
				$out[ $key ] = $values[ $key ];
			}
		}
		return $out;
	}

	public function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defaults(), false );
		}
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $a, array $b ): array {
		foreach ( $b as $key => $value ) {
			if ( is_array( $value ) && isset( $a[ $key ] ) && is_array( $a[ $key ] ) && self::is_assoc( $a[ $key ] ) && self::is_assoc( $value ) ) {
				$a[ $key ] = self::deep_merge( $a[ $key ], $value );
			} else {
				$a[ $key ] = $value;
			}
		}
		return $a;
	}

	private static function is_assoc( array $arr ): bool {
		if ( [] === $arr ) {
			return true;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
