<?php
/**
 * Chat System Prompt
 *
 * Builds the system instruction sent with every chat request: who the model is
 * talking to, what data is connected, how dates work, and how to format and
 * ground its answers.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Dynamic system prompt builder
 */
class System_Prompt {

	/**
	 * Credential manager used to detect connected platforms.
	 *
	 * @var Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Client used to list the abilities offered to the model.
	 *
	 * @var MCP_Client|null
	 */
	private $mcp_client;

	/**
	 * Constructor
	 *
	 * @param Credential_Manager|null $credential_manager Credential manager (defaults to the real one).
	 * @param MCP_Client|null         $mcp_client         Ability lister (defaults to the real one).
	 */
	public function __construct( $credential_manager = null, $mcp_client = null ) {
		$this->credential_manager = null !== $credential_manager ? $credential_manager : new Credential_Manager();
		$this->mcp_client         = $mcp_client;
	}

	/**
	 * One line naming the enabled third-party providers and their tool counts.
	 *
	 * @return string Line, or empty string when no other plugin offers tools.
	 */
	private function other_tools_line() {
		$client = null !== $this->mcp_client ? $this->mcp_client : new MCP_Client();
		$tools  = $client->list_tools();
		if ( is_wp_error( $tools ) || ! is_array( $tools ) ) {
			return '';
		}

		$parts = array();
		foreach ( Tool_Policy::providers( Tool_Policy::select( $tools ) ) as $slug => $counts ) {
			if ( Tool_Policy::OWN_PROVIDER === $slug || Tool_Policy::CORE_PROVIDER === $slug ) {
				continue;
			}

			$detail = $counts['read'] . ' read';
			if ( $counts['write'] > 0 ) {
				$detail .= ', ' . $counts['write'] . ' need approval';
			}
			$parts[] = $counts['label'] . ' (' . $detail . ')';
		}

		return $parts ? "Other plugins' tools available: " . implode( ', ', $parts ) . '.' : '';
	}

	/**
	 * Gather the site facts the prompt is built from.
	 *
	 * @return array Context: site_name, home_url, date, timezone, currency, platforms, connections_url.
	 */
	public function get_context() {
		$platforms = array();
		foreach ( Platform_Registry::all() as $slug => $definition ) {
			$platforms[ $slug ] = $this->is_connected( $definition );
		}

		$timezone = wp_timezone();

		return array(
			'site_name'       => (string) get_bloginfo( 'name' ),
			'home_url'        => (string) home_url(),
			'date'            => ( new \DateTimeImmutable( 'now', $timezone ) )->format( 'Y-m-d (l)' ),
			'timezone'        => $timezone->getName(),
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'platforms'       => $platforms,
			'connections_url' => admin_url( 'admin.php?page=specflux-mac-connections' ),
		);
	}

	/**
	 * Whether a platform has what it needs to answer, without a live API call.
	 *
	 * @param array $definition Platform definition from the registry.
	 * @return bool
	 */
	private function is_connected( array $definition ) {
		if ( 'none' === $definition['auth'] ) {
			return true;
		}

		if ( 'api_key' === $definition['auth'] && array() === array_diff( $definition['fields'], $definition['optional_fields'] ) ) {
			return true;
		}

		if ( ! $this->credential_manager->has_credentials( $definition['slug'] ) ) {
			return false;
		}

		// A saved key that the service rejected is not a working connection.
		$last = 'api_key' === $definition['auth'] ? Connection_Tester::get_last_result( $definition['slug'] ) : null;
		return null === $last || ! empty( $last['success'] );
	}

	/**
	 * Build the system prompt.
	 *
	 * @return string System prompt.
	 */
	public function build() {
		$context = $this->get_context();

		$lines   = array();
		$lines[] = 'You are the marketing analytics assistant inside the WordPress admin for the site below. You answer questions about website traffic, search performance and visitor behaviour by calling the provided tools, which read live data from the data sources listed below.';
		$lines[] = '';
		$lines[] = '## Site';
		$lines[] = '- Name: ' . $context['site_name'];
		$lines[] = '- URL: ' . $context['home_url'];
		$lines[] = '- Today: ' . $context['date'] . ', site timezone ' . $context['timezone'];
		if ( '' !== $context['currency'] ) {
			$lines[] = '- Store currency (WooCommerce): ' . $context['currency'];
		}
		$lines[] = '';
		$lines[] = '## Connected data sources';
		foreach ( Platform_Registry::all() as $slug => $definition ) {
			$line = '- ' . $definition['label'] . ': ' . ( ! empty( $context['platforms'][ $slug ] ) ? 'connected' : 'NOT connected' );
			if ( '' !== $definition['prompt_hint'] ) {
				$line .= '. ' . $definition['prompt_hint'];
			}
			$lines[] = $line;
		}
		$lines[] = 'If the user asks about a platform that is not connected, say so plainly, do not guess, and point them to the Connections page (' . $context['connections_url'] . ') to connect it. Answer what you can from the platforms that are connected.';
		$lines[] = '';
		$lines[] = '## Dates';
		$lines[] = '- GA4 and GSC tools accept start_date and end_date (YYYY-MM-DD); prefer them for any specific period. They also accept date_range: "7daysAgo", "28daysAgo", "30daysAgo", "yesterday", "today", a single YYYY-MM-DD date, or "YYYY-MM-DD,YYYY-MM-DD".';
		$lines[] = '- GSC data lags by about 3 days, so its most recent days are incomplete or missing.';
		$lines[] = '- Clarity only supports the last 1 to 3 days. For longer periods, use GA4 and say that Clarity cannot cover them.';
		$lines[] = '- Work out calendar periods ("last month", "this quarter") from today\'s date above, and use the same date arithmetic for the comparison period.';
		$lines[] = '';
		$lines[] = '## Rules';
		$lines[] = '- Never invent numbers. Every figure must come from a tool result in this conversation. If a tool failed or returned nothing, say so.';
		$lines[] = '- State the exact period each set of numbers covers (for example "2026-09-01 to 2026-09-30").';
		$lines[] = '- Compare against the previous equivalent period when it helps, and give the change as an absolute and a percentage.';
		$lines[] = '- Tool results may be truncated to the top rows; when a result says it is truncated, mention that you are looking at the top results only.';
		$lines[] = '- Call several tools when one is not enough, and prefer the most specific tool for the question.';
		$lines[] = '- Keep answers short and decision-oriented. Lead with the answer.';
		$lines[] = '- End every answer with 1 to 3 concrete next actions the user can take.';
		$lines[] = '- Tools that change the site never run immediately: they become a Run button the user must click. Never claim a change was made until the user has applied it.';
		$other   = $this->other_tools_line();
		if ( '' !== $other ) {
			$lines[] = '- ' . $other;
		}

		/**
		 * Filters whether the chat can show proposed changes as Apply buttons.
		 *
		 * An add-on that registers handlers for proposed actions returns true so
		 * the model is told about the feature; free-only sites are not.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( apply_filters( 'specflux_mac_chat_actions_enabled', false ) ) {
			$lines[] = '- When a tool proposes a change, it appears to the user as an Apply button under your answer. Nothing changes until the user clicks it, so describe it as a proposal and never claim a change was made.';
		}
		$lines[] = '';
		$lines[] = '## Formatting';
		$lines[] = 'The chat renders a limited Markdown subset. Use only: headings (#, ##, ###), bullet lists with "- ", pipe tables, **bold**, *italic*, `inline code`, fenced code blocks and [links](https://...). Do not nest lists, do not use horizontal rules, and do not use HTML. Use a pipe table for any comparison of three or more rows or metrics.';

		$prompt = implode( "\n", $lines );

		/**
		 * Filters the chat system prompt.
		 *
		 * Add-ons can append guidance (for example extra tools or business rules).
		 *
		 * @param string $prompt  The system prompt.
		 * @param array  $context Site context used to build it (site_name, home_url, date,
		 *                        timezone, currency, platforms, connections_url).
		 */
		$filtered = apply_filters( 'specflux_mac_chat_system_prompt', $prompt, $context );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $prompt;
	}
}
