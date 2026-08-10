# CLAUDE.md — Free Plugin

This is the **free WordPress.org plugin** for Specflux Marketing Analytics Chat. It exposes Clarity, GA4, and Google Search Console data via MCP.

## Scope

This folder is a standalone WordPress plugin distributed on WordPress.org. It must NOT contain any premium features, license checks, or references to the pro add-on.

## Structure

```
free/
├── specflux-marketing-analytics-chat.php       # Main plugin file
├── composer.json
├── includes/
│   ├── class-plugin.php               # Core orchestrator, fires extensibility hooks
│   ├── class-activator.php            # Plugin activation (no DB tables)
│   ├── class-deactivator.php
│   ├── class-loader.php
│   ├── abilities/                     # MCP abilities (Clarity, GA4, GSC, Cross-platform, Prompts)
│   ├── admin/                         # Admin UI + AJAX handler
│   ├── api-clients/                   # Clarity, GA4, GSC clients
│   ├── cache/                         # WordPress Transients caching
│   ├── credentials/                   # Encrypted credential management + OAuth
│   ├── prompts/                       # Custom prompt manager
│   └── utils/                         # Logger, Permission Manager
├── admin/                             # CSS, JS, views
└── tests/
```

## Namespace & Text Domain

- Namespace: `Specflux_Marketing_Analytics\`
- Text domain: `specflux-marketing-analytics-chat`
- All strings: `esc_html_e( 'String', 'specflux-marketing-analytics-chat' )`

## Naming Conventions — One Rule Per Context

| Context | Prefix | Example |
|---|---|---|
| Script/style handles | `specflux-mac-` | `specflux-mac-admin`, `specflux-mac-sparklines` |
| JS globals (`wp_localize_script`) | `specfluxMac` | `specfluxMacAdmin`, `specfluxMacChat` |
| Menu/page slugs (appear in URLs) | `specflux-marketing-analytics-chat-` | `specflux-marketing-analytics-chat-settings` |
| Options, hooks, AJAX, transients, nonces | `specflux_mac_` | `specflux_mac_settings`, `specflux_mac_save` |
| PHP constants | `SPECFLUX_MAC_` | `SPECFLUX_MAC_VERSION` |
| CSS classes / design tokens | `.smac-` / `--smac-` | `.smac-card`, `--smac-primary` |
| Text domain / plugin slug | `specflux-marketing-analytics-chat` | (fixed, matches WordPress.org slug) |

**Rule of thumb:** use `specflux-mac-` everywhere except where WordPress requires the full slug (menu slugs that appear in admin URLs) or where WordPress.org mandates the exact slug (text domain, plugin directory).

## Development Commands

```bash
composer install
vendor/bin/phpunit                    # Tests
vendor/bin/phpcs                      # Coding standards
vendor/bin/phpcbf                     # Auto-fix
vendor/bin/phpstan analyse            # Static analysis
```

## Extensibility Hooks for Premium

The free plugin fires these hooks — the premium add-on hooks into them. Do NOT remove these:

```php
do_action( 'specflux_mac_loaded' );                       // class-plugin.php
do_action( 'specflux_mac_register_pro_abilities' );        // class-abilities-registrar.php
do_action( 'specflux_mac_admin_menu' );                    // class-admin.php
do_action( 'specflux_mac_connections_tabs' );              // connections.php
do_action( 'specflux_mac_connections_tab_content', $tab ); // connections.php
do_action( 'specflux_mac_settings_tabs', $tab );          // settings.php
do_action( 'specflux_mac_settings_tab_content', $tab );   // settings.php
do_action( 'specflux_mac_register_ajax_handlers' );       // class-ajax-handler.php
```

## Key Rules

- **WordPress.org compliance**: All output escaped, all input sanitized with `wp_unslash()`, nonces on all forms
- **No pro code**: Never add license checks, premium features, or upsell nags that reference specific pricing
- **PHPCS clean**: Must pass `vendor/bin/phpcs` before submission
- **No vendor/ in repo**: Composer dependencies installed at build time

## API Clients

| Client | Rate Limit | Cache TTL |
|--------|-----------|-----------|
| Clarity_Client | 10 req/day | 1 hour |
| GA4_Client | Standard | 30 minutes |
| GSC_Client | Standard | 24 hours |

All three clients construct parameterless and resolve their own credentials — `new Clarity_Client()`, `new GA4_Client()`, `new GSC_Client()`. Clarity's constructor still accepts `($api_token, $project_id)` for one caller only: the connection test in `Ajax_Handler::test_clarity_connection()`, which runs before anything is saved. It throws if no credentials are supplied and none are stored.

## CSS Design Tokens

All styles use `--smac-*` custom properties in `admin/css/admin-styles.css`. Never hardcode colors. Use `.status-badge.connected` / `.disconnected` for status indicators.

## WordPress Coding Standards

- Follow WPCS (tabs, spacing, Yoda conditions)
- `wp_unslash()` before `sanitize_text_field()` on all `$_POST`/`$_GET`
- Escape all output: `esc_html_e()`, `esc_attr_e()`, `esc_url()`
- Translators comments directly before `__()`, not before `sprintf()`
- Never use reserved globals: `$cat`, `$post`, `$id`, `$page`, `$tag`
- WPCS requires `class-` prefix on all class files, including abstract classes (`class-abstract-llm-provider.php`, not `abstract-llm-provider.php`)

## Insights & Learnings

### NamingConsistencyTest
`tests/unit/NamingConsistencyTest.php` scans all PHP files for text domain, option prefix, hook prefix, AJAX action, menu slug, nonce action, transient prefix, and DB table naming consistency. Run it after any naming changes — it caught 5 pre-existing bugs during the Specflux rename.

### MCP Ability Names Are Protocol Identifiers
The `marketing-analytics/` prefix in ability names (e.g., `marketing-analytics/get-clarity-insights`) and the `marketing-analytics` category are MCP protocol identifiers, not plugin branding. Do NOT rename these when changing the plugin slug or display name.

### Repository Structure: free/ Is the GitHub Repo
`free/` is a standalone git repo with its own remote (`github.com/specflux/marketing-analytics-chat`). The root directory is a local-only wrapper with no remote. GitHub Actions workflows must live in `free/.github/workflows/` — the root `.github/workflows/` files are stale copies GitHub never sees. All workflow file paths are relative to `free/` as repo root (no `free/` prefix needed).

### WordPress 7.0 Baseline: Abilities API, AI Client, MCP Adapter
The plugin requires WordPress 7.0+ (since 0.2.0). Core provides the Abilities API (`wp_register_ability`, `wp_get_ability`) and the AI Client (`wp_ai_client_prompt()`, Settings > Connectors for provider keys). MCP Adapter remains a separate plugin that bridges abilities to the Model Context Protocol. This plugin works standalone for built-in chat (direct Abilities API calls in `class-mcp-client.php`), but external MCP clients (Claude Desktop, Cursor) need MCP Adapter. MCP Adapter is not yet on WordPress.org — preserved code with hard dependency is on branch `feature/mcp-adapter-dependency` for when it's available.

### WP AI Provider (native core AI Client)
`class-wp-ai-provider.php` (settings slug `wp-ai`, default for new installs) routes chat through the core AI Client — no API key stored in the plugin. The slug is deliberately `wp-ai`, NOT `wordpress`: the WPCS CapitalPDangit sniff flags the literal `'wordpress'` and phpcbf would corrupt it to `'WordPress'`. Tool names sent to the model use core's `wpab__` prefix convention (`wpab__marketing-analytics__get-ga4-metrics`).

### Soft Dependency Pattern for WordPress.org
For optional plugin dependencies not on WordPress.org, use a soft check: call `is_plugin_active()`, show dismissible `notice-info` (not `notice-error`), limit display to relevant admin pages via `get_current_screen()`, and always return `true` so the plugin runs regardless. See `check_plugin_dependencies()` in main plugin file.
