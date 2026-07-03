# BuddyBoss integration example

Embed AcrossAI MCP Manager's client-configuration blocks inside a BuddyBoss member profile so members can copy their own MCP config JSON without visiting `wp-admin`.

> **⚠ Experimental API.** The public Renderer API is `@since 0.0.6 @experimental May change without notice before 1.0.0`. Third-party integrations should pin to a plugin version they've tested against.

## Minimal working integration

Drop the following into a small BuddyBoss integration plugin (or an `mu-plugin`). Assumes BuddyPress/BuddyBoss profile tabs API is available (`bp_core_new_nav_item`, `bp_displayed_user_id`, etc.).

```php
<?php
/**
 * Plugin Name: MCP × BuddyBoss glue
 */

// Register a new "MCP" tab on the BuddyBoss member profile.
add_action( 'bp_setup_nav', function () {
    if ( ! function_exists( 'bp_core_new_nav_item' ) ) { return; }

    bp_core_new_nav_item( array(
        'name'                    => 'MCP',
        'slug'                    => 'mcp',
        'position'                => 90,
        'screen_function'         => 'my_mcp_profile_screen',
        'default_subnav_slug'     => 'npm',
    ) );
} );

function my_mcp_profile_screen() {
    add_action( 'bp_template_content', 'my_mcp_profile_render' );
    bp_core_load_template( 'members/single/plugins' );
}

function my_mcp_profile_render() {
    $server_id = absint( get_option( 'my_mcp_default_server_id', 1 ) );

    // Render the npm block via the public action hook.
    do_action(
        'acrossai_mcp_render_client_block',
        'npm',                                          // Renderer slug: 'npm' | 'clients' | 'claude-connector'
        $server_id,
        array(
            'context'           => 'buddyboss-profile', // Custom context slug — binds the nonce
            'cap'               => 'read',              // Members viewing their own config only need 'read'
            'submit_target_url' => bp_displayed_user_domain() . 'mcp/',
            'user_id'           => bp_displayed_user_id(),
        )
    );
}
```

## Customize context defaults via the filter

Instead of passing `$context` at every call site, register a filter that applies your defaults across all contexts:

```php
add_filter(
    'acrossai_mcp_client_block_context',
    function ( array $context, string $renderer_slug, int $server_id ): array {
        // Only apply on BuddyBoss profile.
        if ( ( $context['context'] ?? '' ) !== 'buddyboss-profile' ) {
            return $context;
        }

        $context['cap']               = 'read';
        $context['user_id']           = bp_displayed_user_id();
        $context['submit_target_url'] = bp_displayed_user_domain() . 'mcp/';

        return $context;
    },
    10,
    3
);
```

## Security notes

- **Application Password generation is locked to `get_current_user_id()`.** If the currently logged-in member views another member's MCP profile tab, the "Generate New Application Password" button will render as **disabled**. The backing REST endpoint returns HTTP 403 if the request body's `user_id` differs from `get_current_user_id()`. This is defense-in-depth against admin-impersonation and is enforced at both UI and REST layers (SEC-013-002).
- **Nonces bind BOTH the server_id AND the context slug.** A nonce minted in `context='admin'` will not validate against a POST with `context='buddyboss-profile'` (SEC-013-001).
- **F012 settings toggles gate the npm + Claude Connector blocks uniformly.** If the site admin has turned off `acrossai_mcp_npm_login_enabled` in `wp-admin → AcrossAI → Settings → MCP`, the BuddyBoss profile embed renders the disabled notice with a link back to the setting (not the config UI). The MCP Clients block is not gated.

## Extending the MCP Clients sub-nav with a custom client

Third-party plugins can register additional `AbstractMCPClient` subclasses:

```php
add_filter( 'acrossai_mcp_client_classes', function ( array $classes ) {
    // Only append if the class exists and is a valid subclass.
    if (
        class_exists( '\MyPlugin\MCP\MyCustomClient' )
        && is_subclass_of( '\MyPlugin\MCP\MyCustomClient', '\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient' )
    ) {
        $classes[] = '\MyPlugin\MCP\MyCustomClient';
    }
    return $classes;
} );
```

Invalid FQNs (class doesn't exist, wrong base class) are silently skipped by `MCPClientsBlock`, so misconfigured third-party plugins don't crash the render (SEC-013-008).
