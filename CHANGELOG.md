# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-05-22

### Added
- WordPress 7.0 compatibility — `Requires at least` bumped to 7.0.
- Integration with the core Abilities API observability hooks
  (`wp_before_execute_ability`, `wp_after_execute_ability`) for centralized
  execution logging of all `marketing-analytics/*` abilities.

### Changed
- MCP Adapter is now correctly documented as an optional GitHub-distributed
  plugin (it is not on WordPress.org). The plugin runs standalone on WP 7.0
  via the core Abilities API and AI Client; MCP Adapter is only required to
  expose abilities to external MCP clients (Claude Desktop, Cursor, ChatGPT).
- Admin notice on the Plugins screen now clarifies when MCP Adapter is needed
  and links to the correct GitHub source.

### Fixed
- Stale `@since 1.5.0` tag on the `specflux_mac_dashboard_cards` action hook
  (the hook was actually introduced in 0.1.2).

## [0.1.6] - 2026-05-09

### Fixed
- Downgraded PHPUnit to `^10.5` for PHP 8.1 compatibility in CI.
- Updated menu slugs to use full `specflux-marketing-analytics-chat` prefix.

## [0.1.5] - 2026-05-09

### Fixed
- Upgraded `jetpack-autoloader` (5.0.16 → 5.0.17) and `google/apiclient`
  (2.19.0 → 2.19.3).
- Removed inline `<style>` tag, replaced with `wp_add_inline_style()`.
- Sanitized `$_COOKIE` name/value before passing to `WP_Http_Cookie`.
- Added `sanitize_text_field()` before `json_decode()` in AJAX handler and
  prompts view.
- Prefixed all JS globals with `specfluxMac` to prevent naming conflicts.

## [0.1.4] - 2026-04-20

### Fixed
- WordPress.org review issues.
- Removed `mcp-adapter` from `Requires Plugins` (now optional recommendation).
- Fixed Plugin URI to correct repository URL.
- Included `composer.json` in distribution.
- Improved function prefixing for consistency.

## [0.1.2] - 2025-12-13

### Added
- Interactive onboarding wizard with guided setup.
- Analytics-at-a-glance dashboard widget with sparkline trends.
- Admin bar analytics pulse indicator.
- Auto-installed smart prompt templates.
- MCP Abilities Catalog page.
- Connection depth prompts for contextual suggestions.
- Improved cross-platform summary abilities.

## [0.1.1] - 2025-12-13

- Release version 0.1.1.

## [0.1.0] - 2025-12-06

### Added
- Initial release.
- MCP-native analytics abilities for AI assistants.
- Google Analytics 4 integration.
- Google Search Console integration.
- Microsoft Clarity integration.
- Secure OAuth and credential management.
- Cross-platform comparison tools.
- Smart caching system.

[0.2.0]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.2.0
[0.1.6]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.6
[0.1.5]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.5
[0.1.4]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.4
[0.1.2]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.2
[0.1.1]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.1
[0.1.0]: https://github.com/specflux/marketing-analytics-chat/releases/tag/v0.1.0
