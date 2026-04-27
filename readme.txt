=== Partner Program for WooCommerce ===
Contributors: beenacle
Tags: affiliate, partner, woocommerce, referral, commission
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

White-label, fully configurable affiliate / partner program for WooCommerce by Beenacle. Tiered commissions, coupon attribution, hold periods, manual payouts, compliance gating, and a private partner portal.

== Description ==

Drop-in partner program by [Beenacle](https://beenacle.com) that you can install on any WooCommerce site without writing code. Every dollar amount, percentage, hold day, threshold, tier, exclusion rule, prohibited claim, and form field is editable in the admin.

Highlights:

* Tiered commissions evaluated on prior calendar month sales.
* Coupon attribution with optional bonus rate when an attributed coupon is used.
* Configurable hold period (e.g. 15 days) before commissions become payable.
* Subtotal-after-discount calculation by default; shipping and tax exclusions are toggleable.
* Auto-rejection on refunds, chargebacks, cancellations, and admin-flagged fraud / compliance violations.
* Built-in partner portal: links + codes, marketing materials, compliance agreement (versioned), commissions table, payout history.
* Built-in application form with custom field builder; no extra form plugin needed.
* Manual payout batch generator with per-method CSV export.
* WP-CLI commands: `wp partner-program release-holds`, `recalculate-tiers`, `generate-payouts --period=YYYY-MM`.
* REST API for portal AJAX.
* Compliance: prohibited-term scanner, agreement versioning with re-acceptance, configurable penalty including clawback.
* Encrypted payout details (libsodium when available).
* White-label: program name, logo, color, support email, and legal text are settings.

== Installation ==

1. Upload the `partner-program` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit *Partner Program → Settings* to configure tiers, hold period, payout threshold, application fields, and compliance text.
4. Three pages are auto-created on activation: `/partner-application`, `/partner-portal`, `/partner-login`.

== Frequently Asked Questions ==

= Can I rebrand this for my own product or another site? =

Yes. There is no hard-coded site name. Set program name, logo, accent color, support email and legal text in *Settings → General*. Templates can be overridden by your theme at `your-theme/partner-program/...`.

= How does attribution work when both a referral cookie and a coupon are used? =

If both refer to the same affiliate, the source is recorded as `both` and the configurable bonus rate is added on top of the affiliate's tier rate.

= How do I pay partners? =

Generate a payout batch from *Partner Program → Payouts*. Download the CSV, send funds via your preferred method (ACH, PayPal, Zelle, CashApp, Wise, check), then click "Mark paid" so commissions roll to status `paid`.

== Changelog ==

= 1.0.0 =
* Initial release.
