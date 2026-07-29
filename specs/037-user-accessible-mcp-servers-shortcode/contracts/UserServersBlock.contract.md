# Contract — `UserServersBlock`

**File**: `public/Renderers/UserServers/UserServersBlock.php`
**Namespace**: `AcrossAI_MCP_Manager\Public\Renderers\UserServers`
**Stability**: `@experimental May change without notice before 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API
**Kind**: `final class` per D36 + singleton per A2 + S6 — concrete shortcode child

---

## Class shape

```php
namespace AcrossAI_MCP_Manager\Public\Renderers\UserServers;

/**
 * @experimental May change without notice before 1.0.0.
 */
final class UserServersBlock extends AbstractUserServersRenderer {

    /** @var self|null */
    protected static $_instance = null;

    /**
     * Per-request flag — true after the inline <style> block has been emitted
     * once during this request. Ensures FR-016 (style emitted exactly once).
     *
     * @var bool
     */
    private static $style_emitted = false;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Register the [acrossai_mcp_servers] shortcode. Called from
     * Main::define_public_hooks() on the `init` action (per A1).
     */
    public function register_shortcode(): void {
        add_shortcode( 'acrossai_mcp_servers', [ $this, 'render_shortcode' ] );
    }

    /**
     * Shortcode callback.
     *
     * @param array<string, mixed>|string $atts_raw Shortcode atts as passed by WP.
     * @return string HTML output (already escaped) or '' for anonymous.
     */
    public function render_shortcode( $atts_raw ): string {
        // See "Algorithm" below.
    }
}
```

---

## Shortcode attribute contract

`shortcode_atts()` normalizes to exactly this shape:

| Attribute | Default | Type | Purpose |
|-----------|---------|------|---------|
| `heading` | `''` (empty string — no header rendered) | string | Optional `<h2>` above the list. Rendered only when non-empty. |
| `show_description` | `'1'` | string (`'1'` or `'0'`) | Whether to render each server's `description` field. Any value other than `'1'` treated as false. |
| `empty_message` | `__( 'You do not have access to any MCP server yet.', 'acrossai-mcp-manager' )` | string | Rendered inside the empty-state wrapper when the logged-in user has zero accessible servers. |

All three attributes are safe strings — no HTML permitted; escaped via `esc_html` at render.

---

## Algorithm (required order of operations)

1. **Normalize attributes**
   ```php
   $atts = shortcode_atts( [
       'heading'          => '',
       'show_description' => '1',
       'empty_message'    => __( 'You do not have access to any MCP server yet.', 'acrossai-mcp-manager' ),
   ], is_array( $atts_raw ) ? $atts_raw : [], 'acrossai_mcp_servers' );
   ```

2. **Anonymous short-circuit**
   ```php
   if ( 0 === get_current_user_id() ) {
       return '';
   }
   ```

3. **Fetch data**
   ```php
   $data = $this->get_accessible_servers();
   ```
   `$data` is guaranteed to be an array (parent contract). Defensively coerce a non-array return from the filter — `if ( ! is_array( $data ) ) $data = [];` — before building HTML.

4. **Emit `<style>` block (once per request)**
   - If `false === self::$style_emitted`, prepend the inline `<style>…</style>` block to `$html`, set `self::$style_emitted = true`.
   - CSS content: scoped to `acrossai-mcp-servers` prefix. See "CSS scope" section below.

5. **Build HTML**
   - If `empty( $data )`: emit
     ```html
     <div class="acrossai-mcp-servers acrossai-mcp-servers--empty">
         <p><?php echo esc_html( $atts['empty_message'] ); ?></p>
     </div>
     ```
   - Otherwise: emit the DOM shape from FR-013 (see "DOM shape" section below).

6. **Fire filter**
   ```php
   $html = (string) apply_filters( 'acrossai_mcp_servers_shortcode_html', $html, $data, $atts );
   ```

7. **Return `$html`**.

---

## DOM shape (FR-013)

```html
<style>/* … scoped inline CSS … */</style>  <!-- first render only -->
<div class="acrossai-mcp-servers">
    <!-- optional heading -->
    <h2 class="acrossai-mcp-servers__heading"><?php echo esc_html( $atts['heading'] ); ?></h2>

    <ul class="acrossai-mcp-servers__list">
        <?php foreach ( $data as $server ): ?>
        <li class="acrossai-mcp-servers__server"
            data-server-id="<?php echo esc_attr( (string) $server['server_id'] ); ?>"
            data-server-slug="<?php echo esc_attr( $server['server_slug'] ); ?>">

            <h3 class="acrossai-mcp-servers__server-name">
                <?php echo esc_html( $server['server_name'] ); ?>
            </h3>

            <?php if ( '1' === $atts['show_description'] && '' !== $server['description'] ): ?>
            <p class="acrossai-mcp-servers__server-desc">
                <?php echo esc_html( $server['description'] ); ?>
            </p>
            <?php endif; ?>

            <div class="acrossai-mcp-servers__transports">
                <?php foreach ( $server['transports'] as $transport ): ?>
                <section class="acrossai-mcp-servers__transport"
                         data-key="<?php echo esc_attr( $transport['key'] ); ?>">
                    <h4 class="acrossai-mcp-servers__transport-label">
                        <?php echo esc_html( $transport['label'] ); ?>
                    </h4>
                    <ul class="acrossai-mcp-servers__dtos">
                        <?php foreach ( $transport['dtos'] as $dto ): ?>
                        <li class="acrossai-mcp-servers__dto"
                            data-slug="<?php echo esc_attr( $dto['slug'] ); ?>">
                            <?php /* icon: URL → <img>, else esc_html text */ ?>
                            <?php if ( self::icon_is_url( $dto['icon'] ) ): ?>
                            <img class="acrossai-mcp-servers__icon"
                                 src="<?php echo esc_url( $dto['icon'] ); ?>"
                                 alt="">
                            <?php else: ?>
                            <span class="acrossai-mcp-servers__icon">
                                <?php echo esc_html( $dto['icon'] ); ?>
                            </span>
                            <?php endif; ?>
                            <span class="acrossai-mcp-servers__name">
                                <?php echo esc_html( $dto['name'] ); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endforeach; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
```

### Icon URL detection

```php
private static function icon_is_url( string $icon ): bool {
    return 0 === strpos( $icon, 'http://' )
        || 0 === strpos( $icon, 'https://' );
}
```

Anything not matching `http://` or `https://` — including emoji, empty string, `data:` URIs, and relative paths — renders as `esc_html` text.

---

## CSS scope (inline `<style>` block content)

All selectors prefixed with `.acrossai-mcp-servers`. Rules cover only layout — no colors, no fonts, no theme opinions. Approximate shape:

```css
.acrossai-mcp-servers { }
.acrossai-mcp-servers--empty { }
.acrossai-mcp-servers__heading { margin: 0 0 1em 0; }
.acrossai-mcp-servers__list { list-style: none; padding: 0; margin: 0; display: grid; gap: 1em; }
.acrossai-mcp-servers__server { border: 1px solid currentColor; border-radius: 4px; padding: 1em; }
.acrossai-mcp-servers__server-name { margin: 0 0 0.25em 0; font-size: 1.1em; }
.acrossai-mcp-servers__server-desc { margin: 0 0 0.75em 0; opacity: 0.85; font-size: 0.9em; }
.acrossai-mcp-servers__transports { display: grid; gap: 0.75em; }
.acrossai-mcp-servers__transport-label { margin: 0 0 0.35em 0; font-size: 0.95em; }
.acrossai-mcp-servers__dtos { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 0.5em; }
.acrossai-mcp-servers__dto { display: inline-flex; align-items: center; gap: 0.35em; padding: 0.25em 0.6em; border: 1px solid currentColor; border-radius: 999px; font-size: 0.9em; }
.acrossai-mcp-servers__icon { display: inline-block; width: 1.1em; height: 1.1em; }
.acrossai-mcp-servers__icon img { max-width: 100%; height: auto; display: block; }
```

Uses `currentColor` for borders so the widget inherits theme text color. Grid + flex — no fixed pixel widths beyond icon sizing. `<style>` block emitted verbatim; wrapper `<style type="text/css">…</style>` (no CDATA, matches WP theme conventions).

**Invariant — static CSS only (SEC-005)**: the emitted `<style>` block content is a static CSS literal. No user-supplied, DTO-supplied, filter-supplied, or attribute-supplied value may be interpolated into it — ever. Future dynamic values (per-shortcode-instance theming, custom brand colors, etc.) MUST be applied via `data-*` attributes on the wrapper `<div>` and CSS attribute selectors (`.acrossai-mcp-servers[data-theme="dark"] { … }`) — never via string concatenation into the `<style>` block. `esc_html` and `esc_attr` DO NOT escape CSS context (analog of B36 for CSS instead of JS). A grep-gate on `public/Renderers/UserServers/UserServersBlock.php` MUST catch any interpolated variable inside the `<style>` block source region.

---

## Filter contract

### `acrossai_mcp_servers_shortcode_html`

- **Fires**: exactly once per `render_shortcode()` invocation, immediately before return.
- **Signature**: `apply_filters( 'acrossai_mcp_servers_shortcode_html', string $html, array $data, array $atts ): string`
- **Semantics**: consumers may wrap, prepend, append, or fully replace `$html`. Filter must return a string — F038 defensively coerces via `(string)` cast.
- **Non-goals**: NOT a place to re-render the data — use `AbstractUserServersRenderer::get_accessible_servers()` in a subclass for that.
- **Trust boundary disclosure (SEC-002)**: F038 returns the filter result verbatim WITHOUT re-escaping. Listener plugins are trusted at the filter boundary — a malicious or buggy listener can introduce XSS by returning attacker-controlled HTML. This matches WordPress's standard filter idiom (F037's `acrossai_mcp_embed_render_html` follows the same shape). Operators who install untrusted plugins that hook this filter accept the same risk they accept anywhere in WP's filter ecosystem.

---

## Class-level invariants

- **`final class`** per D36 (public `@experimental` renderer). Extension via filter, not subclass.
- **Singleton** per A2 with **private constructor** per S6.
- **`self::$style_emitted` is per-request** (PHP static — resets between HTTP requests naturally).
- **No hook registration in constructor** — `register_shortcode()` is the sole hook-registration entry, invoked from `Main::define_public_hooks()` on `init`.
- **Zero `wp_enqueue_*` calls** — inline `<style>` only.
- **Zero JS output** — no `<script>` tags emitted.

---

## Test contract (matches spec.md FR-027)

The `UserServersBlockTest` PHPUnit case MUST cover:

| Test | Precondition | Assertion |
|------|--------------|-----------|
| `test_anonymous_returns_empty_string` | `wp_set_current_user( 0 )` | `render_shortcode( [] ) === ''` |
| `test_empty_state_renders_wrapper_and_message` | Logged in, no accessible servers | Output contains `class="acrossai-mcp-servers acrossai-mcp-servers--empty"` AND default empty message |
| `test_custom_empty_message_attribute` | Logged in, no accessible servers, `empty_message="Nothing"` | Output contains "Nothing" text, NOT the default |
| `test_default_render_shape` | Logged in, one server with one DTO | Output contains outer `<div class="acrossai-mcp-servers">`, one `<li class="acrossai-mcp-servers__server">`, one `<li class="acrossai-mcp-servers__dto">` |
| `test_style_emitted_exactly_once` | Two `render_shortcode()` calls in one request | `substr_count( $html1 . $html2, '<style' ) === 1` |
| `test_icon_url_becomes_img` | DTO with `icon = 'https://cdn.example.com/i.png'` | Output contains `<img class="acrossai-mcp-servers__icon" src="https://cdn.example.com/i.png"` |
| `test_icon_non_url_becomes_text` | DTO with `icon = '🤖'` | Output contains `<span class="acrossai-mcp-servers__icon">🤖</span>` |
| `test_show_description_false_omits_desc` | Server with description, `show_description="0"` | Output does NOT contain `<p class="acrossai-mcp-servers__server-desc">` |
| `test_heading_attribute_renders_h2` | `heading="My servers"` | Output contains `<h2 class="acrossai-mcp-servers__heading">My servers</h2>` |
| `test_filter_round_trip_html` | Hook `acrossai_mcp_servers_shortcode_html` prepends comment | Comment appears in return |
| `test_escape_at_boundary` | Server with `server_name = 'Foo <script>bar</script>'` | Output does NOT contain `<script>`; contains escaped `&lt;script&gt;` |
| `test_singleton_private_ctor` | Reflection: `(new ReflectionClass(UserServersBlock::class))->getConstructor()->isPrivate()` | `true` |
