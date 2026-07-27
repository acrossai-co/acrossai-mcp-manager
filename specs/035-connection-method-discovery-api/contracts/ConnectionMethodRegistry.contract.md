# Contract: `ConnectionMethodRegistry`

**Feature**: F035 | **Date**: 2026-07-26 | **Plan**: [../plan.md](../plan.md)

Normative reference for the class's public API. Every clause is a testable invariant that `/speckit-tasks` can map to one or more test tasks.

---

## Namespace + File

- **Namespace**: `AcrossAI_MCP_Manager\Public\Discovery`
- **File**: `public/Discovery/ConnectionMethodRegistry.php`
- **PHPDoc tag**: `@experimental until plugin 1.0.0` (per DEC-CLIENT-RENDERER-PUBLIC-API)

---

## Class Shape

```php
final class ConnectionMethodRegistry {

    protected static ?self $_instance = null;

    private ?array $assembled_cache = null;

    private function __construct() {}

    public static function instance(): self;
    public function get_all(): array;
    public function get_npm_methods(): array;
    public function get_clients(): array;
    public function get_ai_connectors(): array;
    public function find( string $category, string $slug ): ?array;
    public function flush_cache(): void;
}
```

**Public method count**: 7 (six required per FR-003 + `flush_cache()` for R2 memoization reset). `flush_cache()` is documented as a supported-but-rarely-used surface; not a scope violation.

---

## Method Contracts

### `instance(): self`

- **Post**: Returns the same instance on every call within a request (A2 singleton).
- **Post**: `new ConnectionMethodRegistry()` is unreachable (private ctor).

### `get_all(): array`

- **Post**: Returns array with exactly three keys: `'npm'`, `'clients'`, `'ai_connectors'`. All three keys present even when empty.
- **Post**: On first call per-request, assembles by calling `get_npm_methods()` + `get_clients()` + `get_ai_connectors()` exactly once each, then fires `acrossai_mcp_connection_methods` exactly once on the composed array.
- **Post**: On subsequent calls per-request, returns the memoized cache — no per-category getter re-invoked, no filter re-fired.
- **Post-Q3 / FR-012a**: If the filter callback returns non-array OR array missing any of the three required keys, discard the filter return, use the pre-filter assembled result, fire `_doing_it_wrong( 'ConnectionMethodRegistry::get_all', '<msg>', '0.1.9' )` under `WP_DEBUG`.
- **Post-SC-005 (grep gate)**: MUST NOT re-fire `acrossai_mcp_client_classes` or `acrossai_mcp_manager_connector_profiles`. Delegates to F034 + F021 registries that fire those filters themselves.

### `get_npm_methods(): array`

- **Post**: Fires `acrossai_mcp_npm_methods` exactly once per call, seeded with `array( NpmClientBlock::get_default_npm_method() )`.
- **Post-FR-009b**: Validates each contribution (a) has the six required top-level keys AND (b) the five string keys hold `is_string()` values AND `meta` holds `is_array()`. Invalid entries (missing key OR type-mismatched value) silently dropped + `_doing_it_wrong( 'ConnectionMethodRegistry::get_npm_methods', '<msg>', '0.1.9' )` under `WP_DEBUG`. Closes SEC-035-001.
- **Post-Q1 / FR-009a**: Dedups surviving entries by `slug` — later-wins (last DTO with a given slug survives; earlier collisions silently dropped).
- **Post**: Returned entries all have `category === 'npm'`. If a contribution has a different `category` value, it's still returned as-is (F035 does NOT rewrite `category`); a companion plugin can technically use this filter to inject a `client`-categorized entry, but F035's contract is documentary — the entry appears in `get_npm_methods()` output regardless.

### `get_clients(): array`

- **Post**: Delegates to `AbstractMCPClient::get_all_registered_clients()`; maps each returned instance to a DTO using the abstract's getter methods (`get_client_slug()`, `get_client_name()`, `get_description()`, `get_icon()`, `get_config_file()`, `get_top_level_key()`).
- **Post**: Every returned DTO has `category === 'client'`, `meta.class === get_class( $instance )`.
- **Post-SC-005**: MUST NOT call `apply_filters( 'acrossai_mcp_client_classes', ... )` directly.

### `get_ai_connectors(): array`

- **Post**: Delegates to `ConnectorProfileRegistry::instance()->get_profiles()`; maps each returned `AbstractConnectorProfile` instance to a DTO using the profile's public methods (`get_slug()`, `get_name()`, `get_icon_url()`, `get_redirect_uri_whitelist()`).
- **Post**: Every returned DTO has `category === 'ai_connector'`, `meta.class === get_class( $profile )`, `meta.icon_url === $dto['icon']`, `meta.has_redirect_whitelist === ! empty( $profile->get_redirect_uri_whitelist() )`.
- **Post-SC-005**: MUST NOT call `apply_filters( 'acrossai_mcp_manager_connector_profiles', ... )` directly.

### `find( string $category, string $slug ): ?array`

- **Pre**: `$category` is any string, `$slug` is any string. No validation.
- **Post**: Returns the DTO if `get_all()[ $category ]` contains an entry with matching `slug`. Otherwise returns `null`.
- **Post**: Uses `get_all()` internally (memoized) — one call per request unless `flush_cache()` was called between them.
- **Post**: No `_doing_it_wrong` on unknown `$category`; the return value (`null`) is the sole developer signal. Simplest possible ergonomic contract.

### `flush_cache(): void`

- **Post**: Sets `$this->assembled_cache = null`.
- **Post**: Does NOT flush F034's `AbstractMCPClient::get_all_registered_clients()` cache (that method is not memoized in F034 — it recomputes every call).
- **Post**: Does NOT flush F021's `ConnectorProfileRegistry::get_profiles()` cache.
- **Usage**: PHPUnit `setUp()` for test isolation; companion plugins that legitimately need mid-request filter re-registration.

---

## Filter Contracts (defined by F035)

### `acrossai_mcp_npm_methods`

- **Fired from**: `ConnectionMethodRegistry::get_npm_methods()` exactly once per call.
- **Signature**: `array apply_filters( 'acrossai_mcp_npm_methods', array $methods );`
- **Seed**: `array( NpmClientBlock::get_default_npm_method() )` — array of one DTO (the built-in npx bridge).
- **Consumer contract**: MAY append or replace entries. Each entry MUST be a DTO with the six required top-level keys (else silently dropped per FR-009b).

### `acrossai_mcp_connection_methods`

- **Fired from**: `ConnectionMethodRegistry::get_all()` exactly once per call. NOT fired by any per-category getter.
- **Signature**: `array apply_filters( 'acrossai_mcp_connection_methods', array $assembled );`
- **Seed**: The pre-filter three-category array (post per-category getter composition).
- **Consumer contract**: MAY modify the entire result (add/remove categories, prepend/append DTOs, decorate `meta`). Return value MUST be an array with the three required category keys (`npm`, `clients`, `ai_connectors`); else discarded and pre-filter result used per FR-012a.

---

## Preserved Invariants (delegation, not re-implementation)

- `acrossai_mcp_client_classes` — fired by F034's `AbstractMCPClient::get_all_registered_clients()`. F035 delegates.
- `acrossai_mcp_manager_connector_profiles` — fired by F021's `ConnectorProfileRegistry::get_profiles()`. F035 delegates.

**Grep gate (SC-005)**: `grep -rn "apply_filters.*acrossai_mcp_client_classes\|apply_filters.*acrossai_mcp_manager_connector_profiles" public/Discovery/` MUST return zero hits.

## Preserved Layering

**Grep gate (SC-006)**: `grep -rn "ConnectionMethodRegistry" includes/` MUST return zero hits. `public/` MUST NOT be imported into `includes/` (one-way dependency).
