# Contract: `EmbedBlockRenderer`

**Feature**: F037 | **Date**: 2026-07-27 | **Plan**: [../plan.md](../plan.md)

Normative reference for the F037 frontend shortcode renderer. Closes SEC-037-004 (dedicated contract file for the escape shape at the render boundary).

---

## Namespace + File

- **Namespace**: `AcrossAI_MCP_Manager\Public\Renderers\EmbedBlock`
- **File**: `public/Renderers/EmbedBlock/EmbedBlockRenderer.php`
- **PHPDoc tag**: `@experimental until plugin 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API
- **Class declaration**: `final class` per D36 (extension via filter, never subclass)

---

## Shortcode Signature

```
[acrossai_mcp_embed server="<slug>" category="<key>" slug="<optional-dto-slug>"]
```

**Attributes**:

| Name | Required | Type | Description |
|------|----------|------|-------------|
| `server` | Yes | string | Server slug (matches `wp_acrossai_mcp_servers.server_slug`). Missing → silent no-render. |
| `category` | Yes | string | Transport key from F035 DTO `category` field (`'npm'`, `'client'`, `'ai_connector'`, or a companion-plugin key). Missing → silent no-render. |
| `slug` | No | string | Optional DTO slug for single-DTO output (via `ConnectionMethodRegistry::find()`). Absent → whole-category output (via `get_*_methods()`). |

**Shortcode name registered in `Main::define_public_hooks()` per A1.**

---

## 3-Gate Cascade Order (FR-013)

Every gate MUST evaluate in order; any miss → silent zero-byte no-render:

1. **Server resolution**: `MCPServer\Query::get_by_slug( $atts['server'] )` — missing server → return `''`.
2. **Gate 1 (F037 master + per-transport)**: `AbstractEmbedTransport::is_enabled_for_server( $server_id, $atts['category'] )` — false → return `''`.
3. **Gate 2 (F015 access control)**: `class_exists( '\AcrossAI_MCP_Access_Control' ) ? \AcrossAI_MCP_Access_Control::user_has_server_access( get_current_user_id(), $server_id ) : true` — false → return `''`. Fail-open per D19 when wrapper absent.
4. **DTO resolution**: `ConnectionMethodRegistry::instance()->find( $atts['category'], $atts['slug'] )` (if slug attr present) OR `get_npm_methods()` / `get_clients()` / `get_ai_connectors()` (whole category, filtered by category key mapping). Missing DTO → return `''`.
5. **Render + filter**: escape at boundary + `apply_filters( 'acrossai_mcp_embed_render_html', $html, $atts, $server_id )` + return.

---

## DTO Escape Contract (SEC-035-002 preservation invariant)

F035's DTO string fields are NOT pre-escaped. The renderer OWNS render-time escaping. Per-field escape function:

| DTO Field | Escape Function | Context |
|-----------|-----------------|---------|
| `name` | `esc_html()` | Text content in headings / labels |
| `description` | `esc_html()` | Text content in `<p>` |
| `icon` (emoji) | `esc_html()` | Text content — emoji is UTF-8, HTML-safe when escaped |
| `icon` (URL) | `esc_url()` | `<img src>` attribute |
| `meta.command_template` | `esc_html()` | Text content in `<code>` block |
| `meta.enabled_option` | NOT rendered | Internal only — do not emit; consumer only |
| `meta.config_file` | `esc_html()` | Text content in `<code>` |
| `meta.top_level_key` | `esc_html()` | Text content in `<code>` |
| `meta.icon_url` | `esc_url()` | `<img src>` when icon field is URL |
| `meta.has_redirect_whitelist` | `checked()` / conditional class | Never rendered as raw string |
| `meta.class` | NOT rendered | FQN — internal identifier only |

**MUST NEVER USE**: raw echo without escape; `wp_kses_post()` on unescaped DTO strings (permissive; XSS-risk); `esc_html_e()` inside translation-free strings (only for translated i18n strings).

---

## Filter Contract (defined by F037)

### `acrossai_mcp_embed_render_html`

- **Fired from**: `EmbedBlockRenderer::render_shortcode()` exactly once per shortcode invocation, immediately BEFORE return.
- **Signature**: `string apply_filters( 'acrossai_mcp_embed_render_html', string $html, array $atts, int $server_id );`
- **Timing**: Post-render, pre-return. Consumer can wrap / decorate / replace the assembled HTML.
- **Consumer contract**: MAY return any string. If consumer returns non-string, `EmbedBlockRenderer` MUST cast to `(string)` before echo (defensive — matches SEC-035-001 pattern).
- **Escape responsibility**: consumer inherits the SEC-035-002 preservation invariant — if they insert new dynamic content into the string, they MUST escape.

---

## Singleton + Class Shape

```php
final class EmbedBlockRenderer {
    private static ?self $_instance = null;
    private function __construct() {}
    public static function instance(): self;
    public function render_shortcode( array $atts ): string;
}
```

- **Singleton per A2** — matches sibling renderers in `public/Renderers/`.
- **`private __construct()` per S6** — prevents double-instantiation → double hook registration.
- **`final class` per D36** — extension via filter `acrossai_mcp_embed_render_html`, never subclass.

---

## Grep Gates (SC-005 + SC-006 per FR-020 / FR-021)

Enforced by tasks.md T034 + T035:

- `grep -rEn 'apply_filters.*acrossai_mcp_(client_classes|manager_connector_profiles|npm_methods|connection_methods)' public/Renderers/EmbedBlock/` MUST return zero hits (delegation, not re-firing).
- `grep -rEn 'add_filter|add_action|add_shortcode' public/Renderers/EmbedBlock/` MUST return zero hits (A1 — all wiring in `Main.php`).
- `grep -rn "'date_updated'" public/Renderers/EmbedBlock/` MUST return zero hits (irrelevant here — no DB code — but consistent audit surface).

---

## Contract Stability

Same as F035's `ConnectionMethodRegistry`: `@experimental until plugin 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API. DTO shape freezes at 1.0.0; the shortcode attribute list + gate cascade shape freeze at 1.0.0. Pre-1.0.0 releases MAY change the shape between minor versions.
