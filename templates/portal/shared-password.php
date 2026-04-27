<?php
/** @var string $error */
defined( 'ABSPATH' ) || exit;
?>
<div class="pp-shared-pw">
	<h2><?php esc_html_e( 'Password required', 'partner-program' ); ?></h2>
	<?php if ( $error ) : ?>
		<div class="pp-alert pp-alert-error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<form method="post" class="pp-form">
		<?php wp_nonce_field( 'pp_shared_pw', '_pp_shared_pw_nonce' ); ?>
		<div class="pp-field"><label><?php esc_html_e( 'Password', 'partner-program' ); ?><input type="password" name="shared_password" required autofocus /></label></div>
		<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Continue', 'partner-program' ); ?></button>
	</form>
</div>
