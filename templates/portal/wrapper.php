<?php
/**
 * Main portal wrapper - tabbed UI.
 *
 * @var array  $affiliate
 * @var \WP_User $user
 * @var int    $pending_cents
 * @var int    $approved_cents
 * @var int    $paid_cents
 * @var array  $tier_progress
 * @var array  $tiers
 * @var string $coupon_code
 * @var string $ref_link
 * @var array  $commissions
 * @var array  $payouts
 * @var array  $materials
 * @var ?array $agreement
 * @var \PartnerProgram\Support\SettingsRepo $settings
 * @var array  $enabled_methods
 * @var int    $min_threshold_cents
 * @var string $active_tab
 * @var string $portal_url
 * @var string $logout_url
 */
defined( 'ABSPATH' ) || exit;

use PartnerProgram\Support\Money;
use PartnerProgram\Support\Template;

$program = (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
$tabs    = [
	'overview'    => __( 'Overview', 'partner-program' ),
	'links'       => __( 'Links & Codes', 'partner-program' ),
	'materials'   => __( 'Materials', 'partner-program' ),
	'compliance'  => __( 'Compliance', 'partner-program' ),
	'commissions' => __( 'Commissions', 'partner-program' ),
	'payouts'     => __( 'Payouts', 'partner-program' ),
];
?>
<div class="pp-portal" style="--pp-accent: <?php echo esc_attr( (string) $settings->get( 'general.accent_color', '#2563eb' ) ); ?>;">
	<header class="pp-portal-header">
		<div>
			<?php $logo = (string) $settings->get( 'general.logo_url', '' ); ?>
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="" class="pp-logo" />
			<?php endif; ?>
			<h1><?php echo esc_html( $program ); ?></h1>
		</div>
		<div class="pp-user">
			<?php echo esc_html( $user->display_name ); ?>
			&middot;
			<a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'partner-program' ); ?></a>
		</div>
	</header>

	<nav class="pp-tabs">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $portal_url ) ); ?>" class="<?php echo $active_tab === $key ? 'is-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<main class="pp-tab-content">
		<?php
		switch ( $active_tab ) {
			case 'overview':
				Template::output( 'portal/tab-overview.php', get_defined_vars() );
				break;
			case 'links':
				Template::output( 'portal/tab-links.php', get_defined_vars() );
				break;
			case 'materials':
				Template::output( 'portal/tab-materials.php', get_defined_vars() );
				break;
			case 'compliance':
				Template::output( 'portal/tab-compliance.php', get_defined_vars() );
				break;
			case 'commissions':
				Template::output( 'portal/tab-commissions.php', get_defined_vars() );
				break;
			case 'payouts':
				Template::output( 'portal/tab-payouts.php', get_defined_vars() );
				break;
		}
		?>
	</main>
</div>
