<?php
/**
 * @var string $action
 * @var string $nonce
 * @var string $error
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="pp-login">
	<h2><?php esc_html_e( 'Partner login', 'partner-program' ); ?></h2>
	<?php if ( $error ) : ?>
		<div class="pp-alert pp-alert-error"><?php esc_html_e( 'Invalid credentials.', 'partner-program' ); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $action ); ?>" class="pp-form">
		<input type="hidden" name="action" value="pp_portal_login" />
		<?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="pp-field"><label><?php esc_html_e( 'Username or email', 'partner-program' ); ?><input type="text" name="log" required /></label></div>
		<div class="pp-field"><label><?php esc_html_e( 'Password', 'partner-program' ); ?><input type="password" name="pwd" required /></label></div>
		<div class="pp-field"><label><input type="checkbox" name="rememberme" value="1" /> <?php esc_html_e( 'Remember me', 'partner-program' ); ?></label></div>
		<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Log in', 'partner-program' ); ?></button>
	</form>
	<p><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot your password?', 'partner-program' ); ?></a></p>
</div>
