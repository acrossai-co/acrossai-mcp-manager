# WooCommerce integration example

Embed AcrossAI MCP Manager's client-configuration blocks inside a WooCommerce "My Account" tab so customers can grab their own MCP config from the storefront.

> **⚠ Experimental API.** The public Renderer API is `@since 0.0.6 @experimental May change without notice before 1.0.0`. Pin to a plugin version you've tested against.

## Minimal working integration

Drop into a small WooCommerce integration plugin.

```php
<?php
/**
 * Plugin Name: MCP × WooCommerce glue
 */

// Register a new "MCP" tab on the My Account page.
add_action( 'init', function () {
    add_rewrite_endpoint( 'mcp', EP_ROOT | EP_PAGES );
} );

add_filter( 'woocommerce_account_menu_items', function ( array $items ): array {
    $items['mcp'] = __( 'MCP', 'my-plugin' );
    return $items;
} );

add_action( 'woocommerce_account_mcp_endpoint', function () {
    if ( ! is_user_logged_in() ) { return; }

    $server_id = absint( get_option( 'my_mcp_default_server_id', 1 ) );

    // Render MCP Clients block — customers pick their client and grab config.
    do_action(
        'acrossai_mcp_render_client_block',
        'clients',                                       // Renderer slug
        $server_id,
        array(
            'context'           => 'woocommerce-my-account',
            'cap'               => 'read',               // Customers only need 'read' for their own config
            'submit_target_url' => wc_get_account_endpoint_url( 'mcp' ),
            'user_id'           => get_current_user_id(),
        )
    );
} );
```

## Or use the shortcode

For simple integrations that just need a block on a WordPress page:

```
[acrossai_mcp_clients_block server="1"]
```

The shortcode uses `context='shortcode'` by default. Combine with a `cap` filter for public-facing pages:

```php
add_filter( 'acrossai_mcp_client_block_context', function ( array $context, string $slug ): array {
    if ( ( $context['context'] ?? '' ) === 'shortcode' ) {
        $context['cap'] = 'read';  // Anyone signed in can view their own config
    }
    return $context;
}, 10, 3 );
```

## Security notes

- **Application Password generation is locked to `get_current_user_id()`.** In a WooCommerce context, this means one customer cannot mint a password for another (defense against admin-impersonation via account switching or malicious REST requests). SEC-013-002.
- **Nonces bind server_id AND context.** A nonce minted for `context='woocommerce-my-account'` will not validate against a `context='admin'` POST, preventing cross-context replay. SEC-013-001.
- **F012 settings toggles gate the npm + Claude Connector blocks uniformly.** If the site admin has turned off npm connections in `wp-admin → AcrossAI → Settings → MCP`, the WooCommerce embed shows the disabled notice.
- **The `cap` context value MUST match the operation you're exposing.** Use `'read'` for viewing own config only. Do NOT use `'read'` for mutating operations — the Renderer will not stop you, but the underlying save handler expects `manage_options` for server metadata edits. If a customer-facing embed tried to expose the Update Server or Danger Zone tabs (it shouldn't), those tabs' internal form action would still be `manage_options`-gated at the server layer.

## Advanced — inject a WooCommerce-specific MCP client

If your WooCommerce integration ships a dedicated `AbstractMCPClient` subclass (e.g., one that pre-fills WooCommerce API credentials), register it:

```php
add_filter( 'acrossai_mcp_client_classes', function ( array $classes ) {
    if (
        class_exists( '\WCMCP\Client\WooMCPClient' )
        && is_subclass_of( '\WCMCP\Client\WooMCPClient', '\AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient' )
    ) {
        $classes[] = '\WCMCP\Client\WooMCPClient';
    }
    return $classes;
} );
```

Invalid FQNs are silently skipped, so a broken third-party plugin won't take down the MCP Clients block for your customers (SEC-013-008).
