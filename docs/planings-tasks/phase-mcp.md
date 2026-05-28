# Phase MCP — MCP Controller + MCP Client Classes

## Source Files to Read First

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/MCP/Controller.php
  src/MCPClients/AbstractMCPClient.php
  src/MCPClients/ClaudeCodeClient.php
  src/MCPClients/ClaudeDesktopClient.php
  src/MCPClients/CodexClient.php
  src/MCPClients/CursorClient.php
  src/MCPClients/CustomClient.php
  src/MCPClients/GitHubCopilotClient.php
  src/MCPClients/VSCodeClient.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new files:
  includes/MCP/Controller.php
  includes/MCPClients/AbstractMCPClient.php
  includes/MCPClients/ClaudeCodeClient.php
  includes/MCPClients/ClaudeDesktopClient.php
  includes/MCPClients/CodexClient.php
  includes/MCPClients/CursorClient.php
  includes/MCPClients/CustomClient.php
  includes/MCPClients/GitHubCopilotClient.php
  includes/MCPClients/VSCodeClient.php

Extend (do NOT replace):
  includes/Main.php              ← wire rest_api_init for MCP Controller only
```

---

## Part A — MCP Controller (`phase-4`)

### What this covers

Migrates `src/MCP/Controller.php` → `includes/MCP/Controller.php`.

The MCP Controller reads enabled server rows from the DB and registers each as a
REST endpoint via the `wordpress/mcp-adapter` package.

### Hook migration

Old (in constructor):
```php
add_action( 'rest_api_init', array( $this, 'register_database_servers' ) );
add_action( 'rest_api_init', array( $this, 'register_default_server' ) );
```

New (in `includes/Main.php::define_admin_hooks()`):
```php
$mcp_controller = new Includes\MCP\Controller( new Includes\Database\MCPServer\Query() );
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_database_servers' );
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_default_server' );
```

### Step 1 — `/speckit.specify`

```
/speckit.specify

Feature: MCP Controller — Server Boot and Registration
Feature number: 003

The MCP Controller reads enabled MCP server rows from the database and registers
each as a REST API endpoint via the WordPress MCP Adapter package (\WP\MCP\Plugin).

Functional requirements:

1. On rest_api_init, read all enabled server rows via MCPServer\Query::get_enabled().

2. For each enabled server row call the MCP adapter's registration API using:
   server_name, server_slug, server_route_namespace, server_route, server_version.
   This is register_database_servers().

3. register_default_server() registers the built-in plugin server (registered_from='plugin')
   separately to guarantee it always exists even before a DB row is present.

4. If \WP\MCP\Plugin does not exist, skip registration gracefully — no fatal.

5. No add_action() in constructor. Plain service class; Loader wires its hooks.

6. DB access via injected MCPServer\Query, not static MCPServerTable:: calls.

Namespace: AcrossAI_MCP_Manager\Includes\MCP
File: includes/MCP/Controller.php
```

### Step 2 — `/speckit.plan`

```
/speckit.plan

class Controller {
    private MCPServer\Query $mcp_query;

    public function __construct( MCPServer\Query $mcp_query ) {
        $this->mcp_query = $mcp_query;
    }

    public function register_database_servers(): void {
        if ( ! class_exists( '\WP\MCP\Plugin' ) ) { return; }
        foreach ( $this->mcp_query->get_enabled() as $row ) { /* register */ }
    }

    public function register_default_server(): void {
        if ( ! class_exists( '\WP\MCP\Plugin' ) ) { return; }
        /* register built-in server */
    }
}

Wiring in Main::define_admin_hooks():
  $mcp_query      = new Includes\Database\MCPServer\Query();
  $mcp_controller = new Includes\MCP\Controller( $mcp_query );
  $this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_database_servers' );
  $this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_default_server' );
```

---

## Part B — MCP Client Classes (`phase-5`)

### What this covers

Migrates 8 pure service classes from `src/MCPClients/` → `includes/MCPClients/`.
These generate config snippets for each AI tool client. **They have no WordPress hooks.**

| Old | New |
|---|---|
| `src/MCPClients/AbstractMCPClient.php` | `includes/MCPClients/AbstractMCPClient.php` |
| `src/MCPClients/ClaudeCodeClient.php` | `includes/MCPClients/ClaudeCodeClient.php` |
| `src/MCPClients/ClaudeDesktopClient.php` | `includes/MCPClients/ClaudeDesktopClient.php` |
| `src/MCPClients/CodexClient.php` | `includes/MCPClients/CodexClient.php` |
| `src/MCPClients/CursorClient.php` | `includes/MCPClients/CursorClient.php` |
| `src/MCPClients/CustomClient.php` | `includes/MCPClients/CustomClient.php` |
| `src/MCPClients/GitHubCopilotClient.php` | `includes/MCPClients/GitHubCopilotClient.php` |
| `src/MCPClients/VSCodeClient.php` | `includes/MCPClients/VSCodeClient.php` |

### Step 1 — `/speckit.specify`

```
/speckit.specify

Feature: MCP Client Classes — Pure Service Layer
Feature number: 004

Each client class generates the configuration snippet a user copies into their
AI tool to connect it to the MCP server.

Supported clients:
  AbstractMCPClient — base class with shared helpers (build_server_url, redact_token)
  ClaudeCodeClient, ClaudeDesktopClient, CodexClient, CursorClient,
  CustomClient, GitHubCopilotClient, VSCodeClient

Requirements:
1. Each concrete client extends AbstractMCPClient.
2. Each exposes:
   get_config_snippet( string $server_url, string $auth_token ): string|array
   get_client_name(): string
   get_client_slug(): string
3. No WordPress hooks — pure service classes.
4. Instantiated by Admin Settings renderer for the per-client setup tab.

Namespace: AcrossAI_MCP_Manager\Includes\MCPClients
Files: includes/MCPClients/*.php
```

### Step 2 — `/speckit.plan`

```
/speckit.plan

Migration:
- Copy 8 files from src/MCPClients/ to includes/MCPClients/
- Replace namespace: ACROSSAI_MCP_MANAGER\MCPClients → AcrossAI_MCP_Manager\Includes\MCPClients
- Update internal cross-references

No hook wiring — clients are injected into Settings:
  $settings = new Admin\Partials\Settings(
      new Includes\Database\MCPServer\Query(),
      new Admin\Partials\ApplicationPasswords(),
      Includes\MCPClients\AbstractMCPClient::get_all_clients()
  );
```

---

## Shared Workflow Steps (run once after both plans are approved)

### Step 3 — `/speckit.clarify` _(optional)_

Run if either Part A or Part B spec needs clarification.

```
/speckit.clarify
```

---

### Step 4 — `/speckit.memory-md.index-project`

```
/speckit.memory-md.index-project
```

---

### Step 5 — `/speckit.architecture-guard.governed-plan`

```
/speckit.architecture-guard.governed-plan
```

---

### Step 6 — `/speckit.security-review.full`

```
/speckit.security-review.full
```

---

### Step 7 — `/speckit.tasks`

Run for Part A (Controller) first, then Part B (Clients).

```
/speckit.tasks
```

---

### Step 8 — `/speckit.architecture-guard.governed-tasks`

```
/speckit.architecture-guard.governed-tasks
```

---

### Step 9 — `/speckit.implement`

Implement Part A first (Controller must exist before it can be wired in Main.php),
then Part B (Clients).

```
/speckit.implement
```

---

### Step 10 — `/speckit.analyze`

```
/speckit.analyze
```

---

### Step 11 — `/speckit.architecture-guard.drift-analysis`

```
/speckit.architecture-guard.drift-analysis
```

---

### Step 12 — `/speckit.security-review.full`

```
/speckit.security-review.full
```

---

### Step 13 — `/speckit.memory-md.merge-features`

```
/speckit.memory-md.merge-features
```

---

### Step 14 — Git commit _(automatic)_

Triggered automatically after `/speckit.analyze` completes.

---

## Success Criteria

**MCP Controller:**
- [ ] `includes/MCP/Controller.php` — namespace `AcrossAI_MCP_Manager\Includes\MCP`
- [ ] Constructor accepts `MCPServer\Query` — no static DB calls
- [ ] No `add_action()` in constructor — wired via Loader
- [ ] Servers visible in REST API when adapter plugin is active
- [ ] Plugin activates cleanly when adapter is absent (graceful guard)

**MCP Client Classes:**
- [ ] All 8 files exist in `includes/MCPClients/`
- [ ] Namespace updated in all files: `AcrossAI_MCP_Manager\Includes\MCPClients`
- [ ] No WordPress hooks in any client class
- [ ] Admin settings page can instantiate all clients and render config snippets
