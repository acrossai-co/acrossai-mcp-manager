# Phase 9 — Assets & Build Pipeline Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  assets/admin.css             → becomes src/scss/backend.scss
  assets/admin.js              → becomes src/js/backend.js
  assets/frontend-auth.css     → becomes src/scss/frontend.scss
  assets/frontend-oauth.css    → becomes src/scss/frontend-oauth.scss

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write into existing source files:
  src/scss/backend.scss
  src/js/backend.js
  src/scss/frontend.scss        ← create new
  src/scss/frontend-oauth.scss  ← create new

Extend (do NOT replace):
  webpack.config.js            ← add new SCSS entry points
  admin/Main.php               ← update enqueue to use build/*.asset.php
  public/Main.php              ← update enqueue to use build/*.asset.php
```

---

## What this phase covers

Migrates flat `assets/*.css` / `assets/*.js` files to the `@wordpress/scripts`
webpack build pipeline (`src/js/` + `src/scss/` → `build/`).

The new branch already has the webpack scaffold in place with `backend.js` and
`frontend.js`. This phase populates the source files and wires the enqueue logic
to use the generated `build/*.asset.php` manifests.

### Old assets (main branch `assets/`)

| File | Content | Migrates to |
|---|---|---|
| `assets/admin.css` | Admin UI styles | `src/scss/backend.scss` |
| `assets/admin.js` | Admin UI JS | `src/js/backend.js` |
| `assets/frontend-auth.css` | Frontend auth page styles | `src/scss/frontend.scss` |
| `assets/frontend-oauth.css` | OAuth consent page styles | `src/scss/frontend-oauth.scss` |

### Current state of new branch

`src/js/backend.js` and `src/js/frontend.js` already exist as empty entry points.
`webpack.config.js` and `package.json` are already configured.

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: Asset Build Pipeline — CSS + JS via @wordpress/scripts
Feature number: 009

All CSS and JS assets must go through the @wordpress/scripts webpack pipeline.
Source files live in src/js/ and src/scss/. Compiled output lands in build/.
Enqueue logic always reads version and dependency arrays from build/*.asset.php.

Asset inventory:

1. backend (admin UI):
   - Source: src/js/backend.js + src/scss/backend.scss
   - Output: build/js/backend.js + build/css/backend.css
   - Enqueued: admin_enqueue_scripts, only on plugin admin pages
   - Handle: acrossai-mcp-backend-js, acrossai-mcp-backend-css

2. frontend (CLI auth page):
   - Source: src/js/frontend.js + src/scss/frontend.scss
   - Output: build/js/frontend.js + build/css/frontend.css
   - Enqueued: wp_enqueue_scripts, only on the FrontendAuth virtual page
   - Handle: acrossai-mcp-frontend-js, acrossai-mcp-frontend-css

3. frontend-oauth (OAuth consent page):
   - Source: src/scss/frontend-oauth.scss
   - Output: build/css/frontend-oauth.css
   - Enqueued: wp_enqueue_scripts, only on the OAuth authorize page
   - Handle: acrossai-mcp-frontend-oauth-css

Functional requirements:
1. webpack.config.js entry map covers all three entry points.
2. Each PHP enqueue reads version + deps from the corresponding build/*.asset.php.
3. No version strings or dependency arrays are hardcoded in PHP.
4. Assets are never enqueued globally — always guarded by screen or query var check.
5. npm run build must complete with zero errors.
6. npm run start (watch mode) works for development.
```

### Step 2: `/speckit.plan`

```
/speckit.plan

Step 1 — webpack.config.js update:
Add frontend-oauth SCSS entry:
  entry: {
    'js/backend':          './src/js/backend.js',
    'js/frontend':         './src/js/frontend.js',
    'css/backend':         './src/scss/backend.scss',
    'css/frontend':        './src/scss/frontend.scss',
    'css/frontend-oauth':  './src/scss/frontend-oauth.scss',
  }

Step 2 — Source files:
  src/scss/backend.scss       ← copy content from assets/admin.css
  src/js/backend.js           ← copy content from assets/admin.js
  src/scss/frontend.scss      ← copy content from assets/frontend-auth.css
  src/scss/frontend-oauth.scss ← copy content from assets/frontend-oauth.css

Step 3 — Enqueue in Admin\Main::enqueue_styles() / enqueue_scripts():
  $screen = get_current_screen();
  if ( ! $screen || false === strpos( $screen->id, 'acrossai_mcp' ) ) { return; }

  $js_asset = include ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/backend.asset.php';
  wp_enqueue_script( 'acrossai-mcp-backend-js',
      ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/backend.js',
      $js_asset['dependencies'], $js_asset['version'], true );

  $css_asset = include ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/css/backend.asset.php';
  wp_enqueue_style( 'acrossai-mcp-backend-css',
      ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/css/backend.css',
      $css_asset['dependencies'], $css_asset['version'] );

Step 4 — Enqueue in Public\Main::enqueue_styles() / enqueue_scripts():
  Guard: if ( ! get_query_var( FrontendAuth::QUERY_VAR ) && ! get_query_var( ClaudeConnectors::AUTHORIZE_QUERY_VAR ) ) { return; }
  Load frontend.asset.php and frontend-oauth.asset.php as needed.

Step 5 — Run npm run build and confirm all four build files are produced:
  build/js/backend.js, build/js/backend.asset.php
  build/js/frontend.js, build/js/frontend.asset.php
  build/css/backend.css, build/css/backend.asset.php
  build/css/frontend.css, build/css/frontend.asset.php
  build/css/frontend-oauth.css, build/css/frontend-oauth.asset.php

Step 6 — Delete old assets/ directory (only after npm run build passes).
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] `npm run build` exits 0
- [ ] `build/js/backend.asset.php` and `build/css/backend.asset.php` exist
- [ ] `build/js/frontend.asset.php` and `build/css/frontend.asset.php` exist
- [ ] `build/css/frontend-oauth.asset.php` exists
- [ ] Admin styles enqueue only on `acrossai_mcp_manager` screen — not globally
- [ ] Frontend auth CSS enqueues only on `/{PAGE_SLUG}/` — not on all pages
- [ ] No hardcoded version strings or dependency arrays in enqueue PHP
- [ ] Old `assets/` directory removed
- [ ] Plugin activates with no PHP fatal errors (no missing asset.php file includes)
