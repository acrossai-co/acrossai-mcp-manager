# Phase 1 Data Model — Connection Method Discovery API DTO

**Feature**: F035 | **Date**: 2026-07-26 | **Plan**: [plan.md](./plan.md)

F035 has no persistent storage. This document specifies the DTO (plain associative array) shape that flows through every public method of `ConnectionMethodRegistry`. Consumers depend on this shape to persist to their own storage, serialize to REST responses, or render in their own UIs.

---

## Top-Level DTO Shape (invariant across all categories)

Every DTO across all three categories MUST have exactly these six top-level keys:

| Key           | Type   | Nullable | Description                                                                                    |
|---------------|--------|----------|------------------------------------------------------------------------------------------------|
| `category`    | string | No       | Category discriminator: exactly one of `'npm'`, `'client'`, `'ai_connector'`.                  |
| `slug`        | string | No       | Stable machine identifier. Shape: `[a-z0-9-]+`. Unique within a category (post-dedup).         |
| `name`        | string | No       | Human-readable display name. Translated via `__()` with `'acrossai-mcp-manager'` text domain.  |
| `description` | string | No       | One-line explanation. May be empty string. Translated via `__()`.                              |
| `icon`        | string | No       | Category-dependent icon. Emoji for `client`; URL for `ai_connector`; empty string for `npm`.   |
| `meta`        | array  | No       | Category-specific extras. See per-category sub-shapes below.                                   |

**Serialization invariant (SC-001)**: Every DTO MUST round-trip through `wp_json_encode()` → `json_decode(..., true)` losslessly. No closures, no objects, no resource handles anywhere in the tree.

**Uniqueness invariant (FR-009a)**: Within a category, `slug` is unique — later-wins dedup for `npm`, matches F034's dedup for `client`, matches F021's dedup for `ai_connector`. Cross-category collisions are allowed (an `npm` method and a `client` may both have slug `"foo"` — `find( 'npm', 'foo' )` and `find( 'client', 'foo' )` return distinct DTOs).

---

## Category `npm` — `meta` sub-shape

| Key                | Type   | Nullable | Description                                                                                              |
|--------------------|--------|----------|----------------------------------------------------------------------------------------------------------|
| `command_template` | string | No       | Copy-paste-ready command with `%s` placeholders. Built-in: `npx -y @acrossai/mcp-manager --siteurl=%s --server=%s`. |
| `enabled_option`   | string | No       | WP option NAME that gates this method. Built-in: `acrossai_mcp_npm_login_enabled`. **Consumer contract (SEC-035-003)**: Treated as a boolean gate flag. Consumers MUST verify the return of `get_option( $dto['meta']['enabled_option'] )` is truthy before considering the NPM method enabled. Consumers MUST NOT use this field as a general-purpose option-name channel or leak the returned value into their UI. |

**Built-in seed** (from `NpmClientBlock::get_default_npm_method()`):

```php
array(
    'category'    => 'npm',
    'slug'        => 'npx-acrossai-mcp-manager',
    'name'        => __( 'NPX', 'acrossai-mcp-manager' ),
    'description' => __( 'Run the AcrossAI MCP bridge as a local process via npx.', 'acrossai-mcp-manager' ),
    'icon'        => '',
    'meta'        => array(
        'command_template' => 'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s',
        'enabled_option'   => 'acrossai_mcp_npm_login_enabled',
    ),
)
```

---

## Category `client` — `meta` sub-shape

| Key             | Type   | Nullable | Description                                                                                       |
|-----------------|--------|----------|---------------------------------------------------------------------------------------------------|
| `config_file`   | string | No       | Path to the client's config file (as displayed to the user; e.g. `~/Library/Application Support/Claude/claude_desktop_config.json`). Sourced from `AbstractMCPClient::get_config_file()`. |
| `top_level_key` | string | No       | JSON top-level key the client expects (e.g. `mcpServers`). Sourced from `AbstractMCPClient::get_top_level_key()`. |
| `class`         | string | No       | FQN of the source `AbstractMCPClient` subclass (e.g. `AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient`). Used by consumers to `instanceof`-check or to render class-specific docs. |

**Source**: `AbstractMCPClient::get_all_registered_clients()` (F034 canonical enumeration). F035 maps each returned instance to a DTO via the abstract's getter methods. F035 MUST NOT re-fire `acrossai_mcp_client_classes`.

---

## Category `ai_connector` — `meta` sub-shape

| Key                       | Type   | Nullable | Description                                                                                    |
|---------------------------|--------|----------|------------------------------------------------------------------------------------------------|
| `icon_url`                | string | No       | Mirror of top-level `icon`. Duplicated in `meta` for consumers that only look at `meta` fields (parallel structure with `client.meta.config_file` accessibility). |
| `has_redirect_whitelist`  | bool   | No       | `true` if the profile's `get_redirect_uri_whitelist()` returns a non-empty array. Consumers use this to decide whether to render an OAuth redirect config UI. |
| `class`                   | string | No       | FQN of the source `AbstractConnectorProfile` subclass. |

**Source**: `ConnectorProfileRegistry::instance()->get_profiles()` (F021 canonical enumeration). F035 maps each returned instance to a DTO via the profile's public methods (`get_slug()`, `get_name()`, `get_icon_url()`, `get_redirect_uri_whitelist()`). F035 MUST NOT re-fire `acrossai_mcp_manager_connector_profiles`.

---

## `get_all()` Return Shape

```php
array(
    'npm'           => array(
        // Zero or more `npm` DTOs. Empty array if all methods removed via filter.
    ),
    'clients'       => array(
        // Zero or more `client` DTOs. Typically 8 (F034 built-ins) + N filter-contributed.
    ),
    'ai_connectors' => array(
        // Zero or more `ai_connector` DTOs. Zero on a fresh install (no companion plugins).
    ),
)
```

**Invariant**: All three keys MUST be present, even when a category is empty. Missing key ≠ empty array (consumers can `array_map` without `isset()` checks).

---

## Malformed-Contribution Handling

- **NPM (per FR-009b)**: Filter callback on `acrossai_mcp_npm_methods` returns an entry missing any of the six required top-level keys → entry silently dropped from the returned list; `_doing_it_wrong( 'ConnectionMethodRegistry::get_npm_methods', '<msg>', '0.1.9' )` fires under `WP_DEBUG`.
- **Cross-category (per FR-012a)**: Filter callback on `acrossai_mcp_connection_methods` returns non-array, or array missing any of the three required category keys → the entire filter return value is discarded; the pre-filter assembled result is returned instead; `_doing_it_wrong( 'ConnectionMethodRegistry::get_all', '<msg>', '0.1.9' )` fires under `WP_DEBUG`.
- **`client` (delegation)**: Malformed contribution to `acrossai_mcp_client_classes` is dropped by F034's `AbstractMCPClient::get_all_registered_clients()` per SEC-013-008. F035 does nothing extra — the invalid entry simply never reaches the DTO map.
- **`ai_connector` (delegation)**: Malformed contribution to `acrossai_mcp_manager_connector_profiles` is dropped by F021's `ConnectorProfileRegistry::get_profiles()` per its own validation. F035 does nothing extra.

**Test fixture guarantee**: Every consumer can iterate `get_all()` and every category-getter output with `array_map` under strict types without runtime error, on any WP install, regardless of what companion plugins have registered.

---

## Memoization Behaviour

- `get_all()` caches its assembled result in `$this->assembled_cache` on first call per-request. Subsequent calls within the same request return the cached array (no re-firing of any filter).
- Per-category getters (`get_npm_methods()`, `get_clients()`, `get_ai_connectors()`) are NOT memoized directly — they are cheap enough (single filter fire + O(n) map) that caching them independently would add complexity without measurable benefit. `get_all()` calls each per-category getter exactly once, then caches the composed result.
- `flush_cache()` sets `$this->assembled_cache = null`. Not called from production; called from PHPUnit `setUp()` for test isolation.
- Consumers who register `acrossai_mcp_npm_methods` or `acrossai_mcp_connection_methods` callbacks mid-request (rare) can call `flush_cache()` to see their filter contribution reflected in the next `get_all()` call. Standard usage (register at `plugins_loaded` / `init`) never needs this.
