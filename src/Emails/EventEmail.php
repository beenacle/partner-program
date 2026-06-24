<?php
/**
 * One WooCommerce-native email per partner-program lifecycle event.
 *
 * Extending WC_Email makes each event appear under WooCommerce > Settings >
 * Emails with the standard enable / recipient / subject / heading / email-type
 * controls, preview, and "send test" — and renders through the store's email
 * template. The plugin's existing `partner_program_*` handlers still build the
 * tokens and call Mailer::send(), which delegates to the matching instance here.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Emails;

use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

// WC_Email only exists once WooCommerce is loaded; this file is autoloaded
// lazily (inside the woocommerce_email_classes filter / a send), so the parent
// is always available when the class is actually evaluated.
if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

class EventEmail extends \WC_Email {

	/** @var string */
	public $event_key;

	/** @var array<string,string> */
	private $pp_tokens = [];

	/** @var string */
	private $pp_default_subject = '';

	/** @var string */
	private $pp_default_body = '';

	/** @var bool */
	private $pp_default_enabled = true;

	public function __construct( string $event_key = '' ) {
		$def = EventRegistry::get( $event_key ) ?: [];

		$this->event_key          = $event_key;
		$this->id                 = 'pp_' . $event_key;
		$this->title              = (string) ( $def['label'] ?? $event_key );
		$this->description        = (string) ( $def['description'] ?? '' );
		$this->customer_email     = ( 'partner' === ( $def['audience'] ?? 'partner' ) );
		$this->pp_default_subject = (string) ( $def['subject'] ?? '' );
		$this->pp_default_body    = (string) ( $def['body'] ?? '' );
		$this->pp_default_enabled = ! empty( $def['default_enabled'] );

		// We render content ourselves (header + body + footer); no template file.
		$this->template_html  = '';
		$this->template_plain = '';

		parent::__construct();

		// Admin emails: honour the recipient configured in WooCommerce >
		// Settings > Emails, falling back to the program support email.
		if ( ! $this->customer_email ) {
			$this->recipient = (string) $this->get_option( 'recipient', self::admin_recipient() );
		}
	}

	public function init_form_fields(): void {
		parent::init_form_fields();
		// Default the enable toggle to the event's configured default.
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = $this->pp_default_enabled ? 'yes' : 'no';
		}
		// Seed the subject default from the event definition.
		if ( isset( $this->form_fields['subject'] ) ) {
			$this->form_fields['subject']['default']     = $this->pp_default_subject;
			$this->form_fields['subject']['placeholder'] = $this->pp_default_subject;
		}
		// Admin-audience emails get a recipient field (customer emails are
		// addressed to the partner at trigger time).
		if ( ! $this->customer_email ) {
			$this->form_fields = array_merge(
				[
					'recipient' => [
						'title'       => __( 'Recipient(s)', 'partner-program' ),
						'type'        => 'text',
						'description' => __( 'Comma-separated. Defaults to the program support email.', 'partner-program' ),
						'placeholder' => self::admin_recipient(),
						'default'     => self::admin_recipient(),
						'desc_tip'    => true,
					],
				],
				$this->form_fields
			);
		}
	}

	/**
	 * Render + send for one fired event. Called by Mailer::send().
	 *
	 * @param string|string[]     $to
	 * @param array<string,mixed> $tokens
	 */
	public function trigger_event( $to, array $tokens ): void {
		$this->setup_locale();
		$this->pp_tokens = array_map( static fn ( $v ): string => (string) $v, $tokens );

		// Partner emails go to the supplied address; admin emails use the
		// configured recipient setting.
		if ( $this->customer_email ) {
			$this->recipient = is_array( $to ) ? implode( ',', $to ) : (string) $to;
		}

		// If the program has a custom footer configured, apply it for this send
		// only (WC owns the footer template natively otherwise). Registered
		// before send and removed in finally so it can never leak into other
		// store emails even if send() throws.
		$footer = (string) ( new SettingsRepo() )->get( 'emails.footer_text', '' );
		$cb     = null;
		if ( '' !== trim( $footer ) ) {
			$cb = static fn (): string => $footer;
			add_filter( 'woocommerce_email_footer_text', $cb, 99 );
		}

		try {
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			if ( $cb ) {
				remove_filter( 'woocommerce_email_footer_text', $cb, 99 );
			}
			$this->restore_locale();
		}
	}

	/**
	 * Honour the program's configured From name/address when set, falling back
	 * to WooCommerce's native sender so the setting stays meaningful.
	 */
	public function get_from_name( $from_name = '' ): string {
		$name = (string) ( new SettingsRepo() )->get( 'emails.from_name', '' );
		return '' !== trim( $name ) ? wp_specialchars_decode( esc_html( $name ), ENT_QUOTES ) : parent::get_from_name( $from_name );
	}

	public function get_from_address( $from_address = '' ): string {
		$addr = (string) ( new SettingsRepo() )->get( 'emails.from_email', '' );
		return ( '' !== trim( $addr ) && is_email( $addr ) ) ? sanitize_email( $addr ) : parent::get_from_address( $from_address );
	}

	public function get_subject(): string {
		$subject = strtr( (string) $this->get_option( 'subject', $this->pp_default_subject ), $this->pp_tokens );
		$subject = wp_specialchars_decode( wp_strip_all_tags( $subject ), ENT_QUOTES );
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $subject, $this->object, $this );
	}

	public function get_heading(): string {
		$heading = (string) $this->get_option( 'heading', '' );
		$heading = '' !== trim( $heading ) ? $heading : (string) $this->get_option( 'subject', $this->pp_default_subject );
		$heading = strtr( $heading, $this->pp_tokens );
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $heading, $this->object, $this );
	}

	public function get_content_html(): string {
		return wc_get_template_html( 'emails/email-header.php', [ 'email_heading' => $this->get_heading(), 'email' => $this ] )
			. wpautop( wp_kses_post( $this->pp_body() ) )
			. wc_get_template_html( 'emails/email-footer.php', [ 'email' => $this ] );
	}

	public function get_content_plain(): string {
		return wp_strip_all_tags( $this->pp_body() );
	}

	/**
	 * Body source of truth: a per-event body override saved on the plugin's
	 * Emails settings tab, else the EventRegistry default — token-replaced.
	 */
	private function pp_body(): string {
		$override = (string) ( new SettingsRepo() )->get( 'emails.events.' . $this->event_key . '.body', '' );
		$body     = '' !== trim( $override ) ? $override : $this->pp_default_body;
		return strtr( $body, $this->pp_tokens );
	}

	private static function admin_recipient(): string {
		return (string) ( new SettingsRepo() )->get( 'general.support_email', get_option( 'admin_email' ) );
	}
}
