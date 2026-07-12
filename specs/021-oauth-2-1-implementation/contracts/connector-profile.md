# `AbstractConnectorProfile` Contract — Feature 021

Companion plugins contribute one `AbstractConnectorProfile` subclass per AI connector they support (Claude Desktop, ChatGPT, Gemini, GitHub Copilot, etc.). The base plugin ships ZERO profiles — the tab shows an empty state until at least one companion is installed.

**Class**: `AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile`
**Registration**: via `acrossai_mcp_manager_connector_profiles` filter (see `php-hooks.md`).

---

## Class contract

### Required (abstract) methods

```php
abstract public function get_slug(): string;
```

A unique, lowercase-kebab identifier (e.g., `'claude-desktop'`, `'chatgpt-web'`, `'gemini-code-assist'`). Must be stable across plugin versions — this is the join key to the `OAuthClients.connector_slug` column. Duplicates within a single request → later-wins with `_doing_it_wrong` under `WP_DEBUG`.

**Constraints**: `[a-z0-9-]{1,64}`. Enforced at registry admission.

```php
abstract public function get_name(): string;
```

Human-readable display name (e.g., `'Claude Desktop'`, `'ChatGPT'`). Rendered on the `AIConnectorsTab` cards and in the consent screen heading fallback.

**Constraints**: Free-text, translated via `__()` inside the profile.

```php
abstract public function get_icon_url(): string;
```

Absolute URL to a square icon (recommended 64×64 SVG or PNG). Rendered on the tab card + optionally on the consent screen (via `get_consent_branding()`).

**Constraints**: Must be a valid absolute URL. Companion plugins typically use `plugins_url( 'assets/icon.svg', __FILE__ )`.

```php
abstract public function get_redirect_uri_whitelist(): array;
```

Returns an array of exact redirect URIs the connector is permitted to use. For admin-generated clients (F021 `AIConnectorsTab`), this list becomes the `OAuthClients.redirect_uris` column verbatim. At `/authorize` time, the incoming `redirect_uri` must byte-match one entry.

**Example**:

```php
return array(
    'https://claude.ai/api/mcp/auth_callback',
    'http://localhost:33333/auth/callback',   // for local Claude Desktop dev
);
```

**Constraints**: HTTPS or loopback (`127.0.0.1`, `localhost`, `::1` on any port). Invalid entries cause admin `generate-client` to reject with 400 before insertion.

```php
abstract public function get_setup_instructions(
    array  $server,          // MCPServer row array
    string $client_id,       // Just-issued client_id
    string $client_secret    // Just-issued raw client_secret (visible ONCE)
): string;
```

Returns HTML instructing the operator on how to configure the AI connector with the credentials. Rendered inside the tab card after Generate is clicked (raw secret visible once) and cached in escaped form in `render_tab_section` on subsequent visits (with secret replaced by `<hidden — regenerate to view>`).

**Escaping**: The profile is responsible for escaping. `AIConnectorsTab` renders the HTML via `wp_kses_post` — profiles should return content that survives that filter. Code blocks (`<pre>`, `<code>`) and inline text (`<strong>`, `<em>`) survive; scripts and iframes are stripped.

**Example**:

```php
public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
    return sprintf(
        '<p>Open <strong>Claude Desktop → Settings → Developer → MCP Servers</strong> and paste:</p>
        <pre><code>%s</code></pre>',
        esc_html( wp_json_encode( array(
            'mcpServers' => array(
                $server['server_slug'] => array(
                    'command' => 'npx',
                    'args'    => array( '-y', '@modelcontextprotocol/server-example',
                        '--client-id', $client_id,
                        '--client-secret', $client_secret,
                        '--issuer', home_url(),
                    ),
                ),
            ),
        ), JSON_PRETTY_PRINT ) )
    );
}
```

```php
abstract public function render_tab_section( array $server ): void;
```

Called by `AIConnectorsTab::render_body()` when an existing client already exists for this (server, connector) pair. Directly echoes HTML — profiles can render whatever section they want (e.g., "Connected as {user}", "Regenerate" button, troubleshooting FAQ). This method is invoked with an escaped context — profiles should still escape any dynamic values they render.

### Optional (concrete) methods

```php
public function get_consent_branding(): array {
    return array(
        'heading' => sprintf(
            /* translators: %s: connector name */
            __( '%s wants to connect to your site', 'acrossai-mcp-manager' ),
            $this->get_name()
        ),
        'subtitle' => __(
            'This will allow the application to access the MCP tools you have exposed on this server.',
            'acrossai-mcp-manager'
        ),
        'permissions_bullets' => array(),
    );
}
```

Overridable to show brand-specific messaging on the consent screen. Default returns a neutral heading built from `get_name()`.

**Return shape** (strict):

```php
array{
    heading: string,
    subtitle: string,
    permissions_bullets: string[],
}
```

The consent template escapes each field before rendering. `permissions_bullets` is optional but useful — e.g., `['Read your MCP tools list', 'Execute allowed tools on your behalf']`.

---

## Registry behavior

`ConnectorProfileRegistry::get_profiles(): array<AbstractConnectorProfile>` — memoized per request. First call fires the filter, deduplicates by slug, sorts by slug, caches the array. All subsequent calls return the cached array.

`ConnectorProfileRegistry::get_profile( string $slug ): ?AbstractConnectorProfile` — lookup by slug. Returns null if no profile registered.

`register_profile()` is **intentionally absent** — the ONLY registration path is the filter. Companion plugins that try to bypass this by directly calling registry methods will hit `_doing_it_wrong` under `WP_DEBUG`.

---

## Example — Complete Minimal Profile

```php
<?php
namespace MyCompanion\Connectors;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;

final class ClaudeDesktopProfile extends AbstractConnectorProfile {

    public function get_slug(): string {
        return 'claude-desktop';
    }

    public function get_name(): string {
        return __( 'Claude Desktop', 'my-companion' );
    }

    public function get_icon_url(): string {
        return plugins_url( 'assets/claude.svg', __FILE__ );
    }

    public function get_redirect_uri_whitelist(): array {
        return array(
            'https://claude.ai/api/mcp/auth_callback',
        );
    }

    public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
        // Return the HTML shown to the operator right after Generate.
        return sprintf(
            '<p>Copy this into Claude Desktop → Settings → Developer:</p><pre>%s</pre>',
            esc_html( wp_json_encode( array( /* ... */ ), JSON_PRETTY_PRINT ) )
        );
    }

    public function render_tab_section( array $server ): void {
        printf(
            '<p><strong>%s</strong></p>',
            esc_html__( 'Claude Desktop is configured for this server.', 'my-companion' )
        );
    }

    public function get_consent_branding(): array {
        return array(
            'heading' => __( 'Claude Desktop wants to connect', 'my-companion' ),
            'subtitle' => __( 'Claude will access the MCP tools you have curated for this server.', 'my-companion' ),
            'permissions_bullets' => array(
                __( 'List your MCP tools', 'my-companion' ),
                __( 'Execute tools you have exposed', 'my-companion' ),
                __( 'Cannot access WP admin, database, or unauthorized files', 'my-companion' ),
            ),
        );
    }
}
```

Registration:

```php
add_filter( 'acrossai_mcp_manager_connector_profiles', function ( array $profiles ) {
    $profiles[] = new \MyCompanion\Connectors\ClaudeDesktopProfile();
    return $profiles;
} );
```

---

## Testing surface

`ConnectorProfileRegistryTest` (planned Phase 2 T4-1) verifies:

1. Filter contribution registers a profile.
2. Two profiles with the same slug → later-wins with `_doing_it_wrong`.
3. Empty filter output → empty array.
4. Memoization → filter fires exactly once per request even under 100 `get_profiles()` calls.

Companion plugins should ship their own tests against this contract — the base plugin's tests don't cover companion-specific `get_setup_instructions` or `render_tab_section` output.
