# Quickstart — Consuming the Connection Method Discovery API

**Feature**: F035 | **Date**: 2026-07-26 | **Plan**: [plan.md](./plan.md)

A 5-minute walkthrough for companion-plugin developers. Exercises User Stories 1, 2, and 3 as documentation.

Target audience: authors of add-on plugins (e.g., the planned BuddyBoss add-on) that need to enumerate every connection method the site supports.

---

## 1. Enumerate every connection method (User Story 1)

```php
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;

$all = ConnectionMethodRegistry::instance()->get_all();

// $all is:
// array(
//     'npm'           => array( … DTOs … ),
//     'clients'       => array( … DTOs … ),
//     'ai_connectors' => array( … DTOs … ),
// );

foreach ( $all as $category => $methods ) {
    foreach ( $methods as $dto ) {
        // Every DTO has the same six top-level keys:
        printf(
            "[%s] %s (%s)\n",
            $dto['category'],
            $dto['name'],
            $dto['slug']
        );
    }
}
```

> **Security note (SEC-035-002)**: DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed by admin-installed companion plugins and are NOT pre-escaped by F035. If you render these values into an admin page, REST response, or frontend HTML, escape at the render boundary using the most-specific WordPress escaping function (`esc_html()`, `esc_attr()`, `esc_url()` per context). This mirrors F034's SEC-034-001 preservation invariant.

Serialize to your own storage — every DTO round-trips through JSON losslessly:

```php
$json = wp_json_encode( $all );          // safe — plain associative arrays only
$restored = json_decode( $json, true );  // structurally identical to $all
```

---

## 2. Look up one specific method

```php
$dto = ConnectionMethodRegistry::instance()->find( 'client', 'claude-desktop' );

if ( null === $dto ) {
    // Not found. No exception, no WP_Error — the return value IS the signal.
    return;
}

// $dto === array(
//     'category'    => 'client',
//     'slug'        => 'claude-desktop',
//     'name'        => 'Claude Desktop',
//     'description' => 'The official Anthropic Claude desktop app.',
//     'icon'        => '🍰',
//     'meta'        => array(
//         'config_file'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
//         'top_level_key' => 'mcpServers',
//         'class'         => 'AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient',
//     ),
// );
```

`find()` is fine to call on any string — an unknown `$category` or unknown `$slug` returns `null`. No error.

---

## 3. Add a custom NPM method (User Story 2)

Register a new NPM bridge command (e.g., a yarn-based alternative) via the `acrossai_mcp_npm_methods` filter:

```php
add_filter( 'acrossai_mcp_npm_methods', function ( array $methods ): array {
    $methods[] = array(
        'category'    => 'npm',
        'slug'        => 'yarn-mcp-bridge',
        'name'        => __( 'Yarn MCP Bridge', 'my-plugin' ),
        'description' => __( 'Alternative bridge using yarn dlx.', 'my-plugin' ),
        'icon'        => '',
        'meta'        => array(
            'command_template' => 'yarn dlx @myco/mcp-bridge --site=%s --server=%s',
            'enabled_option'   => 'my_plugin_yarn_bridge_enabled',
        ),
    );
    return $methods;
} );
```

Now `get_npm_methods()` returns 2 items: the built-in npx bridge + your yarn bridge.

**Rules the filter enforces**:
- Your DTO MUST have all six top-level keys. Missing any → silently dropped + `_doing_it_wrong( 'ConnectionMethodRegistry::get_npm_methods', ..., '0.1.9' )` under `WP_DEBUG`.
- If your DTO's `slug` collides with an earlier entry (including the built-in `npx-acrossai-mcp-manager`), yours wins (later-wins dedup).

---

## 4. Customize the assembled result cross-category (User Story 3)

Remove one entire category or curate DTOs across all three at once via `acrossai_mcp_connection_methods`:

```php
add_filter( 'acrossai_mcp_connection_methods', function ( array $assembled ): array {
    // Example: remove all NPM methods (e.g., because your deployment blocks local CLI bridges).
    $assembled['npm'] = array();

    // Example: filter out one specific AI connector by slug.
    $assembled['ai_connectors'] = array_values( array_filter(
        $assembled['ai_connectors'],
        static fn ( $dto ) => $dto['slug'] !== 'unwanted-connector'
    ) );

    return $assembled;
} );
```

**Rules the filter enforces**:
- Return value MUST be an array with all three category keys (`npm`, `clients`, `ai_connectors`).
- Missing any key OR non-array return → your callback's return is DISCARDED; `get_all()` returns the pre-filter assembled result + fires `_doing_it_wrong( 'ConnectionMethodRegistry::get_all', ..., '0.1.9' )` under `WP_DEBUG`. Your bug can't crash consumers.
- Fires exactly ONCE inside `get_all()`. NOT fired when a consumer calls `get_npm_methods()` / `get_clients()` / `get_ai_connectors()` in isolation — those return their per-category output unfiltered by the cross-category filter.

---

## 5. Register your own MCP client or AI connector

F035 does NOT add its own extension seam for the `client` or `ai_connector` categories — those categories delegate to the existing seams:

- **New MCP client**: Register a subclass of `AbstractMCPClient` via `acrossai_mcp_client_classes` (F034 seam). Your client appears automatically in `get_clients()` output.

  ```php
  add_filter( 'acrossai_mcp_client_classes', function ( array $fqns ): array {
      $fqns[] = \My\Plugin\MyCustomClient::class;
      return $fqns;
  } );
  ```

- **New AI connector**: Register a subclass of `AbstractConnectorProfile` via `acrossai_mcp_manager_connector_profiles` (F021 seam). Your connector appears automatically in `get_ai_connectors()` output.

---

## Memoization + mid-request filter changes (advanced)

`get_all()` memoizes per-request. If you register a filter callback AFTER the first `get_all()` call in the same request (rare — normally callbacks register at `plugins_loaded` / `init`), call `flush_cache()` to see your change reflected on the next call:

```php
add_filter( 'acrossai_mcp_npm_methods', $callback );

ConnectionMethodRegistry::instance()->flush_cache();

$all = ConnectionMethodRegistry::instance()->get_all();  // reflects $callback
```

Normal usage (register callbacks before any consumer calls `get_all()`) never needs this.

> **Security implication (SEC-035-004)**: If your plugin registers a security-critical filter callback AFTER any code has already called `get_all()` in the same request, that callback WILL NOT take effect until you call `ConnectionMethodRegistry::instance()->flush_cache()`. Register filter callbacks early (at `plugins_loaded` or `init`) to guarantee they apply on every discovery-API consumer's first query.

---

## Contract stability

`ConnectionMethodRegistry` is marked `@experimental until plugin 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API. Plugin releases before 1.0.0 MAY change the DTO shape between minor versions. Post-1.0.0, any breaking DTO shape change requires a MAJOR version bump per semver.
