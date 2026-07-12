# Extending: AI Connector Profiles

**Feature 021** exposes a public filter that lets companion plugins contribute
AI connector profiles to the AcrossAI MCP Manager. Each profile shows up as
a card on the per-server **AI Connectors** tab with its own Generate /
Regenerate button and setup instructions.

The base plugin ships **zero profiles** — every AI integration (Claude
Desktop, ChatGPT, Gemini, GitHub Copilot, …) is a small companion plugin
that adds a single callback to the filter.

**F021 Phase 9 (2026-07-11)**: `AbstractConnectorProfile` now ships a
**shared card shell** — CSS, JS, Generate/Regenerate handlers, Copy/Reveal
behavior, and REST wiring are all provided by the base plugin. A companion
plugin only implements the 6 abstract metadata methods and gets a fully
functional card for free. **The old pattern of overriding `render_tab_section`
to hand-roll the entire card is now discouraged** — use it only if your
connector needs a fundamentally different UI (e.g. a device-flow connector).

---

## The public API surface (permanent — do not remove without a major bump)

1. **Filter**: `acrossai_mcp_manager_connector_profiles`
2. **Abstract class**: `\AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile`
3. Four observability actions (below) — permanent action names and payloads.

Marked `@experimental May change without notice before 1.0.0` until the
plugin ships 1.0:
- Every `render_*` method on `AbstractConnectorProfile` (see §Card Shell below)
- Every `.acrossai-mcp-connector*` CSS class name
- Every `data-acrossai-*` attribute the shared JS listens for

---

## Minimal companion plugin

```php
<?php
/**
 * Plugin Name: AcrossAI Claude Connectors
 * Requires Plugins: acrossai-mcp-manager
 * Version: 0.1.0
 */

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;

final class Claude_Desktop_Profile extends AbstractConnectorProfile {

    public function get_slug(): string {
        return 'claude-desktop';
    }

    public function get_name(): string {
        return __( 'Claude Desktop', 'my-claude-connectors' );
    }

    public function get_icon_url(): string {
        return plugins_url( 'assets/claude.svg', __FILE__ );
    }

    public function get_redirect_uri_whitelist(): array {
        return array(
            'https://claude.ai/api/mcp/auth_callback',
            'http://localhost:33333/auth/callback', // for local Claude Desktop dev
        );
    }

    public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
        return sprintf(
            '<p>Open <strong>Claude Desktop → Settings → Developer → MCP Servers</strong> and paste:</p>
            <pre><code>%s</code></pre>',
            esc_html( wp_json_encode( array(
                'mcpServers' => array(
                    $server['server_slug'] => array(
                        'command' => 'npx',
                        'args'    => array(
                            '-y', '@modelcontextprotocol/server-example',
                            '--client-id', $client_id,
                            '--client-secret', $client_secret,
                            '--issuer', home_url(),
                        ),
                    ),
                ),
            ), JSON_PRETTY_PRINT ) )
        );
    }

    // NOTE: render_tab_section is NO LONGER required — the shell renders
    // the whole card automatically. Only override it if you need a
    // fundamentally different UI (device flow, no client_secret, etc.).

    public function get_consent_branding(): array {
        return array(
            'heading'             => __( 'Claude Desktop wants to connect', 'my-claude-connectors' ),
            'subtitle'            => __( 'Claude will access the MCP tools you have curated for this server.', 'my-claude-connectors' ),
            'permissions_bullets' => array(
                __( 'List your MCP tools', 'my-claude-connectors' ),
                __( 'Execute tools you have exposed', 'my-claude-connectors' ),
                __( 'Cannot access WP admin, database, or unauthorized files', 'my-claude-connectors' ),
            ),
        );
    }
}

add_filter(
    'acrossai_mcp_manager_connector_profiles',
    function ( array $profiles ): array {
        $profiles[] = new Claude_Desktop_Profile();
        return $profiles;
    }
);
```

---

## Card Shell (F021 Phase 9)

`AbstractConnectorProfile` ships concrete render helpers that produce a
uniform card look + behavior. **You don't need to touch any of these** for
a standard connector — the default `render_tab_section` calls them for you.

### What the shell renders

```
<section class="acrossai-mcp-connector acrossai-mcp-connector--{slug}"
         data-acrossai-connector-slug="{slug}">
  <header class="acrossai-mcp-connector__header">
    <img class="acrossai-mcp-connector__icon" src="{get_icon_url()}">
    <h3 class="acrossai-mcp-connector__title">{get_name()}</h3>
  </header>
  <div class="acrossai-mcp-connector__body">
    <!-- MCP URL row with Copy button -->
    <!-- If credentials exist: client_id row + Regenerate button -->
    <!-- If credentials do NOT exist: Generate button + description -->
    <div class="acrossai-mcp-connector__result" data-acrossai-result></div>
  </div>
</section>
```

### What the JS bundle does (auto-loaded on the AI Connectors tab)

- **Generate button** — POSTs `{server_id, connector_slug}` to
  `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` with `X-WP-Nonce`.
  On success, injects the `client_id` + one-time `client_secret` (with Copy
  and Reveal buttons) + your `get_setup_instructions()` HTML into
  `[data-acrossai-result]`. All secrets pass through `wp_kses_post` server-side
  (SEC-021-T02).
- **Regenerate button** — same endpoint, but with `window.confirm` gate.
- **Copy button (`[data-acrossai-copy]`)** — `navigator.clipboard.writeText`
  with `document.execCommand` fallback. Temporary "Copied!" flash.
- **Reveal button (`[data-acrossai-reveal]`)** — toggles the paired secret
  input's type between `password` and `text`.

### Overriding at different granularities

If your connector needs a slightly-different UI, override at the smallest
granularity that gets the job done:

| Method | Override when |
|---|---|
| `render_result_target` (protected) | You want to place the AJAX result target elsewhere in the DOM. |
| `render_url_row` (protected) | Your connector needs a different URL widget (e.g., a token URL instead of an MCP URL). |
| `render_credentials_area` (protected) | Your OAuth flow doesn't fit the Generate/Regenerate pattern (e.g., device flow). |
| `render_card_body` (protected) | You want to reorganize the whole body but keep the shared header. |
| `render_card_header` (protected) | You want a different header (e.g., add a "beta" pill). |
| `render_default_card` (protected) | You want to replace the whole card frame but call individual helpers. |
| `render_tab_section` (public) | You need a completely custom card that opts out of the shell. |

### `find_existing_client_id` helper

`AbstractConnectorProfile::find_existing_client_id( int $server_id ): ?string`
encapsulates the F021 `ClientRepository` lookup so your subclass doesn't
need to import it. It returns `null` when no client exists OR when the
base plugin's F021 Repository is absent (test bootstrap edge case).

---

## Method contract

| Method | Required | Notes |
|---|---|---|
| `get_slug(): string` | ✅ | Lowercase-kebab (`[a-z0-9-]{1,64}`). Stable across versions — join key to `OAuthClients.connector_slug`. Duplicates → later-wins with `_doing_it_wrong` under `WP_DEBUG`. |
| `get_name(): string` | ✅ | Free-text, translate via `__()` inside the profile. |
| `get_icon_url(): string` | ✅ | Absolute URL to 64×64 SVG or PNG. Rendered on tab card + optionally on consent screen. |
| `get_redirect_uri_whitelist(): array<int, string>` | ✅ | HTTPS or loopback only. Enforced strictly — see [`SEC-021-004`](../docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md#sec-021-004). |
| `get_setup_instructions( array $server, string $client_id, string $client_secret ): string` | ✅ | HTML shown after admin clicks Generate. **Passed through `wp_kses_post` before the response is returned to the browser** ([SEC-021-T02](../docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-tasks.md#sec-021-t02)). Script tags, `javascript:` URLs, and other unsafe HTML will be stripped. |
| `render_tab_section( array $server ): void` | **No longer required (F021 Phase 9)** | Default implementation calls `render_default_card()` — the shared shell. Override only if your connector needs a fundamentally different UI. See §Card Shell above. |
| `get_consent_branding(): array` | Optional | Overridable. Default returns a neutral heading. Return shape: `{ heading: string, subtitle: string, permissions_bullets: string[] }`. |

---

## Observability actions

Four permanent action names fire during OAuth lifecycle events. Payloads
below are stable — new positional args require a major version bump.

### `acrossai_mcp_manager_oauth_token_issued`

Fires once per issued access token (initial + rotation).

```php
do_action(
    'acrossai_mcp_manager_oauth_token_issued',
    int    $token_id,       // OAuthTokens.id
    string $client_id,      // OAuthClients.client_id
    int    $user_id,        // WP user id
    string $connector_slug  // OAuthClients.connector_slug ('' for DCR clients)
);
```

### `acrossai_mcp_manager_oauth_authorization_denied`

Fires when the operator clicks Deny on the consent screen.

```php
do_action(
    'acrossai_mcp_manager_oauth_authorization_denied',
    string $client_id,
    string $redirect_uri,
    string $reason  // 'user_denied' (only value currently emitted)
);
```

### `acrossai_mcp_manager_oauth_token_revoked`

Fires **once per row** transitioned to `revoked=1`. Reason enum:

| Reason | When |
|---|---|
| `'refresh_rotation'` | Standard refresh-token rotation — the presented token is revoked. |
| `'client_regenerated'` | Admin clicked Regenerate on AI Connectors tab. |
| `'user_deleted'` | WordPress user was deleted (FR-042 cascade). |
| `'family_reuse_detected'` | Refresh token reuse detected — the entire family is revoked (SEC-021-001 / RFC 9700 §2.2.2). |

```php
do_action(
    'acrossai_mcp_manager_oauth_token_revoked',
    int    $token_id,
    string $reason
);
```

### `acrossai_mcp_manager_oauth_cleanup`

Daily cron hook — fires at the start of each cleanup run. Integrators can
piggy-back on the schedule.

```php
do_action( 'acrossai_mcp_manager_oauth_cleanup' );
```

---

## Filter interaction rules

- **The filter fires exactly ONCE per request** (`ConnectorProfileRegistry`
  memoizes). Adding a callback after the first `get_profiles()` call has
  no effect.
- **Duplicate slugs**: later-registered profile wins. `_doing_it_wrong`
  notice under `WP_DEBUG`.
- **Non-`AbstractConnectorProfile` entries are silently discarded** with
  `_doing_it_wrong` under `WP_DEBUG`.
- **Slug validation**: `/[a-z0-9-]{1,64}/`. Non-matching → discarded.

---

## Trusted-proxy filter (for reverse-proxied installs)

If your WordPress install sits behind a reverse proxy (Cloudflare, nginx,
AWS ALB, …) the plugin needs to know which upstream IPs to trust so
`X-Forwarded-For` can be honoured for rate limiting.

```php
add_filter(
    'acrossai_mcp_manager_trusted_proxies',
    function ( array $proxies ): array {
        // CIDRs — IPv4 or IPv6.
        return array( '10.0.0.0/8', 'fd00::/8' );
    }
);
```

**Default**: empty array. `X-Forwarded-For` is IGNORED — every request buckets
against `$_SERVER['REMOTE_ADDR']`. See [`SEC-021-003`](../docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md#sec-021-003).

---

## Testing your profile

The base plugin ships an abstract `OAuthTestCase` at
`tests/phpunit/OAuth/OAuthTestCase.php` that truncates the three OAuth
tables + resets registry memoization on every test. Companion plugins can
extend it via composer autoload or copy the pattern.

```php
class My_Claude_Profile_Test extends \AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth\OAuthTestCase {

    public function test_profile_is_registered(): void {
        add_filter(
            'acrossai_mcp_manager_connector_profiles',
            function ( array $p ) {
                $p[] = new Claude_Desktop_Profile();
                return $p;
            }
        );

        $profile = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::instance()
            ->get_profile( 'claude-desktop' );

        $this->assertNotNull( $profile );
        $this->assertSame( 'Claude Desktop', $profile->get_name() );
    }
}
```

---

## References

- **RFC 6749** — OAuth 2.0 Authorization Framework
- **RFC 7591** — OAuth 2.0 Dynamic Client Registration
- **RFC 7636** — Proof Key for Code Exchange (PKCE)
- **RFC 8414** — OAuth 2.0 Authorization Server Metadata
- **RFC 8707** — Resource Indicators for OAuth 2.0 (audience-binding)
- **RFC 9207** — OAuth 2.0 Authorization Server Issuer Identification
- **RFC 9700** — OAuth 2.0 Security Best Current Practice (family revocation, §2.2.2)
- **RFC 9728** — OAuth 2.0 Protected Resource Metadata
- **MCP Authorization Spec** — https://modelcontextprotocol.io/specification/draft/basic/authorization
- **Anthropic Claude Connector Auth** — https://claude.com/docs/connectors/building/authentication
