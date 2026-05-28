# Settings API

## Overview

The WordPress Settings API provides a structured way to add plugin settings pages, validate input, and store options. Always use it rather than rolling custom form handling.

## Complete working example

```php
namespace MyPlugin\Admin\Partials;

class SettingsPage {

    private const OPTION_GROUP = 'myplugin_options';
    private const OPTION_KEY   = 'myplugin_settings';

    /**
     * Register sections, fields, and the option itself.
     * Hook this on admin_init via the Loader.
     */
    public function register_settings(): void {
        // 1. Register the option — always supply a sanitize_callback.
        register_setting(
            self::OPTION_GROUP,           // Option group (used in settings_fields())
            self::OPTION_KEY,             // Option name in wp_options
            [
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => $this->default_settings(),
            ]
        );

        // 2. Add a section.
        add_settings_section(
            'myplugin_general_section',         // Section ID
            __( 'General Settings', 'myplugin' ), // Title
            [ $this, 'render_general_section' ],  // Description callback
            'myplugin-settings'                   // Page slug (passed to do_settings_sections())
        );

        // 3. Add fields.
        add_settings_field(
            'api_key',                              // Field ID
            __( 'API Key', 'myplugin' ),            // Label
            [ $this, 'render_api_key_field' ],      // Render callback
            'myplugin-settings',                    // Page slug
            'myplugin_general_section',             // Section ID
            [ 'label_for' => 'myplugin_api_key' ]  // Extra args — label_for links <label> to field
        );

        add_settings_field(
            'enable_logging',
            __( 'Enable Logging', 'myplugin' ),
            [ $this, 'render_logging_field' ],
            'myplugin-settings',
            'myplugin_general_section',
            [ 'label_for' => 'myplugin_enable_logging' ]
        );
    }

    /** Sanitize the whole option array before it is saved. */
    public function sanitize_settings( mixed $input ): array {
        $output   = $this->default_settings();
        $raw      = is_array( $input ) ? $input : [];

        $output['api_key']        = sanitize_text_field( wp_unslash( $raw['api_key'] ?? '' ) );
        $output['enable_logging'] = ! empty( $raw['enable_logging'] );

        return $output;
    }

    private function default_settings(): array {
        return [
            'api_key'        => '',
            'enable_logging' => false,
        ];
    }

    public function render_general_section(): void {
        echo '<p>' . esc_html__( 'Configure the core plugin behaviour below.', 'myplugin' ) . '</p>';
    }

    public function render_api_key_field(): void {
        $options = $this->get_settings();
        printf(
            '<input type="text" id="myplugin_api_key" name="%s[api_key]" value="%s" class="regular-text">',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $options['api_key'] )
        );
        echo '<p class="description">' . esc_html__( 'Your API key from the service dashboard.', 'myplugin' ) . '</p>';
    }

    public function render_logging_field(): void {
        $options = $this->get_settings();
        printf(
            '<input type="checkbox" id="myplugin_enable_logging" name="%s[enable_logging]" value="1" %s>',
            esc_attr( self::OPTION_KEY ),
            checked( $options['enable_logging'], true, false )
        );
        echo '<label for="myplugin_enable_logging">' . esc_html__( 'Log plugin activity to debug.log', 'myplugin' ) . '</label>';
    }

    /** Render the settings page. */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );   // Outputs nonce, action, option_page fields
                do_settings_sections( 'myplugin-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /** Always supply a default — never assume the option exists. */
    public function get_settings(): array {
        return (array) get_option( self::OPTION_KEY, $this->default_settings() );
    }
}
```

Registering the hooks (in `includes/Main.php`):

```php
$settings = new \MyPlugin\Admin\Partials\SettingsPage();
$this->loader->add_action( 'admin_init', $settings, 'register_settings' );
$this->loader->add_action( 'admin_menu', $settings, 'register_menu' );
```

## Option group naming

Always follow the pattern `{plugin-prefix}_options`:

```
myplugin_options
procureco_options
acme_widget_options
```

The option group name is used in `settings_fields()` and `register_setting()`. Keep it consistent.

## Never write to options directly from `$_POST`

The Settings API handles sanitization and storage automatically when you use `settings_fields()` + `do_settings_sections()` + `submit_button()`. If you implement a custom save handler (e.g. for AJAX or custom admin-post.php), you must manually verify the nonce, check capabilities, sanitize, and then call `update_option()`:

```php
public function custom_save_handler(): void {
    check_admin_referer( 'myplugin_settings_save', 'myplugin_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Forbidden' );
    }

    $data = [
        'api_key'        => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
        'enable_logging' => ! empty( $_POST['enable_logging'] ),
    ];

    update_option( 'myplugin_settings', $data );
    wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
    exit;
}
```

## Always supply a default to `get_option()`

```php
// WRONG — returns false when option does not exist yet
$settings = get_option( 'myplugin_settings' );

// CORRECT — returns a predictable array
$settings = get_option( 'myplugin_settings', [
    'api_key'        => '',
    'enable_logging' => false,
] );
```

## Store related options as a single serialized array

Storing dozens of individual options bloats the `wp_options` table and increases autoloaded data. Group related settings:

```php
// WRONG — 10 separate options, each autoloaded
update_option( 'myplugin_api_key', $key );
update_option( 'myplugin_enable_logging', $flag );
// ...

// CORRECT — one option key, one autoload row
update_option( 'myplugin_settings', [
    'api_key'        => $key,
    'enable_logging' => $flag,
    // ...
] );
```

## Autoload: set to false for infrequent options

Options with `autoload = true` (the default) are loaded on every page request. Set `autoload = false` for options that are only needed in specific contexts.

```php
// On first install / activation:
add_option( 'myplugin_import_log', [], '', 'no' );  // 'no' = do not autoload

// Or when updating:
update_option( 'myplugin_import_log', $log_data, false );
```

Options needed on every front-end request (e.g. plugin slug, version) should autoload. Options needed only on a settings page should not.

## `update_option()` vs `add_option()`

Prefer `update_option()` — it creates the row if it does not exist, and updates it if it does. Use `add_option()` only when you explicitly want a no-op if the option already exists (e.g. seeding a default on activation without overwriting user data).

```php
// Activation hook — seed defaults without overwriting:
register_activation_hook( __FILE__, function () {
    add_option( 'myplugin_settings', [
        'api_key'        => '',
        'enable_logging' => false,
    ], '', 'no' );
} );
```

## Upstream reference

https://developer.wordpress.org/plugins/settings/
