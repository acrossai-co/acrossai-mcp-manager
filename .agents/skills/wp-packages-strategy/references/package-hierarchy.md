# Package Selection Hierarchy

When choosing a package for any feature in a WordPress plugin, apply this priority order.
The goal is to use the packages WordPress already ships — they are vetted, maintained, and
do not add to your bundle size because WordPress externalises them at runtime.

---

## Tiers

### Tier 1 — Always prefer: `@wordpress/*`

These packages are:
- Maintained by the WordPress core team
- Versioned alongside WordPress itself
- Declared as `externals` by `@wordpress/dependency-extraction-webpack-plugin`
- **Not bundled into your plugin** — WordPress loads them from `wp-includes/`

Use Tier 1 whenever a package exists. There is never a valid reason to duplicate them.

### Tier 2 — Consider: well-known npm packages (non-framework)

Use when no `@wordpress/*` equivalent exists and the package is:
- Widely used, well-maintained, and small in scope
- Not a full framework (React, Vue, Angular, etc.)
- Not something WordPress already ships

Examples: `dompurify`, `fuse.js`, `chart.js`, `marked`

Always confirm the package is not already available via `@wordpress/*` before adding it.

### Tier 3 — Avoid: duplicate frameworks

These duplicate what WordPress already provides. **Do not add them to your plugin bundle.**

| Package | Problem | Use instead |
|---|---|---|
| `react` | WordPress already ships React via `@wordpress/element` | `@wordpress/element` |
| `react-dom` | Bundled with `@wordpress/element` | `@wordpress/element` |
| `redux` | WordPress ships a compatible store via `@wordpress/data` | `@wordpress/data` |
| `react-redux` | `@wordpress/data` provides `useSelect` / `useDispatch` | `@wordpress/data` |
| `axios` | WordPress ships an authenticated fetch client | `@wordpress/api-fetch` |
| `moment` | WordPress ships date formatting | `@wordpress/date` |
| `lodash` | WordPress externalises lodash globally as `window._` | `lodash` (external, not bundled) |
| `jquery` | WordPress ships and externalises jQuery | Enqueue as a dependency via `wp_enqueue_script` |

---

## Full comparison table

| External package | Tier | `@wordpress` alternative | Notes |
|---|---|---|---|
| `react` | 3 — Avoid | `@wordpress/element` | JSX runtime, hooks, portals |
| `react-dom` | 3 — Avoid | `@wordpress/element` | `render`, `createRoot`, `createPortal` |
| `react-redux` | 3 — Avoid | `@wordpress/data` | `useSelect`, `useDispatch` |
| `redux` | 3 — Avoid | `@wordpress/data` | Store registry |
| `zustand` | 3 — Avoid | `@wordpress/data` | Lightweight store |
| `jotai` | 3 — Avoid | `@wordpress/data` | Atom-based state |
| `axios` | 3 — Avoid | `@wordpress/api-fetch` | Nonce middleware included |
| `node-fetch` / `cross-fetch` | 3 — Avoid | `@wordpress/api-fetch` | Unnecessary in browser context |
| `moment` | 3 — Avoid | `@wordpress/date` | Smaller, WP-aware |
| `date-fns` | 2 — Consider | `@wordpress/date` | Only if `@wordpress/date` doesn't cover the need |
| `lodash` | 1 — Use as external | `window._` (WP externalises it) | Add `lodash` to `wp_enqueue_script` deps, not your bundle |
| `classnames` / `clsx` | 2 — Consider | `@wordpress/classnames` | WP ships a thin wrapper |
| `dompurify` | 2 — Consider | `@wordpress/dom` for focus/DOM; `wp_kses` server-side | Use for client-side HTML sanitization only |
| `@emotion/react` | 3 — Avoid | `@wordpress/components` + CSS modules | WP ships a design system |
| `styled-components` | 3 — Avoid | `@wordpress/components` + CSS modules | WP ships a design system |
| `react-query` / `@tanstack/query` | 3 — Avoid | `@wordpress/core-data` + `@wordpress/data` | WP data layer handles caching |
| `i18next` / `react-i18next` | 3 — Avoid | `@wordpress/i18n` | WP i18n is the standard |
| `react-router` | 3 — Avoid | `@wordpress/router` (experimental) or hash-based routing | For admin pages; avoid full SPA routing |
| `formik` / `react-hook-form` | 2 — Consider | Settings API (PHP) or `@wordpress/components` Form | Use WP native forms where possible |
| `chart.js` / `recharts` | 2 — Consider | None | Acceptable if charts are core to the plugin |

---

## Decision flowchart

```
1. I need a new package.
   │
   ├─ Is it in the @wordpress/* namespace?
   │  └─ YES → Use it. Done.
   │
   ├─ Does WordPress ship/externalise it (react, lodash, jquery)?
   │  └─ YES → Use the @wordpress wrapper or declare it as a script dependency.
   │            Never bundle it.
   │
   ├─ Is there a well-known, small npm package with no WP equivalent?
   │  └─ YES → Tier 2. Add to package.json, bundle it, document why.
   │
   └─ Is it a framework or large runtime (another React, Vue, etc.)?
      └─ YES → Tier 3. Do not use. Find the @wordpress equivalent.
                If truly unavailable, open a GitHub discussion first.
```

---

## Why this matters

WordPress externalises packages via `wp_enqueue_script` dependency chains. When your plugin
declares `@wordpress/element` as a script dependency, WordPress loads its own copy of React
from `wp-includes/js/dist/element.min.js`. If you also bundle `react` inside your plugin's
JS file, the page now has **two copies of React** — they share no state, hooks break, and
the bundle is needlessly large.

The `@wordpress/dependency-extraction-webpack-plugin` (included in `@wordpress/scripts`)
handles this automatically: it marks `@wordpress/*`, `react`, `react-dom`, `lodash`, `jquery`,
and others as `externals`, ensuring they are never bundled. The generated `.asset.php` file
lists the WordPress script handles to pass to `wp_enqueue_script`.

---

## Upstream reference

`https://developer.wordpress.org/block-editor/reference-guides/packages/`
