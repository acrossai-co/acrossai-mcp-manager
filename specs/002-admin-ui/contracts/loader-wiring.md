# Contract — Loader Wiring for `Includes\Main::define_admin_hooks()`

**Date**: 2026-06-17 | **Authoritative for**: FR-021

This contract locks down the exact body of `Includes\Main::define_admin_hooks()`
after Phase 2 ships. Implementation MUST match.

---

## Body (canonical)

```php
private function define_admin_hooks(): void {

    // ─── 1. Resolve every partial singleton to a named local variable. ───
    $admin_main             = \AcrossAI_MCP_Manager\Admin\Main::instance();
    $menu                   = \AcrossAI_MCP_Manager\Admin\Partials\Menu::instance();
    $settings               = \AcrossAI_MCP_Manager\Admin\Partials\Settings::instance();
    $application_passwords  = \AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords::instance();

    // ─── 2. Menu + plugin action link (US1). ───
    $this->loader->add_action( 'admin_menu', $menu, 'register_menu' );
    $this->loader->add_filter(
        'plugin_action_links_' . ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME,
        $menu,
        'plugin_action_links',
        10,
        1
    );

    // ─── 3. Settings dispatcher + registration (US2, US3, US2.7). ───
    $this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );
    $this->loader->add_action( 'admin_init', $settings, 'register_settings' );

    // ─── 4. Asset enqueue, guarded by get_current_screen() (US5). ───
    $this->loader->add_action( 'admin_enqueue_scripts', $admin_main, 'enqueue_styles' );
    $this->loader->add_action( 'admin_enqueue_scripts', $admin_main, 'enqueue_scripts' );

    // ─── 5. Application Passwords hooks (US3.3). ───
    //      Canonical hook names carried over verbatim from the source class.
    $this->loader->add_action( 'init', $application_passwords, 'register_post_type' );
    $this->loader->add_filter(
        'wp_authenticate_application_password_errors',
        $application_passwords,
        'maybe_block_application_password',
        10,
        3
    );
    // (Additional ApplicationPasswords hooks per the source class — implementer
    // copies the full list. Each registration MUST be a Loader call.)

    // ─── 6. Adapter-missing notice (US4) — register the renderer always; ───
    //      the renderer short-circuits when the adapter is present.
    $this->loader->add_action( 'admin_notices', $settings, 'render_missing_adapter_notice' );
    $this->loader->add_action(
        'wp_ajax_acrossai_mcp_dismiss_adapter_notice',
        $settings,
        'handle_adapter_notice_dismissal'
    );

    // ─── 7. Notice rendering for success/error query vars (FR-016). ───
    $this->loader->add_action( 'admin_notices', $settings, 'render_action_result_notice' );

    // ─── 8. Access Control integration (US3.4–3.5, US1.2). ───
    //      Guarded — skipped entirely if the vendor package is absent.
    if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
        $access_control_manager = \WPBoilerplate\AccessControl\AccessControlManager::instance();
        $this->loader->add_action( 'admin_menu', $access_control_manager, 'register_submenu' );
        // (Any additional AccessControlManager hooks per its public contract.
        // These are vendor-owned — Settings::render_access_control_tab calls
        // into the manager at render time without holding a long-lived ref.)
    }
}
```

---

## Invariants the body MUST satisfy

1. **Every line uses `$this->loader->add_action()` or `$this->loader->add_filter()`** — no direct `add_action()`/`add_filter()`.
2. **Every singleton is resolved to a named local variable BEFORE being passed to the Loader** — never `Settings::instance()` inline as the Loader's second arg.
3. **`class_exists()` guards** wrap the access-control wiring block (`\WPBoilerplate\AccessControl\AccessControlManager`).
4. **The adapter-missing renderer is wired unconditionally** — the *renderer itself* short-circuits when `\WP\MCP\Plugin` exists. This is a deliberate choice: registering the action conditionally would mean the dismissal-ajax handler also vanishes when the adapter appears mid-session, which is unwanted.

   *(Aside: The dismissal-ajax handler is registered separately so it remains callable even after the user has dismissed the notice — though in practice a dismissed notice never POSTs again. The handler is also safe under repeat-fire: setting user meta to `1` is idempotent.)*
5. **No other method anywhere in the codebase** calls
   `$this->loader->add_action()` or `$this->loader->add_filter()` for an
   admin-only hook — `Main::define_admin_hooks()` is the exhaustive list.
6. **Phase 1 TODO stubs for `Admin\Main`, `Menu`, `Settings`,
   `ApplicationPasswords`** are deleted and replaced by the lines above.
   The Phase 1 stubs for `REST\CliController`, `Includes\MCP\Controller`,
   and `Includes\OAuth\ClaudeConnectors` REMAIN as TODOs (they belong to
   later phases).

---

## Verification

After implementation, the following commands MUST all pass:

```bash
# No constructor add_action / add_filter under admin/.
grep -rn 'add_action\|add_filter' admin/ | grep -v 'admin/Main.php\b'
# Expected: empty output

# Every admin-related hook traces to define_admin_hooks().
grep -n 'loader->add_action\|loader->add_filter' includes/Main.php | wc -l
# Expected: ≥ 10 lines (the registrations above)

# No MCPServerTable:: calls in admin/.
grep -rn 'MCPServerTable::' admin/
# Expected: empty output

# No CliAuthLogTable:: calls in admin/.
grep -rn 'CliAuthLogTable::' admin/
# Expected: empty output
```
