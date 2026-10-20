<?php
/**
 * Dashboard welcome block: setup progress as a stepper.
 *
 * One block for first-run setup. Expects $specflux_mac_setup from
 * Dashboard_Data::setup_steps(). The dashboard only includes it while setup is
 * unfinished and has not been skipped.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Ui_Kit;

$specflux_mac_remaining = 0;
foreach ( $specflux_mac_setup['steps'] as $specflux_mac_step ) {
	if ( 'done' !== $specflux_mac_step['status'] && ! $specflux_mac_step['optional'] ) {
		++$specflux_mac_remaining;
	}
}
?>
<section class="aui-card smac-setup" id="smac-onboarding-wizard" aria-labelledby="smac-setup-title">
	<div class="aui-card-h">
		<div>
			<h2 class="aui-card-t" id="smac-setup-title">
				<?php
				if ( 0 === $specflux_mac_remaining ) {
					esc_html_e( 'Welcome. One optional step left.', 'specflux-marketing-analytics-chat' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: %d: number of required setup steps still to do. */
							_n( 'Welcome. You are %d step from your first answer.', 'Welcome. You are %d steps from your first answer.', $specflux_mac_remaining, 'specflux-marketing-analytics-chat' ),
							$specflux_mac_remaining
						)
					);
				}
				?>
			</h2>
			<p class="aui-card-d"><?php esc_html_e( 'Finish setup, then ask about your traffic in plain English.', 'specflux-marketing-analytics-chat' ); ?></p>
		</div>
	</div>
	<div class="aui-card-b smac-stack">
		<ol class="aui-stepper" aria-label="<?php echo esc_attr__( 'Setup progress', 'specflux-marketing-analytics-chat' ); ?>">
			<?php foreach ( $specflux_mac_setup['steps'] as $specflux_mac_index => $specflux_mac_step ) : ?>
				<li<?php echo 'done' === $specflux_mac_step['status'] ? ' class="is-done"' : ( 'current' === $specflux_mac_step['status'] ? ' aria-current="step"' : '' ); ?>>
					<span class="aui-step-n">
						<?php
						if ( 'done' === $specflux_mac_step['status'] ) {
							echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon().
						} else {
							echo esc_html( $specflux_mac_index + 1 );
						}
						?>
					</span>
					<span>
						<span class="aui-step-t"><?php echo esc_html( $specflux_mac_step['label'] ); ?></span>
						<span class="aui-step-d"><?php echo esc_html( $specflux_mac_step['desc'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<div class="smac-row">
			<?php if ( ! empty( $specflux_mac_setup['action'] ) ) : ?>
				<a class="aui-btn aui-btn--primary" href="<?php echo esc_url( $specflux_mac_setup['action']['url'] ); ?>"><?php echo esc_html( $specflux_mac_setup['action']['label'] ); ?></a>
			<?php endif; ?>
			<button type="button" class="aui-btn aui-btn--ghost" id="smac-wizard-skip"><?php esc_html_e( 'Skip setup', 'specflux-marketing-analytics-chat' ); ?></button>
		</div>
	</div>
</section>
