<?php
/** @var array $materials */
defined( 'ABSPATH' ) || exit;
?>
<h3><?php esc_html_e( 'Marketing materials', 'partner-program' ); ?></h3>
<?php if ( ! $materials ) : ?>
	<p><?php esc_html_e( 'No materials are published yet.', 'partner-program' ); ?></p>
<?php else : ?>
	<div class="pp-materials">
		<?php foreach ( $materials as $m ) : ?>
			<article class="pp-material">
				<h4><?php echo esc_html( get_the_title( $m ) ); ?></h4>
				<?php if ( has_post_thumbnail( $m ) ) : ?>
					<?php echo get_the_post_thumbnail( $m, 'medium' ); ?>
				<?php endif; ?>
				<div class="pp-material-body"><?php echo wp_kses_post( apply_filters( 'the_content', $m->post_content ) ); ?></div>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
