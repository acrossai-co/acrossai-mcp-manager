---
name: wp-packages-strategy
description: "Prioritize official @wordpress packages over external dependencies. Detect React conflicts, use aliasing, and validate package usage."
compatibility: "WordPress 6.0+, PHP 7.4+, @wordpress/scripts build pipeline"
---

# WP Packages Strategy

## When to use

Use this skill whenever:

- Building React components inside a WordPress plugin (including Elementor widgets)
- Creating interactive Gutenberg blocks or admin features
- Integrating a third-party library that ships its own copy of React or ReactDOM
- Seeing duplicate React warnings or bloated bundle sizes
- Auditing an existing plugin for package conflicts
- Any project that uses both external npm packages and `@wordpress/*` packages

## Inputs required

- Plugin root path (where `package.json` lives).
- Build system in use (`@wordpress/scripts`, custom webpack, Vite, etc.).
- Target framework context: Gutenberg block, Elementor widget, plain admin React.
- Contents of `package.json` → `dependencies` and `devDependencies`.
- Whether `node_modules/` is present (needed for duplicate-version detection).

## Pre-implementation checklist

Before writing any interactive UI code:

- [ ] Check `references/wordpress-packages.md` — does an official `@wordpress/*` package already cover the need?
- [ ] Confirm `@wordpress/element` is used instead of importing `react` directly.
- [ ] Confirm `@wordpress/components` is used for UI primitives before pulling in a UI library.
- [ ] Confirm `@wordpress/data` / `@wordpress/store` is used for state instead of Redux/Zustand.
- [ ] Confirm `@wordpress/api-fetch` is used for REST calls instead of axios/fetch wrappers.
- [ ] Confirm `webpack.config.js` aliases `react` → `@wordpress/element` if third-party libs are present.
- [ ] Validation script passes: `node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.`

## Procedure

### Step 1) Check the official @wordpress packages list first

Before reaching for any external package, consult `references/wordpress-packages.md`.
WordPress ships ~70 packages as part of the block editor. Most common needs are covered:

| Need | Use this |
|---|---|
| React / JSX runtime | `@wordpress/element` |
| UI components | `@wordpress/components` |
| State management | `@wordpress/data` |
| REST API calls | `@wordpress/api-fetch` |
| Utility functions | `@wordpress/compose`, `@wordpress/hooks` |
| Icons | `@wordpress/icons` |
| Notices | `@wordpress/notices` |

See: `references/wordpress-packages.md`

### Step 2) Detect current React usage

Scan for direct React imports in the project:

```bash
node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.
```

Or manually:

```bash
grep -r "from 'react'" src/ --include="*.js" --include="*.jsx" --include="*.ts" --include="*.tsx"
grep -r "from \"react\"" src/ --include="*.js" --include="*.jsx" --include="*.ts" --include="*.tsx"
grep -r "require('react')" src/ --include="*.js"
```

### Step 3) Map conflicts with @wordpress alternatives

For every direct React import found, apply the replacement:

| Found | Replace with |
|---|---|
| `import React from 'react'` | `import { createElement } from '@wordpress/element'` |
| `import { useState } from 'react'` | `import { useState } from '@wordpress/element'` |
| `import { useEffect } from 'react'` | `import { useEffect } from '@wordpress/element'` |
| `import ReactDOM from 'react-dom'` | `import { render, unmountComponentAtNode } from '@wordpress/element'` |
| `import { createRoot } from 'react-dom/client'` | `import { createRoot } from '@wordpress/element'` |
| `import { createPortal } from 'react-dom'` | `import { createPortal } from '@wordpress/element'` |

See: `references/wordpress-packages.md` for the full replacement map.

### Step 4) Configure webpack aliases (if third-party libs ship React)

If a third-party library (e.g. an Elementor React UI kit) imports `react` internally and
you cannot change its source, add webpack aliases so it resolves to the WordPress copy:

```javascript
// webpack.config.js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  resolve: {
    ...defaultConfig.resolve,
    alias: {
      ...defaultConfig.resolve?.alias,
      'react':         path.resolve('./node_modules/@wordpress/element'),
      'react-dom':     path.resolve('./node_modules/@wordpress/element'),
      'react-dom/client': path.resolve('./node_modules/@wordpress/element'),
    },
  },
};
```

See: `references/webpack-aliasing.md`

### Step 5) Update imports to use @wordpress packages

Apply replacements across the codebase. For a plugin with a `src/` directory:

```bash
# Preview changes (macOS/Linux)
grep -rn "from 'react'" src/
grep -rn "from 'react-dom'" src/

# Replace (example using sed on macOS)
find src -name "*.js" -o -name "*.jsx" | xargs sed -i '' \
  "s/import React from 'react'/import { createElement } from '@wordpress\/element'/g"
```

Always verify the build still passes after replacements.

### Step 6) Run the validation script

```bash
node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.
```

The script checks:
- Direct `react` / `react-dom` imports in JS/JSX/TS/TSX source files
- Whether `@wordpress/element` is present in `package.json`
- Duplicate React versions in `node_modules/` (if present)

Exit 0 = clean. Exit 1 = conflicts found with a remediation list.

### Step 7) Test and verify no conflicts

After changes:

1. `npm run build` — must complete with no errors.
2. Open the browser console — no "Cannot use two copies of React" warnings.
3. Webpack bundle analysis (optional): `npx webpack-bundle-analyzer build/stats.json`
   — confirm `react` is not in the bundle (only `@wordpress/element` should appear).
4. Functional test — all React components render correctly on-screen.

## Verification

- `node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.` exits 0.
- `npm run build` succeeds with no duplicate-React warnings.
- `grep -r "from 'react'" src/` returns no results.
- Bundle does not contain a standalone `react` chunk.
- All interactive UI works correctly in the browser.

## Failure modes / debugging

- **"Cannot use two copies of React"** — a third-party lib is importing its own `react`. Add webpack aliases (Step 4) so all imports resolve to `@wordpress/element`.
- **Duplicate React in bundle** — webpack is not deduplicating. Check that aliases point to the resolved path (`path.resolve(...)`) not just a package name string.
- **`@wordpress/element` missing from `node_modules/`** — run `npm install`; ensure `@wordpress/scripts` or `@wordpress/element` is in `devDependencies`.
- **Alias not working** — confirm `webpack.config.js` spreads `defaultConfig.resolve.alias` before adding new entries; missing the spread drops built-in WP aliases.
- **Build passes but components blank** — hooks rules violated (e.g. hooks called conditionally) or component returned `null` due to missing data. Check browser console errors.
- **Version mismatch warnings** — `@wordpress/element` version in `package.json` must match the WordPress version running the site. Use `@wordpress/dependency-extraction-webpack-plugin` and `wp_enqueue_script` with the generated `.asset.php` manifest to let WordPress supply the correct version at runtime instead of bundling.

See: `references/webpack-aliasing.md`, `references/package-hierarchy.md`

## Escalation

- WPBoilerplate `agents.md`: `https://github.com/WPBoilerplate/wordpress-plugin-boilerplate/blob/main/agents.md`
- Official `@wordpress` packages list: `https://developer.wordpress.org/block-editor/reference-guides/packages/`
- `@wordpress/scripts` docs: `https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/`
- `@wordpress/element` docs: `https://developer.wordpress.org/block-editor/reference-guides/packages/packages-element/`
