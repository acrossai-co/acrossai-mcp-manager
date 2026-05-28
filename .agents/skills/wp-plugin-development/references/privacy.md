# Privacy

## Register a personal data exporter

The `wp_privacy_personal_data_exporters` filter registers a callback that WordPress calls when a user requests their data via Tools → Export Personal Data.

```php
// Register via Loader in includes/Main.php
$this->loader->add_filter( 'wp_privacy_personal_data_exporters', $this, 'register_data_exporter' );

public function register_data_exporter( array $exporters ): array {
    $exporters['myplugin'] = [
        'exporter_friendly_name' => __( 'My Plugin Data', 'myplugin' ),
        'callback'               => [ $this, 'export_user_data' ],
    ];
    return $exporters;
}

/**
 * @return array{ data: list<array{ group_id: string, group_label: string, item_id: string, data: list<array{ name: string, value: string }> }>, done: bool }
 */
public function export_user_data( string $email, int $page = 1 ): array {
    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        return [ 'data' => [], 'done' => true ];
    }

    $user_id = $user->ID;
    $export_items = [];

    // Example: export plugin-stored order records
    global $wpdb;
    $records = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}myplugin_orders WHERE user_id = %d",
            $user_id
        ),
        ARRAY_A
    );

    foreach ( $records as $record ) {
        $export_items[] = [
            'group_id'    => 'myplugin-orders',
            'group_label' => __( 'My Plugin Orders', 'myplugin' ),
            'item_id'     => 'myplugin-order-' . absint( $record['id'] ),
            'data'        => [
                [ 'name' => __( 'Order ID', 'myplugin' ),    'value' => absint( $record['id'] ) ],
                [ 'name' => __( 'Amount', 'myplugin' ),      'value' => esc_html( $record['amount'] ) ],
                [ 'name' => __( 'Created', 'myplugin' ),     'value' => esc_html( $record['created_at'] ) ],
            ],
        ];
    }

    return [ 'data' => $export_items, 'done' => true ];
}
```

## Register a personal data eraser

The `wp_privacy_personal_data_erasers` filter registers a callback that fires when a user requests data erasure via Tools → Erase Personal Data.

```php
$this->loader->add_filter( 'wp_privacy_personal_data_erasers', $this, 'register_data_eraser' );

public function register_data_eraser( array $erasers ): array {
    $erasers['myplugin'] = [
        'eraser_friendly_name' => __( 'My Plugin Data', 'myplugin' ),
        'callback'             => [ $this, 'erase_user_data' ],
    ];
    return $erasers;
}

/**
 * @return array{ items_removed: bool, items_retained: bool, messages: string[], done: bool }
 */
public function erase_user_data( string $email, int $page = 1 ): array {
    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
    }

    $user_id = $user->ID;
    global $wpdb;

    // Anonymize rather than delete where records must be kept for accounting
    $updated = $wpdb->update(
        $wpdb->prefix . 'myplugin_orders',
        [
            'user_email' => 'anonymized@example.com',
            'user_name'  => __( 'Anonymized', 'myplugin' ),
        ],
        [ 'user_id' => $user_id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );

    // Delete user meta stored by this plugin
    delete_user_meta( $user_id, 'myplugin_consent_timestamp' );
    delete_user_meta( $user_id, 'myplugin_preferences' );

    return [
        'items_removed'  => $updated !== false,
        'items_retained' => false,
        'messages'       => [],
        'done'           => true,
    ];
}
```

## Privacy policy content suggestion

Suggest plugin-specific language for the site's privacy policy during `admin_init`:

```php
$this->loader->add_action( 'admin_init', $this, 'suggest_privacy_policy_content' );

public function suggest_privacy_policy_content(): void {
    if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
        return;
    }

    $content = '<h2>' . esc_html__( 'My Plugin', 'myplugin' ) . '</h2>' .
        '<p>' . esc_html__( 'When you place an order, My Plugin stores your name, email address, and order details to fulfil your purchase. This data is retained for 7 years to comply with financial record-keeping requirements.', 'myplugin' ) . '</p>' .
        '<p>' . esc_html__( 'My Plugin sends your email address to ExampleAPI to verify payment. See ExampleAPI\'s privacy policy at https://example.com/privacy.', 'myplugin' ) . '</p>';

    wp_add_privacy_policy_content( 'My Plugin', wp_kses_post( $content ) );
}
```

## Declare external services in readme.txt

If your plugin communicates with a third-party API, you must declare it in `readme.txt` under `== External services ==`:

```
== External services ==

This plugin communicates with ExampleAPI (https://api.example.com) to process payments.

* What is sent: user email address, order total, currency code.
* When it is sent: when the user completes checkout.
* Privacy policy: https://example.com/privacy
* Terms of service: https://example.com/terms
```

This disclosure is required for plugins distributed via the WordPress.org Plugin Directory.

## Consent tracking

Store consent with a timestamp and the policy version so you can re-prompt users when the policy changes.

```php
public function record_consent( int $user_id, string $policy_version ): void {
    update_user_meta( $user_id, 'myplugin_consent_timestamp', time() );
    update_user_meta( $user_id, 'myplugin_consent_policy_version', sanitize_text_field( $policy_version ) );
}

public function user_has_consented( int $user_id ): bool {
    $current_version   = get_option( 'myplugin_current_policy_version', '1.0' );
    $consented_version = get_user_meta( $user_id, 'myplugin_consent_policy_version', true );
    return $consented_version === $current_version;
}
```

When the policy version changes (update `myplugin_current_policy_version`), users who consented to an older version will be re-prompted on their next login.

## Data retention: automated cleanup cron

Define how long data is kept and automate its removal:

```php
// Schedule on activation
register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( 'myplugin_data_retention_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'myplugin_data_retention_cleanup' );
    }
} );

// Cleanup callback
$this->loader->add_action( 'myplugin_data_retention_cleanup', $this, 'run_data_retention' );

public function run_data_retention(): void {
    global $wpdb;

    // Delete orders older than 7 years (2555 days)
    $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 years' ) );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}myplugin_orders WHERE created_at < %s AND status = 'completed'",
            $cutoff
        )
    );

    error_log( '[myplugin] Data retention cleanup ran. Cutoff: ' . $cutoff );
}
```

Document the retention period in the plugin's privacy policy content and in `readme.txt`.

## Upstream reference

https://developer.wordpress.org/plugins/privacy/
