# Contract — Adapter-Missing Notice Dismissal

**Date**: 2026-06-17 | **Authoritative for**: FR-015, Q3 clarification

The JS↔PHP contract for persisting the adapter-missing notice dismissal,
per US4 and the Q3 sticky-per-user decision.

---

## Endpoint

| Property | Value |
|---|---|
| URL | `wp-admin/admin-ajax.php` |
| Method | `POST` |
| `action` param | `acrossai_mcp_dismiss_adapter_notice` |
| Required body | `_ajax_nonce` (matches nonce action `acrossai_mcp_dismiss_adapter_notice`) |

---

## PHP handler — `Admin\Partials\Settings::handle_adapter_notice_dismissal()`

```php
public function handle_adapter_notice_dismissal(): void {
    check_ajax_referer( 'acrossai_mcp_dismiss_adapter_notice' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
    }

    update_user_meta(
        get_current_user_id(),
        'acrossai_mcp_dismissed_adapter_notice',
        1
    );

    wp_send_json_success();
}
```

**Security**:
- `check_ajax_referer()` verifies the nonce; failure → `wp_die()` (default).
- `manage_options` capability is required (defence in depth — non-admins
  shouldn't see the notice anyway).
- `update_user_meta()` is idempotent under repeat-fire.

---

## PHP render guard — `Admin\Partials\Settings::render_missing_adapter_notice()`

```php
public function render_missing_adapter_notice(): void {
    if ( class_exists( '\WP\MCP\Plugin' ) ) {
        return; // Adapter is installed — no notice.
    }
    if ( get_user_meta( get_current_user_id(), 'acrossai_mcp_dismissed_adapter_notice', true ) ) {
        return; // User already dismissed it.
    }

    $nonce = wp_create_nonce( 'acrossai_mcp_dismiss_adapter_notice' );

    printf(
        '<div class="notice notice-warning is-dismissible acrossai-mcp-adapter-notice" data-nonce="%s"><p>%s</p></div>',
        esc_attr( $nonce ),
        esc_html__( 'The WordPress MCP adapter package is not installed. MCP servers will not respond until you install the wordpress/mcp-adapter package.', 'acrossai-mcp-manager' )
    );
}
```

---

## JS — small inline script enqueued only when the notice is rendered

Loaded by `Admin\Main::enqueue_scripts()` IF
`render_missing_adapter_notice()` short-circuit conditions are both false.
Implementation hooks the standard `.notice-dismiss` click event added by
core to `is-dismissible` notices:

```js
( function () {
    document.addEventListener( 'click', function ( event ) {
        const dismissBtn = event.target.closest(
            '.acrossai-mcp-adapter-notice .notice-dismiss'
        );
        if ( ! dismissBtn ) {
            return;
        }
        const notice = dismissBtn.closest( '.acrossai-mcp-adapter-notice' );
        const nonce  = notice.dataset.nonce;

        const body = new URLSearchParams();
        body.set( 'action', 'acrossai_mcp_dismiss_adapter_notice' );
        body.set( '_ajax_nonce', nonce );

        fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body } );
        // Fire-and-forget — core JS already removes the notice from the DOM.
    } );
} )();
```

**Why fire-and-forget**: WordPress core's `wp-admin/js/common.js` already
removes the notice DOM element when `.notice-dismiss` is clicked. The fetch
exists solely to persist the dismissal so it doesn't re-appear on the next
page load. No response handling is required — even on transient failure,
the user can dismiss again next page load.

---

## Negative-path contract

| Scenario | Server response | Effect |
|---|---|---|
| Nonce missing/invalid | `wp_die()` via `check_ajax_referer()` default | User sees blank `-1` body; notice still dismissed in-DOM for this page load; reappears next page load. |
| User lacks `manage_options` | 403 JSON: `{"success":false,"data":{"message":"forbidden"}}` | Notice reappears next page load (intended — non-admins should not be silencing admin notices). |
| `update_user_meta()` returns false (no-op because value unchanged) | 200 JSON: `{"success":true}` | Idempotent — value was already `1`. Treated as success. |
| Adapter installed mid-session | Render guard short-circuits | Notice doesn't render — dismissal endpoint is a no-op (no one POSTs to it). |
