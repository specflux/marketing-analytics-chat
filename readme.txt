=== Specflux Marketing Analytics Chat ===
Contributors: specflux, stephen1204paul
Donate link: https://www.specflux.com/
Tags: google analytics, search console, microsoft clarity, ai chat, mcp
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.3.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chat for your Google Analytics 4, Search Console, and Microsoft Clarity data, powered by MCP.

== Description ==

Specflux Marketing Analytics Chat lets you ask questions about your marketing data in plain English, right inside WordPress. Connect Google Analytics 4, Google Search Console, and Microsoft Clarity, then chat with an AI assistant — or any MCP-compatible client — to get instant insights and recommendations.

Connecting Google Analytics 4 and Search Console takes one click: sign in with Google through Specflux's hosted OAuth service and there's no need to create your own Google Cloud project or OAuth client. Already have your own OAuth client? It keeps working — the hosted sign-in is just the default. See External Services below for exactly what that involves.

= Google Analytics 4, Search Console & Microsoft Clarity =

* **Google Analytics 4** - Traffic metrics, user behavior, conversions, real-time data
* **Google Search Console** - Search performance, queries, indexing status
* **Microsoft Clarity** - Session recordings, heatmaps, user behavior insights

= AI Chat & MCP Features =

* **Built-in AI Chat** - Chat with your analytics data through the WordPress AI Client built into core. You pick your AI provider once under Settings > Connectors; this plugin stores no AI keys of its own
* **MCP-Native Architecture** - Exposes analytics as MCP abilities for any compatible AI assistant
* **Interactive Onboarding Wizard** - Step-by-step setup with guided configuration
* **Analytics at a Glance Dashboard** - Dashboard widget with sparkline trends
* **Admin Bar Pulse Indicator** - Quick analytics status in the WordPress admin bar
* **Smart Prompt Templates** - Auto-installed prompts for common analytics questions
* **MCP Abilities Catalog** - Browse all available analytics abilities in one place
* **Connection Depth Prompts** - Contextual prompts that adapt to your connected platforms
* **Multi-Platform Support** - Connect all your marketing data sources
* **Secure Credentials** - OAuth 2.0 and encrypted API key storage
* **Smart Caching** - Reduce API calls with intelligent caching
* **Cross-Platform Analysis** - Compare data across all connected platforms

= How It Works =

1. Connect your analytics platforms via OAuth or API keys
2. Use the built-in AI chat right inside WordPress (works out of the box on WordPress 7.0)
3. Ask questions like "How did my traffic change this week?"
4. Get AI-powered insights and recommendations

Prefer to use an external AI client (Claude Desktop, ChatGPT, Cursor)? Install the optional MCP Adapter plugin (see Requirements) to expose these tools over the Model Context Protocol.

= Requirements =

* WordPress 7.0 or higher (includes Abilities API and AI Client in core)
* MCP Adapter plugin (optional; only needed to connect external AI clients such as Claude Desktop or Cursor). It is available from GitHub at https://github.com/WordPress/mcp-adapter and is not required for the built-in chat.
* PHP 8.1 or higher
* SSL certificate (HTTPS) for OAuth connections
* PHP extensions: json, curl, openssl (sodium via ext-sodium or the polyfill bundled with WordPress)

= External Services =

This plugin sends data to the external services listed below. Each service is
contacted only after you connect the corresponding platform on the plugin's
Connections screen. A site with no connections configured makes no external
requests.

**Google Analytics 4 and Google Search Console** (provided by Google LLC)

What it is used for: reading your own analytics and search-performance data so
it can be shown in the plugin dashboard, exposed as MCP abilities, and used to
answer questions in chat.

What is sent and when: connecting an account sends your authorisation request to
accounts.google.com, and access and refresh tokens are exchanged against
oauth2.googleapis.com. Once connected, report requests go to
analyticsdata.googleapis.com (GA4 traffic, behaviour, and conversion metrics),
analyticsadmin.googleapis.com (the list of your GA4 properties, requested during
connection setup), and searchconsole.googleapis.com (search queries, clicks,
impressions, and indexing status). Each request carries your OAuth access token,
the GA4 property ID or Search Console site URL being queried, and the date range
and metrics requested. Requests are made when you open a plugin screen whose
cached data has expired, when you refresh the dashboard widget, and when an
ability is run from the built-in chat or an external MCP client. No post
content, visitor records, or other personal data from your WordPress site is
sent.

Terms of service: https://developers.google.com/terms
Privacy policy: https://policies.google.com/privacy

**Specflux Google sign-in service** (provided by Specflux Group, api.specflux.com)

What it is used for: letting you connect Google Analytics 4 and Search Console
with one click, without creating your own Google Cloud project. It holds the
Google OAuth client credentials so this plugin does not have to ship them. It is
not used if you enter your own Google OAuth client ID and secret under Settings
> Google API.

What is sent and when: when you click "Connect with Google", the plugin sends the
service being connected (GA4 or Search Console), the URL of your Connections
screen to return to, and a random one-time nonce, and receives the Google
sign-in link. After you approve access, Google redirects through the service,
which exchanges the authorisation code with Google, holds the resulting tokens
for at most five minutes, and hands them to your site once over HTTPS, after
which they are deleted from the service. Thereafter, whenever your access token
expires (about once an hour while the connection is in use), your site sends its
refresh token to the service, which obtains a fresh access token from Google and
returns it. The service does not receive, proxy, or store any analytics data,
and does not retain tokens beyond the handoff described above.

Terms of service: https://specflux.com/terms
Privacy policy: https://specflux.com/privacy-policy

**Microsoft Clarity** (provided by Microsoft Corporation)

What it is used for: reading your own Clarity project's behavioural metrics,
including session counts, engagement and scroll data, and the insights Clarity
derives from session recordings and heatmaps.

What is sent and when: the plugin calls the Clarity Data Export API at
www.clarity.ms. Each request carries your Clarity API token and project ID,
together with the number of days and the dimensions requested. Requests are made
when cached Clarity data has expired and a plugin screen, a dashboard widget
refresh, or an MCP ability asks for it. Clarity permits ten export requests per
project per day, so responses are cached for one hour.

Terms of use: https://clarity.microsoft.com/terms
Privacy statement: https://privacy.microsoft.com/privacystatement

**AI chat responses**

This plugin does not contact any AI provider directly and stores no AI provider
credentials. The built-in chat calls the AI Client included in WordPress 7.0,
which sends your chat messages and the analytics results described above to
whichever provider you have configured for your site under Settings >
Connectors. That provider's own terms of service and privacy policy govern that
transfer, and WordPress stores its credentials.

== Installation ==

= Prerequisites =

1. Ensure you are running WordPress 7.0 or higher (includes Abilities API and AI Client)
2. Optional, for external AI clients only: install and activate the "MCP Adapter" plugin from GitHub (https://github.com/WordPress/mcp-adapter). The built-in chat works without it.

= Plugin Installation =

1. Upload the `specflux-marketing-analytics-chat` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Marketing Analytics > Settings > Google API to configure OAuth credentials
4. Connect your analytics platforms from the Connections page
5. Configure your MCP client (e.g., Claude Desktop) to use the plugin endpoint

= Configuring MCP Client =

Add this to your Claude Desktop configuration:

`{
  "mcpServers": {
    "wordpress-marketing": {
      "transport": {
        "type": "http",
        "url": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "headers": {
          "Authorization": "Basic base64(username:application-password)"
        }
      }
    }
  }
}`

== Frequently Asked Questions ==

= How does the AI chat work? =

The plugin exposes your analytics data as MCP abilities. Any MCP-compatible AI assistant (Claude Desktop, ChatGPT, Cursor) can query your data by calling these abilities. Just type a question like "What are my top traffic sources?" and get an instant answer.

= Which AI assistants / MCP clients does it work with? =

Out of the box, you can chat with the built-in AI Chat, which runs through the WordPress AI Client — pick your provider once under Settings > Connectors and this plugin never stores an AI key of its own. If you'd rather use an external client, installing the optional MCP Adapter plugin exposes the same analytics abilities over MCP to Claude Desktop, ChatGPT, Cursor, or any other MCP-compatible client.

= Does this work with Google Analytics 4 (GA4)? =

Yes. Connect your GA4 property from the Connections screen and ask about traffic, user behavior, conversions, and real-time data — through the built-in AI chat or any connected MCP client.

= Do I need a Google Cloud project? =

No. Click "Connect with Google" on the Connections screen and sign in through Specflux's hosted OAuth service to link Google Analytics 4 and Search Console without creating a Google Cloud project or OAuth client. If you prefer to use your own OAuth client, that option is still available under Settings > Google API.

= Do I need to pay for API access? =

The plugin itself is free. However, you may need API access for:
* Google Analytics and Search Console - Free with Google Cloud account
* Microsoft Clarity - Free

= Is my data secure? =

Yes. All API credentials are encrypted using libsodium before storage. OAuth tokens are handled securely with CSRF protection. No analytics data is stored permanently - it's fetched on demand.

= What can I ask the AI? =

You can ask questions like:
* "How did my traffic change compared to last week?"
* "What are my top performing pages?"
* "Show me my search console queries"
* "Compare my GA4 and Clarity data"

= What WordPress versions are supported? =

WordPress 7.0 and higher is required. The plugin uses the Abilities API and the AI Client, both included in WordPress 7.0 core, so the built-in chat works out of the box. Connecting external AI clients (Claude Desktop, Cursor) over MCP is optional and requires the separate MCP Adapter plugin, available from GitHub.

== Screenshots ==

1. Dashboard overview with connected platforms
2. MCP Abilities Catalog showing available analytics abilities
3. Google Analytics 4 connection setup
4. Settings page with API configuration

== Changelog ==

= 0.3.0 - 2026-08-18 =
* Added: One-click "Connect with Google" for Google Analytics 4 and Search Console through the Specflux sign-in service, so a Google Cloud project and OAuth client are no longer required. Sites that have already configured their own OAuth client keep using it, and the wizard remains available under Settings > Google API as an advanced option
* Changed: Documented the Specflux Google sign-in service under External Services
* Added: Command Palette commands (Cmd/Ctrl+K) to open the dashboard, refresh analytics data, and ask the AI
* Readme: clearer platform names and FAQ.
* Fixed: activation no longer requires ext-sodium when WordPress's bundled sodium_compat polyfill is available (fixes activation on WordPress Playground and hosts without ext-sodium)
* Fixed: removed legacy connection scripts that fired two failing admin-ajax calls on the Connections screen
* Fixed: reloading the Connections screen after Google sign-in no longer shows a "possible CSRF" error — the single-use callback parameters are cleared from the address bar
* Added: a dismissible, non-incentivized review prompt after the plugin has fetched analytics data a few times over at least a week (Maybe later / Don't ask again)

= 0.2.2 - 2026-07-25 =
* Fixed: The "documentation" links on the dashboard and the abilities catalog led to a page that does not exist; they now open the plugin's setup documentation

= 0.2.1 - 2026-07-25 =
* Changed: Removed the direct Claude, OpenAI, and Google Gemini chat integrations. Chat now runs solely through the WordPress AI Client, so the provider and its credentials are managed by WordPress at the site level and this plugin stores no AI keys of its own
* Changed: Documented every external service the plugin contacts, including the exact domains, the data sent, when it is sent, and links to each provider's terms of service and privacy policy
* Security: The Connections screen checks the plugin capability directly before handling the Google OAuth redirect, in addition to the existing OAuth state validation
* Changed: Refreshed all bundled libraries to their current stable releases

= 0.2.0 - 2026-07-02 =
* Added: Chat can be powered by the WordPress AI Client in core, with the AI provider configured once under Settings > Connectors
* Added: Behavior annotations (read-only, non-destructive, idempotent) on all registered abilities for better AI agent safety hints
* Security: Settings page (role permissions, Google OAuth client, debug mode) now requires the manage_options capability instead of plugin access alone
* Security: The chat view now verifies conversation ownership, preventing users from reading another user's chat history by ID
* Security: Credential values (API tokens and keys) are redacted before any debug logging, and error logging is gated behind debug mode
* Security: New conversations are always owned by the current user; a client-supplied user ID is ignored
* Fixed: Dashboard widget refresh no longer triggers a fatal error caused by mismatched analytics client arguments
* Fixed: AI chat tool-category filtering now matches abilities correctly instead of disabling all tools
* Fixed: Chat history now sends the most recent messages to the AI instead of the oldest, keeping long conversations coherent
* Fixed: The debug-mode toggle now takes effect
* Changed: Minimum required WordPress version is now 7.0 (Abilities API and AI Client in core)
* Changed: Admin notices now use the wp_admin_notice() core function
* Changed: Trimmed unused Google API service classes and refreshed bundled libraries to reduce package size

= 0.1.6 - 2026-05-09 =
* Fixed: Downgraded PHPUnit to ^10.5 for PHP 8.1 compatibility in CI
* Fixed: Updated menu slugs to use full specflux-marketing-analytics-chat prefix

= 0.1.5 - 2026-05-09 =
* Fixed: Upgraded jetpack-autoloader (5.0.16 → 5.0.17) and google/apiclient (2.19.0 → 2.19.3)
* Fixed: Removed inline &lt;style&gt; tag, replaced with wp_add_inline_style()
* Fixed: Sanitized $_COOKIE name/value before passing to WP_Http_Cookie
* Fixed: Added sanitize_text_field() before json_decode() in AJAX handler and prompts view
* Fixed: Prefixed all JS globals with specfluxMac to prevent naming conflicts

= 0.1.4 - 2026-04-20 =
* Fixed WordPress.org review issues
* Removed mcp-adapter from Requires Plugins (now optional recommendation)
* Fixed Plugin URI to correct repository URL
* Included composer.json in distribution
* Improved function prefixing for consistency

= 0.1.2 - 2025-12-13 =
* Added interactive onboarding wizard with guided setup
* Added Analytics at a Glance dashboard widget with sparkline trends
* Added admin bar analytics pulse indicator
* Added auto-installed smart prompt templates
* Added MCP Abilities Catalog page
* Added connection depth prompts for contextual suggestions
* Improved cross-platform summary abilities

= 0.1.1 - 2025-12-13 =
* Release version 0.1.1

= 0.1.0 - 2025-12-06 =
* Initial release
* MCP-native analytics abilities for AI assistants
* Google Analytics 4 integration
* Google Search Console integration
* Microsoft Clarity integration
* Secure OAuth and credential management
* Cross-platform comparison tools
* Smart caching system

== Upgrade Notice ==

= 0.3.0 =
Connecting Google Analytics 4 and Search Console no longer needs your own Google Cloud project — use the new "Connect with Google" button. Existing connections and custom OAuth clients keep working unchanged.

= 0.2.2 =
Repairs the broken documentation links on the dashboard and abilities catalog. No functional changes otherwise.

= 0.2.1 =
Chat now runs entirely through the WordPress AI Client, so no AI provider keys are stored by this plugin. Every external service the plugin contacts is documented in the readme. Requires WordPress 7.0+.

= 0.2.0 =
Important security and reliability update: sensitive settings now require admin capability, chat history is private per user, and several chat and dashboard bugs are fixed. Requires WordPress 7.0+. Recommended for all users.

= 0.1.2 =
New onboarding wizard, dashboard widget, and abilities catalog. Recommended update for all users.

= 0.1.0 =
Initial release. Please backup your site before installing.

== Privacy Policy ==

This plugin:
* Stores encrypted API credentials for the analytics platforms you connect in your WordPress database
* Connects only to the analytics services you configure, as detailed under External Services above
* Does not track your site's visitors and sends no data to the plugin author
* Does not store analytics data permanently; results are fetched on demand and cached temporarily
* Stores no AI provider credentials; chat is handled by the WordPress AI Client using the provider configured for your site
