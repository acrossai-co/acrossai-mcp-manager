# Quickstart — Extending the Embeds Subsystem

**Feature**: F037 | **Date**: 2026-07-27 | **Plan**: [plan.md](./plan.md)

Five-minute walkthrough for companion-plugin developers. Exercises User Stories 2 (extensibility) and 3 (security cascade) as documentation.

Target audience: authors of add-on plugins (planned BuddyBoss add-on) that need a per-server enable/disable checkbox in the Embeds admin tab AND a matching gate check for their own frontend renderer.

---

## 1. Add a custom transport category (User Story 2)

Create a subclass of `AbstractEmbedTransport` under your plugin's namespace:

```php
namespace My\Plugin\Embeds;

use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

final class BuddyBossProfileEmbedTransport extends AbstractEmbedTransport {

    public function get_transport_key(): string {
        return 'buddyboss-profile';
    }

    public function get_checkbox_label(): string {
        return __( 'BuddyPress profile MCP badge', 'my-plugin' );
    }

    public function get_priority(): int {
        return 40; // After AI Connectors (30); before default third-party bucket (100).
    }
}
```

Register it via the filter:

```php
add_filter( 'acrossai_mcp_embed_transports', function ( array $fqns ): array {
    $fqns[] = \My\Plugin\Embeds\BuddyBossProfileEmbedTransport::class;
    return $fqns;
} );
```

Register the filter callback at `plugins_loaded` or `init` — BEFORE any admin request calls `AbstractEmbedTransport::get_all_registered_transports()`.

Result: the Embeds tab on every server-edit page now shows a fourth checkbox row labeled "BuddyPress profile MCP badge" in priority slot 40 (between AI Connectors and any default-priority third-party).

**Rules the filter enforces**:
- Your FQN MUST be a `class_exists()` string subclassing `AbstractEmbedTransport` — else silently dropped.
- `get_transport_key()` MUST match regex `/\A[a-z0-9-]{1,64}\z/` — else silently dropped + `_doing_it_wrong` under `WP_DEBUG`.
- If your `transport_key` collides with a built-in (`npm`, `client`, `ai_connector`) or another third-party, later-wins per D35 dedup.
- Your class MUST be `final` per D36 policy — the base plugin doesn't enforce this, but nothing should extend your subclass (extend the base directly instead).

---

## 2. Gate your own frontend renderer on the toggle state

Your companion plugin's own shortcode/block/BuddyPress-integration render code:

```php
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

function my_plugin_render_buddyboss_profile_badge( array $server ): string {
    if ( ! AbstractEmbedTransport::is_enabled_for_server( $server['id'], 'buddyboss-profile' ) ) {
        return ''; // Silent no-render — matches SC-004 gate cascade contract.
    }

    // Optional additional gate: check F015 access control for the current user.
    if ( class_exists( \AcrossAI_MCP_Access_Control::class ) ) {
        if ( ! \AcrossAI_MCP_Access_Control::user_has_server_access( get_current_user_id(), $server['id'] ) ) {
            return '';
        }
    }

    return '<div class="buddyboss-mcp-badge">…</div>';
}
```

**Rules the gate enforces**:
- Returns `false` unless BOTH master toggle is ON AND your transport row is present with `is_enabled = 1`.
- Memoized per-request: safe to call in a hot loop (e.g., 100 shortcodes on a page).
- Missing server row → `false` (silent).
- Missing transport row → `false` (silent).

---

## 3. Subscribe to observability actions for audit logging

The base plugin fires two actions on every admin save that changes state. Audit-log plugins can subscribe:

```php
add_action(
    'acrossai_mcp_embed_master_toggled',
    function ( int $server_id, bool $enabled, int $user_id ): void {
        my_audit_log(
            sprintf(
                'User %d %s frontend embeds master toggle for server %d.',
                $user_id,
                $enabled ? 'ENABLED' : 'DISABLED',
                $server_id
            )
        );
    },
    10,
    3
);

add_action(
    'acrossai_mcp_embed_transport_toggled',
    function ( int $server_id, string $transport_key, bool $enabled, int $user_id ): void {
        my_audit_log(
            sprintf(
                'User %d %s transport [%s] for server %d.',
                $user_id,
                $enabled ? 'ENABLED' : 'DISABLED',
                $transport_key,
                $server_id
            )
        );
    },
    10,
    4
);
```

**Rules the actions enforce**:
- Fire ONLY on actual value transitions (0 → 1 OR 1 → 0). No-op saves emit nothing.
- Fire AFTER the DB commit (per R3) — you can safely read the new value from the database inside your listener.
- Per-listener `try/catch` — a listener that throws does NOT prevent other listeners from firing OR roll back the DB write.
- Companion plugins CAN also use these hooks to pre-warm caches, invalidate CDN edges, etc.

---

## 4. Clean up your junction rows on uninstall

If your plugin is fully uninstalled (not just deactivated), invoke the F037 GC helper from your `uninstall.php`:

```php
if ( class_exists( \AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::class ) ) {
    // At uninstall time, your class is already gone from the filter list →
    // get_all_registered_transports() won't include your transport key →
    // garbage_collect_orphans() will prune your rows.
    \AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::garbage_collect_orphans();
}
```

**Rules the GC enforces**:
- Only prunes rows whose `transport_key` is NOT in the currently-registered transport set.
- Idempotent: second call returns 0.
- Returns count of pruned rows — you can log this for observability.
- Base plugin (F037) NEVER calls this itself. Companion plugins opt-in.

**Trade-off** (per Clarifications Q2): if a user reinstalls your plugin after uninstalling, THEIR OLD SETTINGS are gone (they got pruned). If you want reinstall to restore settings, don't call the GC helper on uninstall — matches WordPress convention where uninstall is "final".

---

## Consumer contract summary

- **DTO alignment (Q1)**: F037 transport keys align 1:1 with F035 DTO `category` field values (`npm`, `client`, `ai_connector`). If you're iterating `ConnectionMethodRegistry::get_all()` and calling `is_enabled_for_server()`, pass `$dto['category']` directly — no translation.
- **Row lifecycle (Q2)**: Deactivating your plugin leaves junction rows intact. Reactivating restores the saved state. Opt-in cleanup via `garbage_collect_orphans()`.
- **Observability (Q3)**: Two granular actions on every value-changing save. Fail-forward per-listener.
- **Security note (SEC-035-002 inheritance)**: Your shortcode/block output MUST escape DTO string fields at the render boundary (`esc_html()`, `esc_attr()`, `esc_url()` per context). F035 does not pre-escape; consumers own render-time escaping.
