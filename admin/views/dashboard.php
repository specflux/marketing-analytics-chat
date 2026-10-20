<?php
/**
 * Dashboard Page Template
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Connection_Promoter;
use Specflux_Marketing_Analytics\Admin\Dashboard_Data;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Chat\Chat_Manager;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Prompts\Prompt_Manager;

$specflux_mac_credentials = new Credential_Manager();
$specflux_mac_chat        = new Chat_Manager();
$specflux_mac_user_id     = get_current_user_id();
$specflux_mac_chat_url    = admin_url( 'admin.php?page=specflux-mac-ai-assistant' );

// Setup progress, from real state.
$specflux_mac_onboarding_complete = (bool) get_option( 'specflux_mac_onboarding_complete' );
$specflux_mac_setup               = Dashboard_Data::setup_steps(
	Dashboard_Data::setup_state( $specflux_mac_credentials, $specflux_mac_chat, new Prompt_Manager(), $specflux_mac_user_id )
);

// Platforms, headline numbers (cached only, no remote calls) and recent questions.
$specflux_mac_platforms = Dashboard_Data::platform_rows( $specflux_mac_credentials );
$specflux_mac_connected = array();
foreach ( $specflux_mac_platforms as $specflux_mac_platform ) {
	if ( $specflux_mac_platform['connected'] ) {
		$specflux_mac_connected[] = $specflux_mac_platform['slug'];
	}
}
$specflux_mac_cards  = Dashboard_Data::stat_cards( Widget_Headline::cached(), $specflux_mac_connected );
$specflux_mac_recent = Dashboard_Data::recent_questions( $specflux_mac_chat, $specflux_mac_user_id, wp_create_nonce( 'specflux_mac_conversation' ), 5 );

$specflux_mac_has_source = false;
foreach ( $specflux_mac_cards as $specflux_mac_card ) {
	if ( 'disconnected' !== $specflux_mac_card['state'] ) {
		$specflux_mac_has_source = true;
	}
}

$specflux_mac_suggestions = array(
	__( 'Which pages lost traffic last week?', 'specflux-marketing-analytics-chat' ),
	__( 'What did people search for before buying?', 'specflux-marketing-analytics-chat' ),
	__( 'Compare this month with last month', 'specflux-marketing-analytics-chat' ),
);
?>

<div class="aui" data-brand="specflux">
<?php Ui_Kit::render_header( 'specflux-mac' ); ?>

<div class="wrap specflux-marketing-analytics-chat-dashboard">
<div class="aui-layout">
	<div class="aui-stack">

		<?php
		if ( ! $specflux_mac_onboarding_complete && ! $specflux_mac_setup['complete'] ) {
			require SPECFLUX_MAC_PATH . 'admin/views/onboarding/wizard.php';
		}

		/**
		 * Fires after the welcome block, before the headline numbers.
		 *
		 * Used by the premium add-on to inject AI Insight Cards and other dashboard widgets.
		 *
		 * @since 1.5.0
		 */
		do_action( 'specflux_mac_dashboard_cards' );
		?>

		<div class="aui-grid-3 smac-stats">
			<?php foreach ( $specflux_mac_cards as $specflux_mac_card ) : ?>
				<div class="aui-stat aui-stat--spark smac-stat" data-stat="<?php echo esc_attr( $specflux_mac_card['key'] ); ?>" data-state="<?php echo esc_attr( $specflux_mac_card['state'] ); ?>">
					<span class="aui-label-caps"><?php echo esc_html( $specflux_mac_card['label'] ); ?></span>
					<?php if ( 'data' === $specflux_mac_card['state'] ) : ?>
						<div class="aui-stat-row"><div class="aui-stat-v"><?php echo esc_html( number_format_i18n( $specflux_mac_card['value'] ) ); ?></div></div>
						<?php if ( $specflux_mac_card['has_change'] ) : ?>
							<span class="aui-delta<?php echo $specflux_mac_card['change'] < 0 ? ' aui-delta--down' : ''; ?>"><?php echo esc_html( Dashboard_Data::format_delta( $specflux_mac_card['change'] ) ); ?><span class="aui-sr"> <?php esc_html_e( 'compared with the previous period', 'specflux-marketing-analytics-chat' ); ?></span></span>
						<?php endif; ?>
					<?php elseif ( 'empty' === $specflux_mac_card['state'] ) : ?>
						<div class="aui-stat-row"><div class="aui-stat-v smac-stat-none" aria-hidden="true">&mdash;</div></div>
						<span class="aui-muted"><?php esc_html_e( 'No numbers yet. Use Refresh numbers below.', 'specflux-marketing-analytics-chat' ); ?></span>
					<?php else : ?>
						<div class="aui-stat-row"><div class="aui-stat-v smac-stat-none" aria-hidden="true">&mdash;</div></div>
						<a class="smac-stat-link" href="<?php echo esc_url( $specflux_mac_card['url'] ); ?>"><?php esc_html_e( 'Connect to see this', 'specflux-marketing-analytics-chat' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( $specflux_mac_has_source ) : ?>
			<p class="smac-stats-foot aui-muted">
				<?php esc_html_e( 'Numbers come from your last refresh, not a live call.', 'specflux-marketing-analytics-chat' ); ?>
				<button type="button" class="aui-btn aui-btn--ghost aui-btn--sm" id="smac-refresh-stats" data-working="<?php echo esc_attr__( 'Refreshing…', 'specflux-marketing-analytics-chat' ); ?>"><?php esc_html_e( 'Refresh numbers', 'specflux-marketing-analytics-chat' ); ?></button>
				<span class="aui-sr" role="status" id="smac-refresh-status"></span>
			</p>
		<?php endif; ?>

		<?php
		/**
		 * Fires after the headline numbers, before the "Ask about your data" card.
		 *
		 * Used by the pro add-on to inject additional dashboard cards
		 * (e.g. Quick Wins, AI Insights summary).
		 */
		do_action( 'specflux_mac_dashboard_cards_after_insights' );
		?>

		<section class="aui-card" aria-labelledby="smac-ask-title">
			<div class="aui-card-h"><h2 class="aui-card-t" id="smac-ask-title"><?php esc_html_e( 'Ask about your data', 'specflux-marketing-analytics-chat' ); ?></h2></div>
			<div class="aui-card-b smac-stack">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="aui-composer" id="smac-ask-form">
					<input type="hidden" name="page" value="specflux-mac-ai-assistant">
					<label class="aui-sr" for="smac-ask"><?php esc_html_e( 'Your question', 'specflux-marketing-analytics-chat' ); ?></label>
					<textarea id="smac-ask" name="prompt" rows="1" maxlength="500" placeholder="<?php echo esc_attr( $specflux_mac_suggestions[0] ); ?>"></textarea>
					<button type="submit" class="aui-btn aui-btn--primary"><?php echo Ui_Kit::icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><?php esc_html_e( 'Ask', 'specflux-marketing-analytics-chat' ); ?></button>
				</form>
				<div class="aui-suggest smac-suggest">
					<?php foreach ( $specflux_mac_suggestions as $specflux_mac_suggestion ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'prompt', rawurlencode( $specflux_mac_suggestion ), $specflux_mac_chat_url ) ); ?>"><?php echo esc_html( $specflux_mac_suggestion ); ?></a>
					<?php endforeach; ?>
				</div>
				<div class="smac-row smac-quick">
					<span class="aui-muted"><?php esc_html_e( 'Ready-made analyses', 'specflux-marketing-analytics-chat' ); ?></span>
					<a class="aui-btn aui-btn--secondary aui-btn--sm" href="<?php echo esc_url( add_query_arg( 'prompt', rawurlencode( 'Give me a weekly report of my website analytics for the past 7 days.' ), $specflux_mac_chat_url ) ); ?>"><?php esc_html_e( 'Weekly Report', 'specflux-marketing-analytics-chat' ); ?></a>
					<a class="aui-btn aui-btn--secondary aui-btn--sm" href="<?php echo esc_url( add_query_arg( 'prompt', rawurlencode( 'Run an SEO health check on my website. Check search console for any issues, top queries, and indexing status.' ), $specflux_mac_chat_url ) ); ?>"><?php esc_html_e( 'SEO Health', 'specflux-marketing-analytics-chat' ); ?></a>
				</div>
			</div>
		</section>

		<section class="aui-card" aria-labelledby="smac-connections-title">
			<div class="aui-card-h">
				<h2 class="aui-card-t" id="smac-connections-title"><?php esc_html_e( 'Connections', 'specflux-marketing-analytics-chat' ); ?></h2>
				<a class="aui-btn aui-btn--ghost aui-btn--sm aui-end" href="<?php echo esc_url( Dashboard_Data::connections_url() ); ?>"><?php esc_html_e( 'Manage', 'specflux-marketing-analytics-chat' ); ?></a>
			</div>
			<ul class="aui-groups smac-platforms">
				<?php foreach ( $specflux_mac_platforms as $specflux_mac_platform ) : ?>
					<li class="aui-item" data-platform="<?php echo esc_attr( $specflux_mac_platform['slug'] ); ?>">
						<span class="aui-tile aui-tile--sm"><?php echo Ui_Kit::icon( $specflux_mac_platform['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
						<div class="aui-grow">
							<div class="aui-item-name"><?php echo esc_html( $specflux_mac_platform['label'] ); ?></div>
							<?php if ( '' !== $specflux_mac_platform['description'] ) : ?>
								<div class="aui-muted"><?php echo esc_html( $specflux_mac_platform['description'] ); ?></div>
							<?php endif; ?>
						</div>
						<?php if ( $specflux_mac_platform['connected'] ) : ?>
							<span class="aui-badge aui-badge--ok"><?php echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?></span>
							<a class="aui-btn aui-btn--secondary aui-btn--sm" href="<?php echo esc_url( $specflux_mac_platform['url'] ); ?>"><?php esc_html_e( 'Manage', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $specflux_mac_platform['label'] ); ?></span></a>
						<?php else : ?>
							<span class="aui-badge"><?php esc_html_e( 'Not connected', 'specflux-marketing-analytics-chat' ); ?></span>
							<a class="aui-btn aui-btn--secondary aui-btn--sm" href="<?php echo esc_url( $specflux_mac_platform['url'] ); ?>"><?php esc_html_e( 'Connect', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $specflux_mac_platform['label'] ); ?></span></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php
		// Once every registered platform is connected, let the premium add-on suggest more.
		( new Connection_Promoter() )->maybe_fire_all_connected();
		?>

		<section class="aui-card smac-advanced">
			<div class="aui-group">
				<div class="aui-group-h">
					<button type="button" class="aui-group-toggle" aria-expanded="false" aria-controls="smac-mcp">
						<span class="aui-group-name"><?php esc_html_e( 'Advanced: Connect External AI Assistants', 'specflux-marketing-analytics-chat' ); ?></span>
						<span class="aui-leader"></span>
						<?php echo Ui_Kit::icon( 'caret-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
					</button>
				</div>
				<div class="aui-group-b smac-mcp" id="smac-mcp" hidden>
					<p><?php esc_html_e( 'You can also connect external AI assistants like Claude Desktop using the MCP endpoint below:', 'specflux-marketing-analytics-chat' ); ?></p>
					<div class="aui-copy">
						<label class="aui-sr" for="smac-mcp-url"><?php esc_html_e( 'MCP endpoint URL', 'specflux-marketing-analytics-chat' ); ?></label>
						<input type="text" class="aui-input" id="smac-mcp-url" readonly value="<?php echo esc_url( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?>">
						<button type="button" class="aui-btn aui-btn--secondary" data-aui-copy data-aui-target="#smac-mcp-url" data-copied-label="<?php echo esc_attr__( 'Copied', 'specflux-marketing-analytics-chat' ); ?>"><?php echo Ui_Kit::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span data-aui-copy-label><?php esc_html_e( 'Copy URL', 'specflux-marketing-analytics-chat' ); ?></span></button>
					</div>
					<p class="aui-muted">
						<?php
						printf(
							/* translators: %s: link to documentation */
							esc_html__( 'Learn how to configure Claude Desktop and other MCP clients in our %s.', 'specflux-marketing-analytics-chat' ),
							'<a href="https://github.com/specflux/marketing-analytics-chat#configuration" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'specflux-marketing-analytics-chat' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>
		</section>
	</div>

	<aside class="aui-rail" aria-label="<?php echo esc_attr__( 'Sidebar', 'specflux-marketing-analytics-chat' ); ?>">
		<section class="aui-card" aria-labelledby="smac-recent-title">
			<div class="aui-card-h"><h2 class="aui-card-t" id="smac-recent-title"><?php esc_html_e( 'Recent questions', 'specflux-marketing-analytics-chat' ); ?></h2></div>
			<div class="aui-card-b">
				<?php if ( empty( $specflux_mac_recent ) ) : ?>
					<div class="aui-empty smac-empty"><p><?php esc_html_e( 'No questions yet. Your last five show up here.', 'specflux-marketing-analytics-chat' ); ?></p></div>
				<?php else : ?>
					<ul class="aui-links smac-recent">
						<?php foreach ( $specflux_mac_recent as $specflux_mac_item ) : ?>
							<li><a href="<?php echo esc_url( $specflux_mac_item['url'] ); ?>"><?php echo Ui_Kit::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php echo esc_html( $specflux_mac_item['title'] ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>
		<section class="aui-card" aria-labelledby="smac-help-title">
			<div class="aui-card-h"><h2 class="aui-card-t" id="smac-help-title"><?php esc_html_e( 'Help', 'specflux-marketing-analytics-chat' ); ?></h2></div>
			<div class="aui-card-b">
				<ul class="aui-links">
					<li><a href="https://github.com/specflux/marketing-analytics-chat#configuration" target="_blank" rel="noopener noreferrer"><?php echo Ui_Kit::icon( 'book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Getting started', 'specflux-marketing-analytics-chat' ); ?></span><?php echo Ui_Kit::icon( 'arrow-square-out', 'aui-end' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span class="aui-sr"> <?php esc_html_e( '(opens in a new tab)', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
					<li><a href="<?php echo esc_url( Dashboard_Data::connections_url() ); ?>"><?php echo Ui_Kit::icon( 'lifebuoy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Fix a connection', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-prompts' ) ); ?>"><?php echo Ui_Kit::icon( 'pencil' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Manage prompts', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
				</ul>
			</div>
		</section>
	</aside>
</div>
</div>
</div>
