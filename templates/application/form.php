<?php
/**
 * @var array  $fields
 * @var \PartnerProgram\Support\SettingsRepo $settings
 * @var array|null $flash
 * @var string $action
 * @var string $nonce  Pre-rendered nonce field HTML.
 */
defined( 'ABSPATH' ) || exit;

$program = (string) $settings->get( 'general.program_name', __( 'Partner Program', 'partner-program' ) );
?>
<div class="pp-application">
	<h2><?php echo esc_html( sprintf( __( 'Apply to the %s', 'partner-program' ), $program ) ); ?></h2>

	<?php if ( $flash ) : ?>
		<div class="pp-alert pp-alert-<?php echo esc_attr( $flash['type'] ); ?>">
			<?php echo esc_html( $flash['message'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data" class="pp-form">
		<input type="hidden" name="action" value="partner_program_apply" />
		<?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div style="display:none" aria-hidden="true">
			<label>Website (leave empty)<input type="text" name="hp_website" /></label>
		</div>

		<?php foreach ( $fields as $f ) :
			$key      = (string) ( $f['key'] ?? '' );
			$label    = (string) ( $f['label'] ?? $key );
			$type     = (string) ( $f['type'] ?? 'text' );
			$required = ! empty( $f['required'] );
			if ( '' === $key ) {
				continue;
			}
		?>
			<div class="pp-field">
				<label for="pp_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
				<?php if ( 'textarea' === $type ) : ?>
					<textarea id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="4" <?php echo $required ? 'required' : ''; ?>></textarea>
				<?php elseif ( 'select' === $type ) : ?>
					<select id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $required ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( '— Select —', 'partner-program' ); ?></option>
						<?php foreach ( (array) ( $f['options'] ?? [] ) as $opt ) : ?>
							<option value="<?php echo esc_attr( (string) $opt ); ?>"><?php echo esc_html( (string) $opt ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( 'checkbox' === $type ) : ?>
					<input type="checkbox" id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1" <?php echo $required ? 'required' : ''; ?> />
				<?php elseif ( 'file' === $type ) : ?>
					<input type="file" id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" accept=".pdf,image/*" <?php echo $required ? 'required' : ''; ?> />
				<?php elseif ( 'email' === $type ) : ?>
					<input type="email" id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $required ? 'required' : ''; ?> />
				<?php else : ?>
					<input type="text" id="pp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $required ? 'required' : ''; ?> />
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Submit application', 'partner-program' ); ?></button>
	</form>
</div>
