# Phase 7 — Frontend Auth Page Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/Frontend/FrontendAuth.php
  assets/frontend-auth.css     ← styles that will move to src/scss/frontend.scss in Phase 9

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new file:
  public/Partials/FrontendAuth.php

Extend (do NOT replace):
  public/Main.php              ← wire wp_enqueue_scripts via Loader
  includes/Main.php            ← wire init, template_redirect, query_vars via Loader
  includes/Activator.php       ← register rewrite rule for PAGE_SLUG
```

---

## What this phase covers

Migrates `src/Frontend/FrontendAuth.php` to `public/Partials/FrontendAuth.php`.

The Frontend Auth page is a WordPress "virtual page" rendered via a custom
rewrite rule. It presents a browser-based CLI authentication flow so users can
authorize AI clients (Claude Code, Cursor, etc.) without going through the admin.

### Old code

`src/Frontend/FrontendAuth.php` — namespace `ACROSSAI_MCP_MANAGER\Frontend`

### Target

`public/Partials/FrontendAuth.php` — namespace `AcrossAI_MCP_Manager\Public\Partials`

### Hook migration

Old (hooks in constructor):
```php
add_action( 'init', array( $this, 'register_rewrite_rule' ) );
add_filter( 'query_vars', array( $this, 'add_query_var' ) );
add_action( 'template_redirect', array( $this, 'maybe_render_page' ) );
add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
```

New (in `includes/Main.php::define_public_hooks()`):
```php
$frontend_auth = new Public\Partials\FrontendAuth();
$this->loader->add_action( 'init', $frontend_auth, 'register_rewrite_rule' );
$this->loader->add_filter( 'query_vars', $frontend_auth, 'add_query_var' );
$this->loader->add_action( 'template_redirect', $frontend_auth, 'maybe_render_page' );
$this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );
```

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: Frontend CLI Authentication Page
Feature number: 007

The Frontend Auth page provides a browser-based OAuth authorization flow for
AI client tools (Claude Code, Cursor, etc.) to obtain authenticated access to
the MCP server without using wp-admin credentials.

Functional requirements:

1. Virtual page URL: /{PAGE_SLUG}/ (constant: FrontendAuth::PAGE_SLUG)
   Registered as a WordPress rewrite rule that maps to a custom query var.
   Activation hook registers the rule and flushes rewrite rules.

2. Page rendering (template_redirect):
   When the custom query var is present, bypass normal WordPress template
   loading and render a custom HTML page that:
   - Shows the plugin branding
   - Presents an "Authorize" button
   - On authorization: generates a temporary auth code and displays it
     (or redirects to the client redirect_uri with the code)
   - Requires the user to be logged in (redirect to wp-login.php if not)

3. Asset enqueue (wp_enqueue_scripts):
   - Only enqueue on the FrontendAuth page (guard with the custom query var)
   - CSS: src/scss/frontend.scss → build/css/frontend.css
   - Read version + deps from build/css/frontend.asset.php
   - Never enqueue globally

4. Security:
   - Page requires the user to be logged in
   - CSRF: the authorize action uses a WordPress nonce
   - No raw $_GET / $_POST — use wp_unslash() + sanitize_*()

5. Constants (class-level, not PHP define()):
   - PAGE_SLUG — the URL slug for the virtual page
   - QUERY_VAR — the WordPress query variable name

6. No direct add_action() in constructor.
   All hooks wired via Loader in Main::define_public_hooks().

7. Namespace: AcrossAI_MCP_Manager\Public\Partials
   File: public/Partials/FrontendAuth.php
```

### Step 2: `/speckit.plan`

```
/speckit.plan

File: public/Partials/FrontendAuth.php
Namespace: AcrossAI_MCP_Manager\Public\Partials

Constructor:
  Remove all add_action() / add_filter() calls.
  Accept CliAuthLog\Query as injected dependency for code generation/storage.

Public methods (called by Loader):
  register_rewrite_rule()   — on init
  add_query_var()           — filter on query_vars, appends QUERY_VAR
  maybe_render_page()       — on template_redirect, checks query var, renders
  enqueue_assets()          — on wp_enqueue_scripts, guarded by query var

Asset enqueue guard:
  if ( ! get_query_var( self::QUERY_VAR ) ) { return; }
  $asset = include ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/css/frontend.asset.php';
  wp_enqueue_style( 'acrossai-mcp-frontend', ..., $asset['version'] );

Wiring in Main::define_public_hooks():
  $frontend_auth = new Public\Partials\FrontendAuth(
      new Includes\Database\CliAuthLog\Query()
  );
  $this->loader->add_action( 'init', $frontend_auth, 'register_rewrite_rule' );
  $this->loader->add_filter( 'query_vars', $frontend_auth, 'add_query_var' );
  $this->loader->add_action( 'template_redirect', $frontend_auth, 'maybe_render_page' );
  $this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );

Rewrite rule in Activator::activate() (already in Phase 2):
  add_rewrite_rule( '^' . FrontendAuth::PAGE_SLUG . '/?$', 'index.php?' . FrontendAuth::QUERY_VAR . '=1', 'top' );
  flush_rewrite_rules();
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] `public/Partials/FrontendAuth.php` exists with updated namespace
- [ ] No `add_action()` / `add_filter()` in class constructor
- [ ] Virtual page renders at `/{PAGE_SLUG}/` URL
- [ ] Page redirects to login for unauthenticated users
- [ ] CSS enqueues only on the frontend auth page — not on all pages
- [ ] Auth code generation includes nonce verification
