=== Marketing Analytics Chat ===
Contributors: stephenpaulsamynathan
Donate link: https://www.specflux.com/
Tags: marketing analytics, ai, chat, mcp
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 0.1.2
Requires PHP: 8.1
Requires Plugins: mcp-adapter
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose Google Analytics 4, Search Console, and Microsoft Clarity data to AI assistants via the Model Context Protocol.

== Description ==

Marketing Analytics Chat lets you have conversations with your marketing data using AI. Connect your analytics platforms and ask questions in plain English to get instant insights and recommendations.

= Supported Platforms =

* **Google Analytics 4** - Traffic metrics, user behavior, conversions, real-time data
* **Google Search Console** - Search performance, queries, indexing status
* **Microsoft Clarity** - Session recordings, heatmaps, user behavior insights

= Key Features =

* **Built-in AI Chat** - Chat with your analytics data using Claude, OpenAI, or Google Gemini directly in WordPress
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

= Pro Add-on =

Additional platforms and features are available through the Pro add-on, including Meta Business Suite, DataForSEO, WooCommerce analytics, AI-powered insights, anomaly detection, and notification integrations. Visit our website for details.

= How It Works =

1. Connect your analytics platforms via OAuth or API keys
2. Configure your MCP client (e.g., Claude Desktop, ChatGPT, Cursor)
3. Ask questions like "How did my traffic change this week?"
4. Get AI-powered insights and recommendations

= Requirements =

* WordPress 6.9 or higher (includes Abilities API in core)
* MCP Adapter plugin (from WordPress.org)
* PHP 8.1 or higher
* SSL certificate (HTTPS) for OAuth connections
* PHP extensions: json, curl, openssl, sodium

= External Services =

This plugin connects to the following third-party services when you configure the corresponding platform connections:

* **Google Analytics Data API** (https://developers.google.com/analytics/devguides/reporting/data/v1) - Retrieves traffic metrics, user behavior, and conversion data from your GA4 property. Your GA4 property ID and OAuth tokens are sent to Google servers. [Google Privacy Policy](https://policies.google.com/privacy)
* **Google Analytics Admin API** (https://developers.google.com/analytics/devguides/config/admin/v1) - Lists your GA4 properties during connection setup. [Google Privacy Policy](https://policies.google.com/privacy)
* **Google Search Console API** (https://developers.google.com/webmaster-tools/search-console-api-original) - Retrieves search performance data including queries, clicks, and impressions. Your site URL and OAuth tokens are sent to Google servers. [Google Privacy Policy](https://policies.google.com/privacy)
* **Microsoft Clarity Data Export API** (https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api) - Retrieves session recordings, heatmap data, and user behavior insights. Your Clarity API token and project ID are sent to Microsoft servers. [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement)
* **Anthropic API** (https://docs.anthropic.com/en/api) - When Claude is selected as the AI provider, your analytics data and chat messages are sent to Anthropic's servers for AI responses. Requires your own API key. [Anthropic Privacy Policy](https://www.anthropic.com/privacy)
* **OpenAI API** (https://platform.openai.com/docs) - When OpenAI is selected as the AI provider, your analytics data and chat messages are sent to OpenAI's servers for AI responses. Requires your own API key. [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
* **Google Gemini API** (https://ai.google.dev/docs) - When Gemini is selected as the AI provider, your analytics data and chat messages are sent to Google's servers for AI responses. Requires your own API key. [Google Privacy Policy](https://policies.google.com/privacy)

== Installation ==

= Prerequisites =

1. Ensure you are running WordPress 6.9 or higher (includes Abilities API)
2. Install and activate the "MCP Adapter" plugin from WordPress.org

= Plugin Installation =

1. Upload the `marketing-analytics-chat` folder to `/wp-content/plugins/`
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

WordPress 6.9 and higher is required. The plugin uses the Abilities API (included in WordPress 6.9 core) and requires the MCP Adapter plugin.

== Screenshots ==

1. Dashboard overview with connected platforms
2. MCP Abilities Catalog showing available analytics abilities
3. Google Analytics 4 connection setup
4. Settings page with API configuration

== Changelog ==

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

= 0.1.2 =
New onboarding wizard, dashboard widget, and abilities catalog. Recommended update for all users.

= 0.1.0 =
Initial release. Please backup your site before installing.

== Privacy Policy ==

This plugin:
* Stores encrypted API credentials in your WordPress database
* Connects to third-party analytics services you configure
* Does not track users or send data to the plugin author
* Does not store analytics data permanently (fetched on demand)

For full privacy information, see the plugin documentation.
