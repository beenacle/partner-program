<?php
/**
 * Portal "Training" tab: training modules + compliance certification.
 *
 * @var array   $modules        Published pp_module posts (ordered).
 * @var bool    $cert_enabled
 * @var bool    $is_certified
 * @var ?array  $latest_cert     Most recent passing certification row.
 * @var array   $cert_settings   certification settings section.
 * @var string  $program
 * @var string  $certify_action
 * @var string  $certify_nonce
 */
defined( 'ABSPATH' ) || exit;

$questions   = \PartnerProgram\Domain\CertificationRepo::gradable_questions( isset( $cert_settings['questions'] ) ? (array) $cert_settings['questions'] : [] );
$pass_pct    = isset( $cert_settings['pass_pct'] ) ? (int) $cert_settings['pass_pct'] : 80;
$require_sig = ! empty( $cert_settings['require_signature'] );
$ack_html    = isset( $cert_settings['acknowledgment_html'] ) ? (string) $cert_settings['acknowledgment_html'] : '';
$ack_html    = str_replace( '{program_name}', esc_html( $program ), $ack_html );
?>
<h3><?php esc_html_e( 'Training modules', 'partner-program' ); ?></h3>

<?php if ( empty( $modules ) ) : ?>
	<p><?php esc_html_e( 'Training modules will appear here soon.', 'partner-program' ); ?></p>
<?php else : ?>
	<div class="pp-modules">
		<?php foreach ( $modules as $index => $m ) : ?>
			<details class="pp-module"<?php echo 0 === $index ? ' open' : ''; ?>>
				<summary class="pp-module-title"><?php echo esc_html( get_the_title( $m ) ); ?></summary>
				<div class="pp-module-body">
					<?php echo wp_kses_post( apply_filters( 'the_content', $m->post_content ) ); ?>
				</div>
			</details>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( $cert_enabled ) : ?>
	<hr class="pp-mt-lg" />
	<h3><?php esc_html_e( 'Compliance certification', 'partner-program' ); ?></h3>

	<?php if ( $is_certified && $latest_cert ) : ?>
		<div class="pp-alert pp-alert-success">
			<?php
			$when  = isset( $latest_cert['created_at'] ) ? mysql2date( get_option( 'date_format' ), (string) $latest_cert['created_at'] ) : '';
			$score = isset( $latest_cert['score_pct'] ) ? (float) $latest_cert['score_pct'] : 0;
			/* translators: 1: date, 2: score percentage. */
			echo esc_html( sprintf( __( 'You are certified. Completed %1$s with a score of %2$s%%.', 'partner-program' ), $when, rtrim( rtrim( number_format( $score, 2 ), '0' ), '.' ) ) );
			?>
		</div>
		<?php if ( ! empty( $latest_cert['signature'] ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Signed electronically by: %s', 'partner-program' ), (string) $latest_cert['signature'] ) ); ?></p>
		<?php endif; ?>
	<?php elseif ( empty( $questions ) ) : ?>
		<p><?php esc_html_e( 'The certification quiz has not been configured yet.', 'partner-program' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html( sprintf( __( 'Answer the questions below and score at least %d%% to certify. Your answers, signature, and timestamp are recorded as your training record.', 'partner-program' ), $pass_pct ) ); ?></p>

		<form method="post" action="<?php echo esc_url( $certify_action ); ?>" class="pp-form pp-quiz" data-pp-refresh-nonce data-pp-nonce-action="pp_portal_certify" data-pp-nonce-field="_pp_certify_nonce">
			<input type="hidden" name="action" value="pp_portal_certify" />
			<?php echo $certify_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php foreach ( $questions as $i => $q ) :
				$q_text  = isset( $q['q'] ) ? (string) $q['q'] : '';
				$options = isset( $q['options'] ) ? array_values( (array) $q['options'] ) : [];
				if ( '' === $q_text || empty( $options ) ) {
					continue;
				}
				?>
				<fieldset class="pp-quiz-q">
					<legend><?php echo esc_html( ( (int) $i + 1 ) . '. ' . $q_text ); ?></legend>
					<?php foreach ( $options as $opt_i => $opt ) :
						$opt_id = 'pp_q' . (int) $i . '_o' . (int) $opt_i;
						?>
						<label class="pp-quiz-option" for="<?php echo esc_attr( $opt_id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $opt_id ); ?>" name="answers[<?php echo (int) $i; ?>]" value="<?php echo (int) $opt_i; ?>" required />
							<span><?php echo esc_html( (string) $opt ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>

			<?php if ( '' !== trim( $ack_html ) ) : ?>
				<div class="pp-quiz-ack-text"><?php echo wp_kses_post( $ack_html ); ?></div>
			<?php endif; ?>

			<div class="pp-field pp-field-checkbox">
				<label for="pp_cert_ack"><input type="checkbox" id="pp_cert_ack" name="acknowledge" value="1" required /> <span class="pp-check-text"><?php esc_html_e( 'I acknowledge and agree to the statements above.', 'partner-program' ); ?></span></label>
			</div>

			<div class="pp-field">
				<label for="pp_cert_sig"><?php esc_html_e( 'Type your full name as your electronic signature', 'partner-program' ); ?><?php echo $require_sig ? ' <span class="pp-required" aria-hidden="true">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="text" id="pp_cert_sig" name="signature" autocomplete="name"<?php echo $require_sig ? ' required' : ''; ?> />
			</div>

			<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Submit & certify', 'partner-program' ); ?></button>
		</form>
	<?php endif; ?>
<?php endif; ?>
