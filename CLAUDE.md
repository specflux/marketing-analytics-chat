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

**Clarity constructor asymmetry**: Clarity_Client requires `($api_token, $project_id)` — unlike GA4/GSC which are parameterless. Special-case any dynamic instantiation.

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

### WordPress 7.0 Abilities API, AI Client, and MCP Adapter
WordPress 7.0 ships the Abilities API (`wp_register_ability`, `wp_get_ability`) and the AI Client in core. The MCP Adapter remains a separate plugin distributed via GitHub / Composer (NOT WordPress.org) at https://github.com/wordpress/mcp-adapter. This plugin works standalone for built-in chat (uses direct Abilities API calls in `class-mcp-client.php`) and is compatible with the WP 7.0 core AI Client because both consume the same ability registry. MCP Adapter is only required to expose abilities to external MCP clients (Claude Desktop, Cursor, ChatGPT). The plugin uses a soft-check pattern: it runs without MCP Adapter and shows a dismissible info notice on the Plugins screen — see `check_plugin_dependencies()` in the main plugin file.

### WordPress 7.0 Abilities API Observability Hooks
WP 7.0 added `wp_before_execute_ability` and `wp_after_execute_ability` action hooks plus the `wp_register_ability_args` filter. The plugin wires its `Logger` into the before/after hooks (filtered to the `marketing-analytics/` namespace) so every ability execution is centrally logged in debug mode — see `Abilities_Registrar::log_ability_start()` / `log_ability_end()` and the hook registrations in `Plugin::define_abilities_hooks()`. Do NOT add per-callback logging inside individual ability classes — it duplicates the central path.

### Soft Dependency Pattern for WordPress.org
For optional plugin dependencies not on WordPress.org, use a soft check: call `is_plugin_active()`, show dismissible `notice-info` (not `notice-error`), limit display to relevant admin pages via `get_current_screen()`, and always return `true` so the plugin runs regardless. See `check_plugin_dependencies()` in main plugin file.
