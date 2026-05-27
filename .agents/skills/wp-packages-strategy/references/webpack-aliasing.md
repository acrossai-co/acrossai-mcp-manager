# Webpack Aliasing for WordPress Package Strategy

## What is webpack aliasing?

A webpack alias tells the bundler: "whenever any file imports module X, resolve it to Y
instead." This lets you redirect `import React from 'react'` to `@wordpress/element`
without modifying the source file — useful when the source is in a third-party library
you do not control.

---

## Why alias React to @wordpress/element?

`@wordpress/element` is a thin wrapper around React that WordPress ships as part of the
block editor. When your plugin's build uses `@wordpress/dependency-extraction-webpack-plugin`
(included in `@wordpress/scripts`), React is declared as an `external` and never bundled.

A third-party library (e.g. an Elementor React component kit) that `import React from 'react'`
internally will bypass the external — webpack will bundle its own copy of React. The page
then has two copies of React in memory, causing:

- "Invalid hook call" errors (hooks can only call into one React instance)
- "Cannot use two copies of React" console warnings
- Bundle size bloat (React is ~130 KB minified)

Adding an alias forces the third-party library to resolve `react` to the same
`@wordpress/element` module that webpack externalises, eliminating the duplicate.

---

## Webpack configuration

### With @wordpress/scripts (recommended)

Spread the default config, then extend `resolve.alias`:

```javascript
// webpack.config.js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  resolve: {
    ...defaultConfig.resolve,
    alias: {
      // Preserve any aliases already set by @wordpress/scripts
      ...defaultConfig.resolve?.alias,

      // Redirect React to @wordpress/element
      'react':              path.resolve(__dirname, 'node_modules/@wordpress/element'),
      'react-dom':          path.resolve(__dirname, 'node_modules/@wordpress/element'),
      'react-dom/client':   path.resolve(__dirname, 'node_modules/@wordpress/element'),
      'react-dom/server':   path.resolve(__dirname, 'node_modules/@wordpress/element'),
    },
  },
};
```

> **Important**: Use `path.resolve(__dirname, 'node_modules/...')` not just the package
> name. A bare string alias like `'react': '@wordpress/element'` still goes through module
> resolution and can fail in monorepos or when hoisting is inconsistent.

### Without @wordpress/scripts (standalone webpack)

```javascript
// webpack.config.js
const path = require('path');

module.exports = {
  // ... your existing config
  resolve: {
    alias: {
      'react':            path.resolve(__dirname, 'node_modules/@wordpress/element'),
      'react-dom':        path.resolve(__dirname, 'node_modules/@wordpress/element'),
      'react-dom/client': path.resolve(__dirname, 'node_modules/@wordpress/element'),
    },
  },
  externals: {
    // Also externalise so @wordpress/element itself is not bundled
    '@wordpress/element': ['wp', 'element'],
  },
};
```

---

## When to use aliasing vs. changing imports

| Situation | Approach |
|---|---|
| Your own source files import `react` | Change the imports to `@wordpress/element` directly |
| A third-party npm package imports `react` internally | Use webpack alias — you cannot change its source |
| Both your code and a third-party lib import `react` | Change your imports AND add the alias |
| You need a quick fix during migration | Alias first, then migrate imports, then remove alias |

Prefer changing your own imports over relying on aliases — aliases are invisible and can
confuse future maintainers. Keep an alias only when the third-party lib is the source of
the duplicate.

---

## Verifying the alias works

After adding the alias, run a build and check the output:

```bash
npm run build -- --devtool source-map

# Then analyse the bundle (install webpack-bundle-analyzer globally or as a devDependency)
npx webpack-bundle-analyzer build/index.js.map
```

In the treemap, you should see `@wordpress/element` referenced but not a standalone `react`
chunk. If `react` still appears as its own node, the alias is not resolving correctly —
check the `path.resolve` path.

Alternatively, inspect the compiled bundle text:

```bash
grep -c '"react"' build/index.js
# Should return 0 (no "react" module ID in the bundle)
```

---

## Common pitfalls

- **Forgetting to spread `defaultConfig.resolve.alias`** — `@wordpress/scripts` already
  sets aliases for its own packages. If you replace the whole `alias` object you lose them.

- **Using a bare string instead of `path.resolve`** — `'react': '@wordpress/element'`
  works in simple setups but breaks in workspaces or when `node_modules` hoisting puts
  the package at an unexpected depth.

- **Aliasing `react-dom` but not `react-dom/client`** — React 18 imports come from
  `react-dom/client`. Alias both.

- **Alias present but `@wordpress/element` not installed** — if `node_modules/@wordpress/element`
  doesn't exist, the alias resolves to a missing module and the build fails. Run
  `npm install` to ensure it is present.

- **`externals` not set after aliasing** — the alias alone does not externalise the
  module; webpack will bundle `@wordpress/element` into your output. You still need the
  `externals` config (or `@wordpress/dependency-extraction-webpack-plugin`) to keep it
  out of the bundle.

---

## Upstream reference

`https://webpack.js.org/configuration/resolve/#resolvealias`
