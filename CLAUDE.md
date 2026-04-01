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
