# Contract — FrontendAuth Page (Phase 6.0 — HTML, not JSON)

**Date**: 2026-06-25 | **Owned by**: `Public\Partials\FrontendAuth`

The browser-mediated approval page lives at `https://{site_url}/acrossai-mcp-manager/` and is rendered server-side by `FrontendAuth::maybe_render_page` on `template_redirect`. It is NOT a JSON endpoint — it returns HTML.

## URL + dispatch

| Path | Action query var | Method | Renders |
|---|---|---|---|
| `/acrossai-mcp-manager/?action=cli_auth&code=<code>&server=<server>` | `cli_auth` | GET | Consent form (Approve button) |
| `/acrossai-mcp-manager/?action=cli_auth_approve&code=<code>&server=<server>&_wpnonce=<nonce>` | `cli_auth_approve` | GET (via Approve button click) | 302 → `?action=cli_auth_approved` on success; `wp_die(400)` on failure |
| `/acrossai-mcp-manager/?action=cli_auth_approved` | `cli_auth_approved` | GET | Success page ("Return to your CLI") |
| `/acrossai-mcp-manager/` (no action) OR unknown action | (default) | GET | "Missing code" error page |

## Rewrite rule

```php
// FrontendAuth::register_rewrite_rule() — wired on 'init' via Loader
add_rewrite_rule(
    '^' . self::PAGE_SLUG . '/?$',           // '^acrossai-mcp-manager/?$'
    'index.php?' . self::QUERY_VAR . '=1',   // 'index.php?acrossai_mcp_frontend_auth=1'
    'top'
);
```

The pattern has no `.` to escape (B4 N/A here).

## Class constants

```php
final class FrontendAuth {
    const PAGE_SLUG = 'acrossai-mcp-manager';
    const QUERY_VAR = 'acrossai_mcp_frontend_auth';
    // (no TTL constants — this class has no transient state of its own)
}
```

## Public callbacks (Loader-wired in Main::define_public_hooks)

| Hook | Method | Purpose |
|---|---|---|
| `init` | `register_rewrite_rule(): void` | Register the rewrite rule above |
| `query_vars` | `add_query_var( array $vars ): array` | Append `'acrossai_mcp_frontend_auth'` to `$vars` |
| `template_redirect` | `maybe_render_page(): void` | Dispatch to action handler; exit |
| `wp_enqueue_scripts` | `enqueue_assets(): void` | Enqueue minimal page styles (no JS this phase) |

## Static helper

```php
public static function get_base_url(): string {
    return home_url( '/' . self::PAGE_SLUG . '/' );
}
```

Used by:
- `CliController::handle_auth_start` — composes the `auth_url` returned to the CLI
- `FrontendAuth::handle_approve` itself — redirect target for the approved page

## `maybe_render_page` dispatcher (FR-007 of Phase 6.0)

```php
public function maybe_render_page(): void {
    if ( ! get_query_var( self::QUERY_VAR ) ) {
        return;  // not our request — let WP continue
    }
    nocache_headers();

    if ( ! is_user_logged_in() ) {
        wp_redirect( wp_login_url( self::get_base_url() ) );
        exit;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die(
            esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ),
            403
        );
    }

    $action  = isset( $_GET['action'] )
        ? sanitize_text_field( wp_unslash( $_GET['action'] ) )
        : '';
    $enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );

    if ( ! $enabled ) {
        $this->render_disabled_notice();
        exit;
    }

    switch ( $action ) {
        case 'cli_auth':
            $code   = isset( $_GET['code'] )   ? sanitize_text_field( wp_unslash( $_GET['code'] ) )   : '';
            $server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
            $this->handle_cli_auth( $code, $server );
            break;
        case 'cli_auth_approve':
            $code   = isset( $_GET['code'] )   ? sanitize_text_field( wp_unslash( $_GET['code'] ) )   : '';
            $server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
            check_admin_referer( 'cli_auth_approve_' . $code );  // CSRF defense
            $this->handle_approve( $code, $server );
            break;
        case 'cli_auth_approved':
            $this->handle_approved();
            break;
        default:
            $this->handle_cli_auth( '', '' );  // empty params → "missing code" page
    }
    exit;
}
```

## Private handler — `handle_cli_auth`

Renders the consent form when the page is hit with `?action=cli_auth&code=<code>&server=<server>`.

```php
private function handle_cli_auth( string $code, string $server ): void {
    // Validate inputs locally — the real check happens in CliController::approve_auth_code on submit.
    if ( '' === $code || '' === $server ) {
        $this->render_page_shell(
            '<h1>' . esc_html__( 'Missing Authentication Parameters', 'acrossai-mcp-manager' ) . '</h1>'
            . '<p>' . esc_html__( 'This page must be opened via a link from your CLI tool.', 'acrossai-mcp-manager' ) . '</p>'
        );
        return;
    }

    $approve_url = add_query_arg(
        array(
            'action'   => 'cli_auth_approve',
            'code'     => $code,
            'server'   => $server,
            '_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code ),
        ),
        self::get_base_url()
    );

    $html  = '<h1>' . esc_html__( 'Authorize CLI Access', 'acrossai-mcp-manager' ) . '</h1>';
    $html .= '<p>' . esc_html(
        sprintf(
            /* translators: 1: server slug */
            __( 'A CLI tool is requesting access to your MCP server "%1$s".', 'acrossai-mcp-manager' ),
            $server
        )
    ) . '</p>';
    $html .= '<p>' . esc_html__( 'Click Approve to grant the tool access. The session is single-use.', 'acrossai-mcp-manager' ) . '</p>';
    $html .= '<p><a class="button button-primary" href="' . esc_url( $approve_url ) . '">'
           . esc_html__( 'Approve', 'acrossai-mcp-manager' ) . '</a></p>';

    $this->render_page_shell( $html );
}
```

## Private handler — `handle_approve`

Called when the user clicks **Approve** (`?action=cli_auth_approve` + nonce).

```php
private function handle_approve( string $code, string $server ): void {
    // Capability recheck — defense-in-depth on top of maybe_render_page's check.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die(
            esc_html__( 'You do not have permission to approve CLI authentication.', 'acrossai-mcp-manager' ),
            403
        );
    }

    if ( '' === $code ) {
        wp_die( esc_html__( 'Missing authorization code.', 'acrossai-mcp-manager' ), 400 );
    }

    $approved = \AcrossAI_MCP_Manager\Includes\REST\CliController::approve_auth_code(
        $code,
        get_current_user_id()
    );

    if ( ! $approved ) {
        wp_die(
            esc_html__( 'This authorization code is no longer valid. It may have expired or been used already.', 'acrossai-mcp-manager' ),
            400
        );
    }

    wp_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) );
    exit;
}
```

## Private handler — `handle_approved`

Success page rendered after the redirect from `handle_approve`.

```php
private function handle_approved(): void {
    $html  = '<h1>' . esc_html__( 'CLI Authorization Approved', 'acrossai-mcp-manager' ) . '</h1>';
    $html .= '<p>' . esc_html__( 'You can now return to your CLI tool — it will detect the approval shortly.', 'acrossai-mcp-manager' ) . '</p>';
    $html .= '<p>' . esc_html__( 'This page can be closed.', 'acrossai-mcp-manager' ) . '</p>';

    $this->render_page_shell( $html );
}
```

## Private handler — `render_disabled_notice`

Rendered when the feature flag `acrossai_mcp_npm_login_enabled` is `false`.

```php
private function render_disabled_notice(): void {
    status_header( 503 );  // Service Unavailable — feature is off
    $html  = '<h1>' . esc_html__( 'CLI Login Not Enabled', 'acrossai-mcp-manager' ) . '</h1>';
    $html .= '<p>' . esc_html__( 'The CLI login flow is currently disabled on this site. Contact your administrator.', 'acrossai-mcp-manager' ) . '</p>';
    $this->render_page_shell( $html );
}
```

## Private helper — `render_page_shell`

Wraps the page body in a minimal HTML shell. **Does NOT call `wp_head()`** — this is a standalone authentication page, not a themed WP page. Themes / page-builders MUST NOT inject markup.

```php
private function render_page_shell( string $content ): void {
    header( 'Content-Type: text/html; charset=UTF-8' );
    $title = esc_html__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' );

    echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>';
    echo '<meta charset="utf-8">';
    echo '<title>' . $title . '</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:5em auto;padding:0 1em;color:#1d2327}h1{font-size:1.5em}.button{display:inline-block;padding:0.5em 1.5em;background:#2271b1;color:#fff;border:1px solid #2271b1;border-radius:3px;text-decoration:none;font-size:1em}.button-primary{font-weight:600}</style>';
    echo '</head><body>';
    echo $content;  // ALREADY ESCAPED at composition site — caller's responsibility
    echo '</body></html>';
}
```

## CSRF defense

| Action | Defense |
|---|---|
| `cli_auth` (consent form display) | None needed — read-only render of attacker-supplied params, no state mutation |
| `cli_auth_approve` (state mutation) | `check_admin_referer( 'cli_auth_approve_' . $code )` — per-code nonce; `current_user_can('manage_options')` recheck inside `handle_approve` |
| `cli_auth_approved` (success display) | None needed — read-only render, no state mutation |

## Test invariants

- Not-logged-in visit → 302 to `wp-login.php?redirect_to=<base_url>`. PHPUnit `MaybeRenderPageTest::test_not_logged_in_redirects_to_login`.
- Non-admin visit → 403 `wp_die`. PHPUnit `MaybeRenderPageTest::test_non_admin_returns_403`.
- Feature flag disabled → 503 + disabled notice. PHPUnit `DisabledNoticeTest::test_disabled_flag_returns_503`.
- `cli_auth_approve` without nonce → `wp_die`. PHPUnit `HandleApproveTest::test_missing_nonce_dies`.
- `cli_auth_approve` with valid nonce + admin + valid code → 302 to `cli_auth_approved` AND `CliController::approve_auth_code` is called with the granting user's ID. PHPUnit `HandleApproveTest::test_valid_approve_redirects_and_calls_controller`.
- `cli_auth_approve` with invalid code (expired transient) → 400 `wp_die`. PHPUnit `HandleApproveTest::test_invalid_code_returns_400`.
- No `wp_head()` call → page shell does NOT include WP-emitted styles/scripts. PHPUnit `MaybeRenderPageTest::test_page_shell_omits_wp_head` (asserts the output buffer does NOT contain `<link rel='stylesheet'` or `<script src=`).
