# Planning: Wizard Client Snippet Parity (Feature 073)

The per-server edit page's **Clients** tab (`?page=acrossai_mcp_manager&action=edit&server=<id>&tab=clients`) renders every registered MCP client with its real per-server Configuration JSON, a Config File path row, a Top-Level Key row, and a per-client Instructions callout (plus a shared Access Control paragraph). The Quick Setup wizard's **Step 11 (MCP Client detail)** was built with the same shape (picker + JSON pane + App Password notice) but was missing the actual snippet content and the metadata/instructions rows — the JSON pane fell back to dumping the raw client DTO. F073 unifies the two surfaces on one source of truth so operators see identical output regardless of entry point.

## Authoritative sources

- Spec: [`specs/073-wizard-client-snippet-parity/spec.md`](../../specs/073-wizard-client-snippet-parity/spec.md)
- Plan: [`specs/073-wizard-client-snippet-parity/plan.md`](../../specs/073-wizard-client-snippet-parity/plan.md)
- Tasks: [`specs/073-wizard-client-snippet-parity/tasks.md`](../../specs/073-wizard-client-snippet-parity/tasks.md)

## One source of truth (the architectural picture)

`AbstractMCPClient` (`includes/MCPClients/AbstractMCPClient.php`) is the single canonical source for every displayable client attribute:

| Attribute | Method | Consumed on Clients tab | Consumed on wizard Step 11 (post-F073) |
|---|---|---|---|
| Slug / name / description / icon | `get_client_slug/name/description/icon()` | `MCPClientsBlock` pill row | `Step11_ClientDetail` pill row |
| Config file path | `get_config_file()` | `MCPClientsBlock:205-211` | DTO `meta.config_file` → `qs-meta-row` |
| Top-Level Key | `get_top_level_key()` | `MCPClientsBlock:214-220` | DTO `meta.top_level_key` → `qs-meta-row` |
| Configuration JSON | `get_config_snippet($server_url, $auth_token)` | `MCPClientsBlock:227-231` | DTO `config` (server-scoped) → `<CodeBlock variant="pane">` |
| Per-client instructions | `get_instructions()` | `MCPClientsBlock:252-258` | DTO `instructions` → `<Notice status="info">` |
| Shared Access Control paragraph | Hardcoded `__()` at `MCPClientsBlock:256` | Second `<p>` of the same notice | Second `<p>` of the same notice, same translation key |

Both surfaces route through `ConnectionMethodRegistry::get_clients()` (`public/Discovery/ConnectionMethodRegistry.php`) after F073. The registry method now optionally accepts a raw MCPServer row, resolves the server's REST URL identically to `MCPClientsBlock:224-226`, and produces DTOs whose `config` field is byte-identical to the `<textarea>` body the Clients tab emits (same `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` flags).

## Final scope

Retained:
- `ConnectionMethodRegistry::get_clients()` — optional `?array $server = null` param; `instructions` always populated; `config` populated when server context is present.
- `QuickSetupController::handle_state()` — resolves the wizard's active server (from scratchpad `server_id`) and overrides `$methods['clients']` with the server-scoped variant.
- `Step11_ClientDetail.jsx` — Config File + Top-Level Key rows above the JSON pane; per-client Instructions callout + shared Access Control paragraph below.
- Two new SCSS utility classes for the metadata rows (`.qs-meta-row`, `.qs-meta-label`, `.qs-meta-value`).

Not in scope:
- No changes to `AbstractMCPClient` or any of the 16 concrete client classes — all displayable data was already exposed via public methods.
- No changes to the Clients tab (`ClientsTab.php`, `MCPClientsBlock.php`) — additive-only on the registry side.
- No new REST endpoint — piggybacks on the existing `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` route.
- No new React component — reuses `CodeBlock` and `Notice`.

## Durable lesson

**When two surfaces render the same domain object, unify at the DATA layer, not the UI layer.** The Clients tab (PHP-rendered) and the wizard's Step 11 (React) cannot literally share a component, but they can share `AbstractMCPClient::get_config_snippet()` + `get_instructions()` + `get_config_file()` + `get_top_level_key()`. Doing so gives byte-identical output on both surfaces, keeps future translations single-keyed, and means adding a new client class (per F034 / F071) auto-populates both surfaces with no additional work.

The trap this avoids: it's tempting to add a new React component that hardcodes client shape or replicates the JSON encoding. That silently drifts from the Clients tab the moment either surface adds a field. Threading a shared DTO through the existing registry method + REST endpoint is a smaller change AND keeps drift impossible.

## Reference code

```php
// public/Discovery/ConnectionMethodRegistry.php — the key addition
$server_url = null;
if ( null !== $server
	&& isset( $server['server_route_namespace'], $server['server_route'] )
) {
	$server_url = rest_url(
		trailingslashit( (string) $server['server_route_namespace'] )
		. (string) $server['server_route']
	);
}
// ...
if ( null !== $server_url ) {
	$snippet       = $client->get_config_snippet( $server_url, '' );
	$dto['config'] = is_array( $snippet )
		? (string) wp_json_encode(
			$snippet,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
		: (string) $snippet;
}
```

```php
// includes/REST/QuickSetupController.php::handle_state() — the wiring
if ( $server_id > 0 ) {
	$rows = MCPServerQuery::instance()->query(
		array( 'id' => $server_id, 'number' => 1 )
	);
	if ( ! empty( $rows ) ) {
		$methods['clients'] = ConnectionMethodRegistry::instance()->get_clients(
			$rows[0]->to_array()
		);
	}
}
```

Added: 2026-08-20 on branch `072-quick-setup-entry-points` (pending branch-hygiene split; see plan.md § Branch / commit hygiene).
