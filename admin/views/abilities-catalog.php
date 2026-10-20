<?php
/**
 * Abilities Catalog Page Template
 *
 * Showcases all available MCP abilities for AI assistants, grouped by category.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Abilities_Catalog;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;

$mac_abilities     = Abilities_Catalog::items();
$mac_groups        = Abilities_Catalog::group( $mac_abilities );
$mac_tier_totals   = Abilities_Catalog::tier_counts( $mac_abilities );
$mac_show_tiers    = $mac_tier_totals['free'] > 0 && $mac_tier_totals['pro'] > 0;
$mac_total         = count( $mac_abilities );
$mac_mcp_endpoint  = rest_url( 'mcp/mcp-adapter-default-server' );
$mac_open_by_start = 3;
?>

<div class="aui" data-brand="specflux">
<?php Ui_Kit::render_header( 'specflux-mac-abilities' ); ?>

<div class="wrap smac-catalog smac-abilities-catalog">
<div class="aui-layout">
	<div class="aui-stack">
		<p class="aui-muted smac-lead">
			<?php esc_html_e( 'All the analytics abilities available to your AI assistants via the Model Context Protocol. No competitor has MCP-native architecture.', 'specflux-marketing-analytics-chat' ); ?>
		</p>

		<section class="aui-card" data-aui-filterbox aria-label="<?php echo esc_attr__( 'Abilities', 'specflux-marketing-analytics-chat' ); ?>">
			<div class="aui-toolbar">
				<div class="aui-search">
					<?php echo Ui_Kit::icon( 'magnifying-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
					<input type="search" class="aui-input" id="smac-ability-search" data-aui-search autocomplete="off" aria-label="<?php echo esc_attr__( 'Search abilities', 'specflux-marketing-analytics-chat' ); ?>" placeholder="<?php echo esc_attr__( 'Search abilities', 'specflux-marketing-analytics-chat' ); ?>">
				</div>
				<?php if ( $mac_show_tiers ) : ?>
					<div class="aui-seg" role="group" aria-label="<?php echo esc_attr__( 'Filter by plan', 'specflux-marketing-analytics-chat' ); ?>" data-aui-filter>
						<button type="button" data-value="all" aria-pressed="true"><?php esc_html_e( 'All', 'specflux-marketing-analytics-chat' ); ?></button>
						<button type="button" data-value="free" aria-pressed="false"><?php esc_html_e( 'Free', 'specflux-marketing-analytics-chat' ); ?></button>
						<button type="button" data-value="pro" aria-pressed="false"><?php esc_html_e( 'Pro', 'specflux-marketing-analytics-chat' ); ?></button>
					</div>
				<?php endif; ?>
				<button type="button" class="aui-switch aui-end" aria-pressed="true" data-aui-ui data-aui-target="#smac-ability-list" data-aui-class="aui-show-desc">
					<span class="aui-switch-track"></span><?php esc_html_e( 'Show descriptions', 'specflux-marketing-analytics-chat' ); ?>
					<span class="aui-switch-state" data-on="<?php echo esc_attr__( 'On', 'specflux-marketing-analytics-chat' ); ?>" data-off="<?php echo esc_attr__( 'Off', 'specflux-marketing-analytics-chat' ); ?>"><?php esc_html_e( 'On', 'specflux-marketing-analytics-chat' ); ?></span>
				</button>
			</div>
			<p class="aui-sr" role="status" id="smac-ability-status"></p>

			<div class="aui-groups aui-show-desc" id="smac-ability-list" data-total="<?php echo esc_attr( (string) $mac_total ); ?>" data-show-tiers="<?php echo $mac_show_tiers ? '1' : '0'; ?>">
				<?php foreach ( $mac_groups as $mac_index => $mac_group ) : ?>
					<?php
					$mac_group_id = 'smac-ab-' . $mac_group['slug'];
					$mac_is_open  = $mac_index < $mac_open_by_start;
					?>
					<div class="aui-group" data-group-slug="<?php echo esc_attr( $mac_group['slug'] ); ?>">
						<div class="aui-group-h">
							<span class="aui-tile aui-tile--sm"><?php echo Ui_Kit::icon( Abilities_Catalog::category_icon( $mac_group['slug'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
							<button type="button" class="aui-group-toggle" aria-expanded="<?php echo $mac_is_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $mac_group_id ); ?>">
								<span class="aui-group-name"><?php echo esc_html( $mac_group['label'] ); ?></span>
								<span class="aui-leader"></span>
								<span class="aui-group-meta" data-meta><?php echo esc_html( Abilities_Catalog::group_meta( count( $mac_group['items'] ), $mac_group['free'], $mac_group['pro'], $mac_show_tiers ) ); ?></span>
								<?php echo Ui_Kit::icon( 'caret-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
							</button>
						</div>
						<div class="aui-group-b" id="<?php echo esc_attr( $mac_group_id ); ?>"<?php echo $mac_is_open ? '' : ' hidden'; ?>>
							<?php foreach ( $mac_group['items'] as $mac_ability ) : ?>
								<div class="aui-item smac-ability" data-kind="<?php echo esc_attr( $mac_ability['tier'] ); ?>" data-category="<?php echo esc_attr( $mac_ability['category'] ); ?>">
									<div class="aui-grow">
										<div class="aui-item-name"><?php echo esc_html( $mac_ability['name'] ); ?> <code><?php echo esc_html( $mac_ability['slug'] ); ?></code></div>
										<div class="aui-item-d"><?php echo esc_html( $mac_ability['description'] ); ?></div>
									</div>
									<?php if ( 'pro' === $mac_ability['tier'] ) : ?>
										<span class="aui-badge aui-badge--info"><?php esc_html_e( 'Pro', 'specflux-marketing-analytics-chat' ); ?></span>
									<?php else : ?>
										<span class="aui-badge"><?php esc_html_e( 'Free', 'specflux-marketing-analytics-chat' ); ?></span>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="aui-empty" id="smac-ability-none" hidden>
				<span class="aui-tile"><?php echo Ui_Kit::icon( 'magnifying-glass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
				<h2 class="aui-card-t"><?php esc_html_e( 'No abilities match', 'specflux-marketing-analytics-chat' ); ?></h2>
				<p><?php esc_html_e( 'Try a different word, or clear the search.', 'specflux-marketing-analytics-chat' ); ?></p>
				<button type="button" class="aui-btn aui-btn--secondary" id="smac-ability-clear"><?php esc_html_e( 'Clear search', 'specflux-marketing-analytics-chat' ); ?></button>
			</div>
		</section>
	</div>

	<aside class="aui-rail" aria-label="<?php echo esc_attr__( 'Sidebar', 'specflux-marketing-analytics-chat' ); ?>">
		<section class="aui-card" aria-labelledby="smac-connect-title">
			<div class="aui-card-h"><h2 class="aui-card-t" id="smac-connect-title"><?php esc_html_e( 'Connect Your AI Assistant', 'specflux-marketing-analytics-chat' ); ?></h2></div>
			<div class="aui-card-b smac-stack">
				<p><?php esc_html_e( 'Any MCP-compatible AI assistant can use these abilities. Add this endpoint to your assistant configuration:', 'specflux-marketing-analytics-chat' ); ?></p>
				<div class="aui-copy">
					<label class="aui-sr" for="smac-mcp-url"><?php esc_html_e( 'MCP endpoint URL', 'specflux-marketing-analytics-chat' ); ?></label>
					<input type="text" class="aui-input" id="smac-mcp-url" readonly value="<?php echo esc_url( $mac_mcp_endpoint ); ?>">
					<button type="button" class="aui-btn aui-btn--secondary" data-aui-copy data-aui-target="#smac-mcp-url" data-copied-label="<?php echo esc_attr__( 'Copied', 'specflux-marketing-analytics-chat' ); ?>"><?php echo Ui_Kit::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span data-aui-copy-label><?php esc_html_e( 'Copy URL', 'specflux-marketing-analytics-chat' ); ?></span></button>
				</div>
				<p class="aui-muted">
					<?php
					printf(
						/* translators: %s: link to documentation */
						esc_html__( 'Works with Claude Desktop, ChatGPT, Cursor, and any MCP-compatible client. See our %s for setup guides.', 'specflux-marketing-analytics-chat' ),
						'<a href="https://github.com/specflux/marketing-analytics-chat#configuration" target="_blank" rel="noopener noreferrer">' . esc_html__( 'documentation', 'specflux-marketing-analytics-chat' ) . '<span class="aui-sr"> ' . esc_html__( '(opens in a new tab)', 'specflux-marketing-analytics-chat' ) . '</span></a>'
					);
					?>
				</p>
			</div>
		</section>
	</aside>
</div>
</div>
</div>
