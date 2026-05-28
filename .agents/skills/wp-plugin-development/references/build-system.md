# Build system: @wordpress/scripts + webpack

## Entry points

| Source | Output | Consumed by |
|---|---|---|
| `src/js/backend.js` | `build/js/backend.js` + `build/js/backend.asset.php` | `Admin\Main` |
| `src/scss/backend.scss` | `build/css/backend.css` + `build/css/backend.asset.php` | `Admin\Main` |
| `src/js/frontend.js` | `build/js/frontend.js` + `build/js/frontend.asset.php` | `Public\Main` |
| `src/scss/frontend.scss` | `build/css/frontend.css` + `build/css/frontend.asset.php` | `Public\Main` |
| `src/scss/blocks/core/*.scss` | `build/css/blocks/core/*.css` (globbed) | block editor |
| `src/blocks/**/block.json` | `build/blocks/**/index.js` + `view.js` (auto-discovered) | block registration |
| `src/media/**` | `build/media/**` (CopyPlugin) | static assets |
| `src/fonts/**` | `build/fonts/**` (CopyPlugin) | static assets |

`RemoveEmptyScriptsPlugin` removes orphaned `.js` stubs created from SCSS-only entries.

## *.asset.php manifests

Every JS and CSS output has a sibling `*.asset.php` that returns:

```php
return [ 'dependencies' => [...], 'version' => 'abc123' ];
```

Always read from the manifest — never hardcode a version string or dependency list:

```php
$asset = include WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/js/backend.asset.php';
wp_enqueue_script( $handle, $url, $asset['dependencies'], $asset['version'] );
```

## Adding a new JS / CSS file — complete workflow

Follow these four steps every time you add a new standalone JS or SCSS entry. Never edit
`build/` directly — only ever touch `src/`.

### Step 1 — Create the source file(s)

| Target | Create |
|---|---|
| JS only | `src/js/<name>.js` |
| CSS only | `src/scss/<name>.scss` |
| Both | both files above |

Example:

```js
// src/js/my-feature.js
import './my-feature.scss'; // optional: co-located SCSS import
console.log( 'my-feature loaded' );
```

```scss
// src/scss/my-feature.scss
.my-feature { color: red; }
```

> **Co-located import pattern:** If you `import` the SCSS inside the JS file, add only the
> JS entry to webpack; webpack will extract the CSS automatically.  
> **Standalone CSS pattern:** If the SCSS has no paired JS, add a CSS-only webpack entry and
> `RemoveEmptyScriptsPlugin` will drop the empty `.js` stub that webpack generates.

---

### Step 2 — Register the entry point in `webpack.config.js`

Add your new entry to the `entry:` object inside `module.exports`:

```diff
 module.exports = {
   ...defaultConfig,
   entry: {
     ...getWebpackEntryPoints(),
     ...blockStylesheets(),
     ...blockEntries,
     'js/frontend': path.resolve( process.cwd(), 'src/js', 'frontend.js' ),
     'js/backend':  path.resolve( process.cwd(), 'src/js', 'backend.js' ),
     'css/frontend': path.resolve( process.cwd(), 'src/scss', 'frontend.scss' ),
     'css/backend':  path.resolve( process.cwd(), 'src/scss', 'backend.scss' ),
+    // JS entry (also extracts co-located SCSS if imported inside the file)
+    'js/my-feature': path.resolve( process.cwd(), 'src/js', 'my-feature.js' ),
+    // CSS-only entry (no paired JS needed)
+    'css/my-feature': path.resolve( process.cwd(), 'src/scss', 'my-feature.scss' ),
   },
```

After `npm run build` this produces:
- `build/js/my-feature.js` + `build/js/my-feature.asset.php`
- `build/css/my-feature.css` + `build/css/my-feature.asset.php`

---

### Step 3 — Load the asset manifest in the PHP class constructor

#### Backend (admin) → `admin/Main.php`

```diff
 public function __construct( $plugin_name, $version ) {
     $this->plugin_name    = $plugin_name;
     $this->version        = $version;
     $this->js_asset_file  = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/js/backend.asset.php';
     $this->css_asset_file = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/css/backend.asset.php';
+    $this->my_feature_js_asset  = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/js/my-feature.asset.php';
+    $this->my_feature_css_asset = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/css/my-feature.asset.php';
 }
```

#### Frontend (public) → `public/Main.php`

```diff
 public function __construct( $plugin_name, $version ) {
     $this->plugin_name    = $plugin_name;
     $this->version        = $version;
     $this->js_asset_file  = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/js/frontend.asset.php';
     $this->css_asset_file = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/css/frontend.asset.php';
+    $this->my_feature_js_asset  = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/js/my-feature.asset.php';
+    $this->my_feature_css_asset = include \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_PATH . 'build/css/my-feature.asset.php';
 }
```

> **Rule:** Always `include` the `*.asset.php` manifest in the constructor — never hardcode a
> version string or dependency list. The manifest is the single source of truth for both.
>
> ⚠️ **PHP fatal if build not run:** The constructor `include`s the manifest unconditionally.
> If `build/` artifacts are absent (i.e., `npm run build` has not been run), WordPress will
> throw a **PHP fatal error on every page load**, not a soft asset miss. Always run
> `npm run build` before activating or testing the plugin.

---

### Step 4 — Enqueue the compiled output

#### Backend (admin) → `admin/Main.php::enqueue_styles()` / `enqueue_scripts()`

```diff
 public function enqueue_styles() {
     wp_enqueue_style(
         $this->plugin_name,
         \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_URL . 'build/css/backend.css',
         $this->css_asset_file['dependencies'],
         $this->css_asset_file['version'],
         'all'
     );
+    wp_enqueue_style(
+        $this->plugin_name . '-my-feature',
+        \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_URL . 'build/css/my-feature.css',
+        $this->my_feature_css_asset['dependencies'],
+        $this->my_feature_css_asset['version'],
+        'all'
+    );
 }

 public function enqueue_scripts() {
     wp_enqueue_script(
         $this->plugin_name,
         \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_URL . 'build/js/backend.js',
         $this->js_asset_file['dependencies'],
         $this->js_asset_file['version'],
         false
     );
+    wp_enqueue_script(
+        $this->plugin_name . '-my-feature',
+        \WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_URL . 'build/js/my-feature.js',
+        $this->my_feature_js_asset['dependencies'],
+        $this->my_feature_js_asset['version'],
+        true   // load in footer
+    );
 }
```

#### Frontend (public) → `public/Main.php::enqueue_styles()` / `enqueue_scripts()`

Identical pattern — replace `Admin\Main` path with `Public\Main` and swap `backend` for the
new handle name.

---

### Step 5 — Build and verify

```bash
npm run build
```

Confirm the following files exist in `build/`:

```
build/js/my-feature.js
build/js/my-feature.asset.php
build/css/my-feature.css
build/css/my-feature.asset.php
```

Then load the relevant WordPress admin page (backend) or a front-end page (public) and confirm
the handle appears in DevTools → Network with a cache-busting hash version.

## Passing PHP data to JS (wp_localize_script)

After enqueuing any script, call `wp_localize_script` in the **same hooked method** to attach
a PHP data object. This is the correct way to pass nonces, AJAX URLs, settings, and i18n strings.
Full examples for both admin and frontend contexts are in `references/admin.md` and
`references/public.md` respectively.

Quick reference:

```php
wp_localize_script(
    $handle,           // must match the handle used in wp_enqueue_script()
    'myPluginData',    // JS global object name (choose a unique, namespaced name)
    [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'my_plugin_action' ),
    ]
);
```

In JS, access it as `myPluginData.ajaxUrl`, `myPluginData.nonce`, etc.

> **Timing:** `wp_localize_script` must be called **after** `wp_enqueue_script` for the same
> handle. Both calls should be inside the same hooked method (e.g. `enqueue_scripts()`).



1. Create `src/blocks/<block-name>/block.json` + `index.js` (and optionally `view.js`).
2. Run `npm run build` — webpack discovers the block via `block.json` automatically.
3. No manual `webpack.config.js` edit needed for standard blocks.

## Composer / Mozart

`composer.json` includes `coenjacobs/mozart` to namespace-scope vendor libraries, preventing
conflicts with other plugins. After any `composer install` or vendor change:

```
composer dump-autoload
```

## npm scripts

| Script | Purpose |
|---|---|
| `npm run build` | Production build |
| `npm run start` | Watch mode |
| `npm run lint:js` | ESLint |
| `npm run lint:css` | Stylelint |
| `npm run format` | Prettier |
| `npm run packages-update` | Update @wordpress packages |
| `npm run plugin-zip` | Create distributable ZIP |
| `npm run env:start` | Start wp-env |
| `npm run env:stop` | Stop wp-env |
| `npm run env:reset` | Destroy and restart wp-env |
| `npm run skills:install` | Install agent skills (`scripts/install-agent-skills.sh`) |

- Upstream reference: `https://github.com/WPBoilerplate/wordpress-plugin-boilerplate/blob/main/webpack.config.js`
