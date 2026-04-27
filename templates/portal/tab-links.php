<?php
/** @var array $affiliate @var string $coupon_code @var string $ref_link @var string $ref_param */
defined( 'ABSPATH' ) || exit;
?>
<h3><?php esc_html_e( 'Your referral link', 'partner-program' ); ?></h3>
<div class="pp-copy">
	<input type="text" readonly value="<?php echo esc_attr( $ref_link ); ?>" id="pp-ref-link" />
	<button type="button" class="pp-btn" onclick="navigator.clipboard.writeText(document.getElementById('pp-ref-link').value)"><?php esc_html_e( 'Copy', 'partner-program' ); ?></button>
</div>

<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Your coupon code', 'partner-program' ); ?></h3>
<div class="pp-copy">
	<input type="text" readonly value="<?php echo esc_attr( $coupon_code ); ?>" id="pp-coupon" />
	<button type="button" class="pp-btn" onclick="navigator.clipboard.writeText(document.getElementById('pp-coupon').value)"><?php esc_html_e( 'Copy', 'partner-program' ); ?></button>
</div>

<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Build a tagged link', 'partner-program' ); ?></h3>
<p><?php esc_html_e( 'Paste any URL on this site and we will tag it with your referral code.', 'partner-program' ); ?></p>
<div class="pp-link-builder">
	<input type="url" id="pp-builder-url" placeholder="https://..." style="width:60%;" />
	<button type="button" class="pp-btn pp-btn-primary" onclick="(async()=>{const u=document.getElementById('pp-builder-url').value;const r=await fetch('<?php echo esc_url( rest_url( 'partner-program/v1/me/link' ) ); ?>',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},body:JSON.stringify({url:u})});const j=await r.json();document.getElementById('pp-builder-result').value=j.link||'';})()"><?php esc_html_e( 'Build', 'partner-program' ); ?></button>
	<input type="text" id="pp-builder-result" readonly style="width:100%;margin-top:8px;" />
</div>
