# Official WordPress Packages Reference

WordPress ships ~70 packages as part of the block editor infrastructure. These are
globally available in any WordPress page that loads the block editor, and are available
as `externals` via `@wordpress/dependency-extraction-webpack-plugin`. Never bundle them —
reference them and let WordPress supply the correct version at runtime.

## Core packages to know

### UI and rendering

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/element` | React wrapper — `createElement`, hooks, portals, `render` | `react`, `react-dom` |
| `@wordpress/components` | Design-system UI primitives (Button, Modal, Notice, etc.) | Any UI component library |
| `@wordpress/icons` | Official icon set (SVG-based) | `react-icons`, Font Awesome |
| `@wordpress/primitives` | Low-level SVG/HTML primitives used by `@wordpress/components` | — |

### Data and state

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/data` | Global store registry and `useSelect` / `useDispatch` hooks | Redux, Zustand, Jotai |
| `@wordpress/store` | Store type definitions (used with `@wordpress/data`) | — |
| `@wordpress/core-data` | Typed stores for posts, users, taxonomies, settings | Custom REST fetch + state |
| `@wordpress/preferences` | Persistent user preferences store | localStorage wrappers |

### API and HTTP

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/api-fetch` | Authenticated REST API client with nonce middleware | `axios`, `fetch` wrappers |
| `@wordpress/url` | URL building, query-string manipulation | `qs`, `URLSearchParams` wrappers |

### Blocks and editor

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/blocks` | `registerBlockType`, block validation, transforms | — |
| `@wordpress/block-editor` | Block editor hooks, `InspectorControls`, `RichText` | — |
| `@wordpress/editor` | Post editor context and utilities | — |
| `@wordpress/block-library` | Core block registration | — |
| `@wordpress/patterns` | Block patterns API | — |

### Utilities

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/hooks` | WordPress filter/action system in JS | Custom event emitters |
| `@wordpress/compose` | Higher-order components (`withState`, `useDebounce`, etc.) | `lodash/fp`, custom HOCs |
| `@wordpress/keycodes` | Keyboard shortcut constants | Magic number keycodes |
| `@wordpress/dom` | Safe DOM utilities, focus management | jQuery DOM helpers |
| `@wordpress/dom-ready` | DOM-ready callback (replaces `$(document).ready`) | jQuery ready |
| `@wordpress/date` | Moment.js-compatible date formatting | `moment`, `date-fns` |
| `@wordpress/i18n` | `__()`, `_n()`, `sprintf()` for JS strings | Custom i18n solutions |
| `@wordpress/html-entities` | HTML entity encode/decode | `he`, custom regex |
| `@wordpress/escape-html` | HTML escaping utilities | Custom escape functions |
| `@wordpress/is-shallow-equal` | Shallow equality check | `lodash.isequal` |

### Developer tooling

| Package | Purpose |
|---|---|
| `@wordpress/scripts` | Webpack/Babel/ESLint/Jest config — the build pipeline |
| `@wordpress/env` | Local WordPress environment via Docker |
| `@wordpress/jest-preset-default` | Jest config for WP projects |
| `@wordpress/eslint-plugin` | ESLint rules for WordPress coding standards |
| `@wordpress/dependency-extraction-webpack-plugin` | Generates `.asset.php` manifests |

### Notices and feedback

| Package | Purpose | Replaces |
|---|---|---|
| `@wordpress/notices` | Global notices store (success/error/info) | Custom toast libraries |
| `@wordpress/a11y` | Screen-reader announcements | Custom ARIA live regions |

---

## Import replacement map

Use this table when converting direct React imports:

| ❌ Remove | ✅ Replace with |
|---|---|
| `import React from 'react'` | `import { createElement } from '@wordpress/element'` |
| `import { useState, useEffect } from 'react'` | `import { useState, useEffect } from '@wordpress/element'` |
| `import { useRef, useCallback, useMemo } from 'react'` | `import { useRef, useCallback, useMemo } from '@wordpress/element'` |
| `import { createContext, useContext } from 'react'` | `import { createContext, useContext } from '@wordpress/element'` |
| `import { forwardRef, memo } from 'react'` | `import { forwardRef, memo } from '@wordpress/element'` |
| `import ReactDOM from 'react-dom'` | `import { render, unmountComponentAtNode } from '@wordpress/element'` |
| `import { createRoot } from 'react-dom/client'` | `import { createRoot } from '@wordpress/element'` |
| `import { createPortal } from 'react-dom'` | `import { createPortal } from '@wordpress/element'` |
| `import { useDispatch } from 'react-redux'` | `import { useDispatch } from '@wordpress/data'` |
| `import { useSelector } from 'react-redux'` | `import { useSelect } from '@wordpress/data'` |
| `import axios from 'axios'` | `import apiFetch from '@wordpress/api-fetch'` |

---

## Decision tree

```
Do I need React / JSX?
│
└─► Is it covered by @wordpress/element?
    │
    ├─ YES → import from @wordpress/element ✅
    │
    └─ NO (genuinely missing API)
        │
        └─► Am I in a WordPress context at all?
            │
            ├─ YES → File a ticket / open a PR to @wordpress packages
            │        Temporarily alias via webpack (see webpack-aliasing.md)
            │
            └─ NO → Document the justification; use external React
                     Add webpack alias anyway to avoid duplication
```

---

## JSX without importing React

With `@wordpress/scripts` (Babel + automatic JSX runtime), JSX transforms automatically
to `@wordpress/element` calls. You do **not** need `import React from 'react'` at the top
of every component file — the transform handles it.

```jsx
// ✅ Correct — no React import needed
const MyComponent = () => <div className="my-component">Hello</div>;
```

```jsx
// ❌ Wrong — importing React directly
import React from 'react';
const MyComponent = () => <div className="my-component">Hello</div>;
```

The `@wordpress/scripts` Babel config sets `runtime: 'automatic'` and points the JSX
import source to `@wordpress/element`.

---

## Upstream reference

`https://developer.wordpress.org/block-editor/reference-guides/packages/`
