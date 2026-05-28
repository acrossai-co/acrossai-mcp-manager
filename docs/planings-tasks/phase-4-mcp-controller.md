# Phase 4 — MCP Controller Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/MCP/Controller.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new file:
  includes/MCP/Controller.php

Extend (do NOT replace):
  includes/Main.php            ← wire rest_api_init hooks via Loader here
```

---

## What this phase covers

Migrates `src/MCP/Controller.php` to `includes/MCP/Controller.php`.

The MCP Controller is the heart of the plugin — it boots MCP server instances
by reading DB rows and registering each server with WordPress's REST API.

### Old code location

`src/MCP/Controller.php` — namespace `ACROSSAI_MCP_MANAGER\MCP`

### Target location

`includes/MCP/Controller.php` — namespace `AcrossAI_MCP_Manager\Includes\MCP`

### Hook migration pattern

Old (inside constructor):
```php
add_action( 'rest_api_init', array( $this, 'register_database_servers' ) );
add_action( 'rest_api_init', array( $this, 'register_default_server' ) );
```

New (in `includes/Main.php::define_admin_hooks()` or `define_public_hooks()`
depending on context — REST is shared so use `define_admin_hooks()`):
```php
$mcp_controller = new Includes\MCP\Controller( new Includes\Database\MCPServer\Query() );
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_database_servers' );
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_default_server' );
```

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: MCP Controller — Server Boot and Registration
Feature number: 004

The MCP Controller reads enabled MCP server rows from the database and registers
each as a REST API endpoint in WordPress. It bridges the plugin's DB layer and
the WordPress MCP Adapter package (\WP\MCP\Plugin).

Functional requirements:

1. On rest_api_init, read all enabled server rows using MCPServer\Query::get_enabled().

2. For each enabled server row, call the MCP adapter's server registration API
   (currently via \WP\MCP\Plugin or equivalent) using the server's:
   - server_name
   - server_slug
   - server_route_namespace
   - server_route
   - server_version
   This is "register_database_servers".

3. The "default server" is the built-in plugin-registered server (registered_from = 'plugin').
   It must be registered separately via "register_default_server" to ensure it always
   exists even before a DB row is present.

4. If \WP\MCP\Plugin class does not exist, registration is skipped gracefully
   (no fatal — this is the "MCP adapter not installed" scenario).

5. No direct add_action() calls in the Controller constructor.
   The Controller is a plain service class; the Loader in Main.php wires its hooks.

6. DB access goes through the injected MCPServer\Query instance (from Phase 1),
   not static MCPServerTable:: calls.

7. Namespace: AcrossAI_MCP_Manager\Includes\MCP
   File: includes/MCP/Controller.php
```

### Step 2: `/speckit.plan`

```
/speckit.plan

Controller class design:

class Controller {
    private MCPServer\Query $mcp_query;

    public function __construct( MCPServer\Query $mcp_query ) {
        $this->mcp_query = $mcp_query;
    }

    public function register_database_servers(): void { ... }
    public function register_default_server(): void { ... }
}

Dependency injection:
  In Main::define_admin_hooks():
    $mcp_query      = new Includes\Database\MCPServer\Query();
    $mcp_controller = new Includes\MCP\Controller( $mcp_query );
    $this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_database_servers' );
    $this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_default_server' );

MCP adapter guard:
  if ( ! class_exists( '\WP\MCP\Plugin' ) ) { return; }

Port logic verbatim from src/MCP/Controller.php but:
  - Replace ACROSSAI_MCP_MANAGER\Database\MCPServerTable::get_all()
    with $this->mcp_query->get_all()
  - Replace ACROSSAI_MCP_MANAGER\Database\MCPServerTable::get_enabled()
    with $this->mcp_query->get_enabled()
  - Remove constructor add_action() calls
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] `includes/MCP/Controller.php` exists with updated namespace
- [ ] Constructor takes `MCPServer\Query` as injected dependency — no DB static calls
- [ ] No `add_action()` in constructor — hooks wired via Loader in `Main.php`
- [ ] MCP servers visible in REST API when adapter plugin is active
- [ ] Plugin activates cleanly when adapter is absent (graceful fallback)
