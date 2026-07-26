# Quickstart — Add a new MCP client via a companion plugin

**Audience**: Companion-plugin developer who wants to expose a new AI editor (Zed, Sublime, Continue, …) as an MCP client alongside the eight built-ins.
**Time to complete**: ~5 minutes.
**Prerequisites**: F034 shipped (this plugin's `AbstractMCPClient` has the six metadata methods introduced by this feature).

---

## Step 1 — Create your subclass

Anywhere in your companion plugin's PHP source (namespace whatever you like), create a class extending `AbstractMCPClient`:

```php
<?php
namespace MyCompanyPlugin\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;

class ZedClient extends AbstractMCPClient {

    // --- REQUIRED (3 abstract methods) -------------------------------------

    public function get_client_slug(): string {
        return 'zed'; // MUST match /^[a-z0-9-]{1,64}$/
    }

    public function get_client_name(): string {
        return __( 'Zed Editor', 'my-companion-plugin' );
    }

    public function get_config_snippet( string $server_url, string $auth_token ): array {
        return array(
            'mcpServers' => array(
                $this->derive_server_key( $server_url ) => array(
                    'command' => 'npx',
                    'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
                    'env'     => array(
                        'WP_API_URL'      => $server_url,
                        'WP_API_USERNAME' => $this->current_username(),
                        'WP_API_PASSWORD' => $this->safe_token( $auth_token ),
                    ),
                ),
            ),
        );
    }

    // --- OPTIONAL (6 metadata methods — all default to '' or 100) ---------

    public function get_icon(): string {
        return '⚡'; // Or an emoji Zed's brand is associated with.
    }

    public function get_description(): string {
        return __( 'Zed collaborative AI editor', 'my-companion-plugin' );
    }

    public function get_config_file(): string {
        return '~/.config/zed/settings.json'; // Untranslated — technical path.
    }

    public function get_top_level_key(): string {
        return 'mcpServers'; // Untranslated — literal JSON key.
    }

    public function get_instructions(): string {
        return __(
            'Generate a password → copy the JSON → open ~/.config/zed/settings.json → paste under mcpServers → restart Zed.',
            'my-companion-plugin'
        );
    }

    public function get_priority(): int {
        // 100 (default) → your client sorts AFTER all built-ins (which use 10–80).
        // Override with a lower number to interleave with built-ins:
        // return 45; // between Codex (50) and GitHubCopilot (40)
        return 100;
    }
}
```

**Notes**:
- The `use` import for `AbstractMCPClient` uses the full `AcrossAI_MCP_Manager\Includes\MCPClients` namespace. This is the plugin's public API surface for the client subsystem.
- Text-domain in `__()` calls MUST be your companion plugin's own text domain — NOT `'acrossai-mcp-manager'`. WordPress i18n loads translations per plugin.
- Protected helpers (`derive_server_key`, `current_username`, `safe_token`) are available to your subclass for building the config snippet. See `docs/planings-tasks/035-mcp-client-metadata-refactor.md` §Pattern reference for the sibling `AbstractConnectorProfile` idioms if you want a fuller helper repertoire.

---

## Step 2 — Register your class via the filter

Anywhere your companion plugin bootstraps its hooks (typically the plugin's main file or a Loader class):

```php
add_filter( 'acrossai_mcp_client_classes', function ( array $fqns ): array {
    $fqns[] = \MyCompanyPlugin\MCPClients\ZedClient::class;
    return $fqns;
} );
```

That's the entire registration. The base plugin's `AbstractMCPClient::get_all_registered_clients()` will:
- Validate your FQN (must be a string, must be a real class, must extend `AbstractMCPClient`) — invalid contributions silently skipped per SEC-013-008.
- Validate your slug (`/^[a-z0-9-]{1,64}$/`) — invalid slug → `_doing_it_wrong` under `WP_DEBUG`, contribution skipped.
- Dedup against any existing client with the same slug — duplicate → `_doing_it_wrong` under `WP_DEBUG`, later contribution wins.
- Sort by `get_priority()` ascending, tiebreaker slug ascending.

---

## Step 3 — Verify it appears

1. Activate your companion plugin on a site running this plugin (0.1.7+ recommended; 0.1.8+ once F034 ships).
2. Navigate to `Admin → MCP Manager → Edit Server → Clients` tab.
3. Confirm your client appears in the sub-nav with its declared icon + name, in its declared position (after all built-ins by default, or in the slot corresponding to your `get_priority()` override).
4. Click your client's sub-nav pill. Confirm the panel shows your declared description, config file path, top-level key label, and instructions.
5. Generate an Application Password from the panel's button and confirm the copy-paste JSON/string snippet contains the real password (not the `EMPTY_TOKEN_PLACEHOLDER`).

---

## Step 4 — (Optional) Test your class

Add a PHPUnit test in your companion plugin:

```php
namespace MyCompanyPlugin\Tests;

use MyCompanyPlugin\MCPClients\ZedClient;
use PHPUnit\Framework\TestCase;

class ZedClientTest extends TestCase {

    public function test_slug_matches_public_contract(): void {
        $client = new ZedClient();
        $this->assertMatchesRegularExpression( '/\A[a-z0-9-]{1,64}\z/', $client->get_client_slug() );
    }

    public function test_all_metadata_getters_non_empty(): void {
        $client = new ZedClient();
        $this->assertNotEmpty( $client->get_icon() );
        $this->assertNotEmpty( $client->get_description() );
        $this->assertNotEmpty( $client->get_config_file() );
        $this->assertNotEmpty( $client->get_top_level_key() );
        $this->assertNotEmpty( $client->get_instructions() );
    }

    public function test_config_snippet_embeds_both_args(): void {
        $client = new ZedClient();
        $snippet = $client->get_config_snippet( 'https://example.test/wp-json/mcp/default', 'abcd 1234 efgh 5678' );
        $json = wp_json_encode( $snippet );
        $this->assertStringContainsString( 'example.test', $json );
        $this->assertStringContainsString( 'abcd 1234', $json );
    }
}
```

No WordPress bootstrap required — the client class is a pure service per A11.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Client doesn't appear in the sub-nav | Filter not registered, or FQN mistyped | Check `apply_filters` output via `error_log( print_r( apply_filters( 'acrossai_mcp_client_classes', [] ), true ) )` |
| Client appears but sub-nav shows no icon / empty description | `get_icon()` / `get_description()` not overridden | Add the overrides (see Step 1) |
| `_doing_it_wrong` fires in `debug.log` for your slug | Slug doesn't match `/^[a-z0-9-]{1,64}$/` | Rename to lowercase alphanumeric + hyphens, ≤64 chars |
| Two of your clients collapse into one sub-nav slot | Two subclasses returning the same slug | Give each a unique slug |
| Client appears in a different position than expected | Default priority 100 with alphabetical tiebreaker | Override `get_priority()` explicitly (e.g. `return 45;`) |
| Config snippet renders `(paste generated password here)` in production | Application Password not yet generated by the admin | Click "Generate password" in the client panel first |

---

## Reference materials

- **Contract**: [`contracts/AbstractMCPClient.contract.md`](./contracts/AbstractMCPClient.contract.md) — the normative shape your subclass MUST honour.
- **Data model**: [`data-model.md`](./data-model.md) — full method list + defaults + procedure for `get_all_registered_clients()`.
- **Sibling pattern** (identical shape for AI connector profiles): `includes/Connectors/AbstractConnectorProfile.php` + `includes/Connectors/ConnectorProfileRegistry.php`.
- **Engineering brief**: `docs/planings-tasks/035-mcp-client-metadata-refactor.md` (filename says 035; F034 is the shipped feature number).
