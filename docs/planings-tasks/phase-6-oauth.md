# Phase 6 — OAuth / Claude Connectors Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/OAuth/ClaudeConnectors.php
  src/OAuth/AuthorizationCodeResponseType.php
  src/OAuth/AuthorizeController.php
  src/OAuth/Storage.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new files into:
  includes/OAuth/ClaudeConnectors.php
  includes/OAuth/AuthorizationCodeResponseType.php
  includes/OAuth/AuthorizeController.php
  includes/OAuth/Storage.php

Extend (do NOT replace):
  includes/Main.php            ← wire init, rest_api_init, template_redirect, query_vars via Loader
  includes/Activator.php       ← register rewrite rules using ClaudeConnectors class constants
```

---

## What this phase covers

Migrates the OAuth layer from `src/OAuth/` to `includes/OAuth/`.

This is the most complex module — it implements a full OAuth 2.0 authorization
code flow for the "Claude Connectors" integration, including custom response types,
an authorization controller, and persistent token storage.

### Files to migrate

| Old | New |
|---|---|
| `src/OAuth/ClaudeConnectors.php` | `includes/OAuth/ClaudeConnectors.php` |
| `src/OAuth/AuthorizationCodeResponseType.php` | `includes/OAuth/AuthorizationCodeResponseType.php` |
| `src/OAuth/AuthorizeController.php` | `includes/OAuth/AuthorizeController.php` |
| `src/OAuth/Storage.php` | `includes/OAuth/Storage.php` |

### Hook migration pattern

Old (hooks inside `ClaudeConnectors` constructor):
```php
add_action( 'rest_api_init', ... );
add_action( 'template_redirect', ... );
add_action( 'init', ... );
add_filter( 'query_vars', ... );
```

New (all in `includes/Main.php::define_admin_hooks()`):
```php
$connectors = new Includes\OAuth\ClaudeConnectors( new Includes\Database\MCPServer\Query() );
$this->loader->add_action( 'rest_api_init', $connectors, 'register_oauth_routes' );
$this->loader->add_action( 'init', $connectors, 'register_rewrite_rules' );
$this->loader->add_filter( 'query_vars', $connectors, 'add_query_vars' );
$this->loader->add_action( 'template_redirect', $connectors, 'handle_authorize_page' );
```

### Constants that move

The following constants are defined on `ClaudeConnectors`:
- `AUTHORIZE_PATH`
- `AUTHORIZE_QUERY_VAR`
- `AUTH_SERVER_QUERY_VAR`
- `RESOURCE_QUERY_VAR`

These remain as class constants — the activation hook in `Activator.php` references
them by `ClaudeConnectors::AUTHORIZE_PATH` etc. No change needed for the constants
themselves, just ensure the class is autoloaded before `Activator::activate()` runs.

---

## Spec-Kit Steps

### Step 1 — `/speckit.specify`

```
/speckit.specify

Feature: OAuth / Claude Connectors Integration
Feature number: 005

The plugin implements an OAuth 2.0 Authorization Code flow to allow Claude
(via the "Claude Connectors" product) to authenticate against the WordPress site
and obtain access to MCP servers.

Functional requirements:

1. OAuth Discovery endpoints (registered on rest_api_init):
   - GET /.well-known/oauth-authorization-server — returns server metadata JSON
   - GET /.well-known/oauth-protected-resource   — returns resource metadata JSON
   Both use custom query vars and template_redirect to serve the JSON output.

2. Authorization endpoint:
   - URL: /{AUTHORIZE_PATH}/  (e.g. /acrossai-mcp-oauth/)
   - Handles: display of consent form, user authentication, code generation
   - On approval: redirect to redirect_uri with authorization code
   - On denial: redirect with error parameter

3. Token endpoint (REST):
   - POST /wp-json/acrossai-mcp/v1/token
   - Exchanges authorization code for access token
   - permission_callback: always allow (public endpoint — auth is code-based)
   - Validates: code, client_id, client_secret, redirect_uri

4. Per-server OAuth credentials:
   - Each MCP server row stores: claude_connector_client_id,
     claude_connector_client_secret, claude_connector_redirect_uri
   - The authorization flow uses these server-specific credentials

5. Token storage:
   - OAuth\Storage persists authorization codes and access tokens in the DB
     (uses the CliAuthLog table for code storage)
   - Tokens are hashed before storage (SHA-256)

6. Security requirements:
   - Authorization codes expire in 10 minutes
   - Access tokens expire in 1 hour (configurable)
   - PKCE (code_challenge / code_verifier) must be supported
   - redirect_uri must exactly match the registered URI for the server

7. No direct add_action() in class constructors.
   All hooks wired via Loader in Main::define_admin_hooks().

8. Namespace: AcrossAI_MCP_Manager\Includes\OAuth
   Files: includes/OAuth/*.php
```

---

### Step 2 — `/speckit.clarify` _(optional)_

Run this only if the spec output is ambiguous or incomplete.

```
/speckit.clarify
```

---

### Step 3 — `/speckit.plan`

```
/speckit.plan

File placement:
  includes/OAuth/ClaudeConnectors.php       — main orchestrator
  includes/OAuth/AuthorizationCodeResponseType.php — OAuth2 response type
  includes/OAuth/AuthorizeController.php    — handles the consent form flow
  includes/OAuth/Storage.php                — DB persistence for codes/tokens

Namespace changes:
  Old: ACROSSAI_MCP_MANAGER\OAuth\*
  New: AcrossAI_MCP_Manager\Includes\OAuth\*

Constructor cleanup (ClaudeConnectors):
  Remove all add_action() / add_filter() calls.
  Add public methods for each hook target:
    register_oauth_routes()    — called on rest_api_init via Loader
    register_rewrite_rules()   — called on init via Loader
    add_query_vars()           — filter called on query_vars via Loader
    handle_authorize_page()    — called on template_redirect via Loader
    handle_discovery_pages()   — called on template_redirect via Loader

Dependency injection:
  ClaudeConnectors receives MCPServer\Query and OAuth\Storage as constructor args.
  AuthorizeController receives OAuth\Storage.
  Storage receives CliAuthLog\Query for persistence.

Wiring in Main::define_admin_hooks():
  $oauth_storage  = new Includes\OAuth\Storage( new Includes\Database\CliAuthLog\Query() );
  $connectors     = new Includes\OAuth\ClaudeConnectors(
      new Includes\Database\MCPServer\Query(),
      $oauth_storage
  );
  $this->loader->add_action( 'rest_api_init', $connectors, 'register_oauth_routes' );
  $this->loader->add_action( 'init', $connectors, 'register_rewrite_rules' );
  $this->loader->add_filter( 'query_vars', $connectors, 'add_query_vars' );
  $this->loader->add_action( 'template_redirect', $connectors, 'handle_authorize_page' );
  $this->loader->add_action( 'template_redirect', $connectors, 'handle_discovery_pages' );

Rewrite rules are registered in Activator::activate() using class constants:
  ClaudeConnectors::AUTHORIZE_PATH, AUTHORIZE_QUERY_VAR, etc.
  (These references remain correct — just class path changes.)
```

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

- [ ] Four OAuth files exist in `includes/OAuth/`
- [ ] No `add_action()` / `add_filter()` inside class constructors
- [ ] OAuth discovery endpoints return valid JSON (`/.well-known/oauth-authorization-server`)
- [ ] Authorization endpoint renders consent page for an authenticated user
- [ ] Token exchange returns `access_token` JSON on valid code
- [ ] Authorization codes expire correctly
- [ ] PKCE flow works end-to-end
- [ ] All REST routes have appropriate `permission_callback`
