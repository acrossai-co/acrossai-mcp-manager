# Planning: CLI Auth URL Cache-Exclusion Notice (Feature 041)

Push a persistent warning into the shared `acrossai_notices` collection when the operator enables **Settings → MCP → Allow CLI connections via npm / npx**. The warning tells the operator (and any co-admin) to exclude the frontend CLI authorization URL (`https://<site>/acrossai-mcp-manager/`) from page caching, because the page carries per-request nonces + single-use auth codes and will silently fail authentication if cached.

The inline `<div class="notice notice-warning">` banner in `SettingsMenu::render_npm_section_description()` stays — the shared-notice row is the **second, cross-screen surface** so admins who never open that specific settings section still see the warning.

## Authoritative sources

- Spec: [`specs/041-cli-auth-cache-exclusion-notice/spec.md`](../../specs/041-cli-auth-cache-exclusion-notice/spec.md)
- Plan: [`specs/041-cli-auth-cache-exclusion-notice/plan.md`](../../specs/041-cli-auth-cache-exclusion-notice/plan.md)
- Tasks: [`specs/041-cli-auth-cache-exclusion-notice/tasks.md`](../../specs/041-cli-auth-cache-exclusion-notice/tasks.md)
- PR: https://github.com/acrossai-co/acrossai-mcp-manager/pull/70

## Final scope

Retained:
- 15-line append to `admin/Partials/Notices.php::register_shared_notices()` gated on `get_option( 'acrossai_mcp_npm_login_enabled', false )`.
- URL resolved via `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`, escaped via `esc_url()`, wrapped in `<code>…</code>`.
- Notice `id`, `title`, `message`, `type = warning`, `source = MCP Manager` fields per the `Notices.php:96-111` filter contract.

Not in scope:
- No new REST endpoint, no new setting, no new DB row.
- No modification to the existing inline banner in `SettingsMenu`.
- No `WP_CACHE`-based gating — the notice fires on the CLI setting regardless of hosting environment (nonces exist whether cache is present or not; excluding the URL is always the correct configuration).

## Durable lesson

**Any setting whose ON state exposes a nonce or OTP-carrying URL MUST push a conditional cache-exclusion warning into `acrossai_notices`** — the inline settings-section banner alone is invisible after the operator's first visit and to co-admins who did not perform the toggle. Captured in `docs/memory/WORKLOG.md` under the 2026-08-09 entry. Precedent (site-wide `WP_CACHE` version): `acrossai-ai-connectors/admin/Partials/Notices.php` id `acrossai_ai_connectors_page_cache_exclusions_required`.

## Reference code

```php
// admin/Partials/Notices.php — inside register_shared_notices()
if ( (bool) get_option( 'acrossai_mcp_npm_login_enabled', false ) ) {
    $auth_url  = \AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url();
    $notices[] = array(
        'id'      => 'acrossai_mcp_manager_cli_auth_cache_exclusion',
        'title'   => __( 'Exclude CLI auth URL from page cache', 'acrossai-mcp-manager' ),
        'message' => sprintf(
            /* translators: %s: the frontend CLI authorization URL, wrapped in <code>. */
            __( 'The npm / npx CLI connection flow is enabled. The frontend authorization page at %s contains time-sensitive auth codes and nonces. If your hosting, CDN, or caching plugin caches this URL, authentication will silently fail. Exclude this path from all page-caching rules.', 'acrossai-mcp-manager' ),
            '<code>' . esc_url( $auth_url ) . '</code>'
        ),
        'type'    => 'warning',
        'source'  => __( 'MCP Manager', 'acrossai-mcp-manager' ),
    );
}
```

Added: 2026-08-09 via PR #70 (branch `feat/cli-auth-cache-exclusion-notice`, commit hash on merge).
