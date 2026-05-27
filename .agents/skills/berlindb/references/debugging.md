# BerlinDB Debugging

## Silent INSERT/UPDATE failure → REST 500

**Symptom**: `save_override()` returns `false`, REST endpoint returns `{"code":"rest_save_failed","data":{"status":500}}`. No DB error in `debug.log`. No failed query in SAVEQUERIES log.

**Diagnostic**: Add temporary logging inside `save_override()`:

```php
$result = $this->add_item( $fields );
error_log( '[berlindb] add_item result: ' . var_export( $result, true ) );
error_log( '[berlindb] last wpdb error: ' . $GLOBALS['wpdb']->last_error );
```

If `last_error` is empty and `$result` is `false`, the failure is in PHP-layer validation before the DB is touched.

**Root cause A — `allow_null` key mismatch (most common)**:

One or more columns in your Schema class use `'null' => true` instead of `'allow_null' => true`. `validate_item()` returns `false` on any null-valued field where the Column object has `allow_null = false`.

Fix: replace all `'null' => true` with `'allow_null' => true` in your Schema class.

Verification:

```php
// Temporary — dump Column allow_null values for all columns
$query = new MyQuery();
foreach ( $query->get_columns() as $col ) {
    error_log( $col->name . ' allow_null=' . var_export( $col->allow_null, true ) );
}
```

All nullable columns should show `allow_null=true`. Any showing `allow_null=false` with a nullable DB column is the culprit.

**Root cause B — PHP array not JSON-encoded**:

Passing a PHP array as the value for a `longtext` column. `$wpdb->insert()` serialises it as the string `"Array"`, which may trigger a validation error or unexpected behavior.

Fix: `wp_json_encode()` the array before passing to `add_item()`.

---

## Wrong primary key type in update/delete

**Symptom**: `update_item()` or `delete_item()` returns `false` even though the row exists.

**Root cause**: Passing the business key (slug string) as the first argument instead of the integer primary key.

```php
// WRONG
$this->update_item( $slug, $data );

// CORRECT
$existing = $this->get_override_by_slug( $slug );
$this->update_item( $existing->id, $data );
```

BerlinDB's first argument to `update_item` / `delete_item` is always the integer primary key from the `id` column.

---

## Tri-state Inherit === No (both stored as 0)

**Symptom**: Selecting "Inherit" in the UI stores `0` in the DB, same as "No". The distinction between "no override" and "explicit No" is lost.

**Root cause**: Two possible causes — either:
1. PHP loose equality in `sanitize_tri_state()` (e.g. `if ( $value == null )`) collapses `null` and `false`.
2. `allow_null = false` on the tinyint column — `sanitize_default()` casts `null` default to `(int) null = 0`.

Fix:
1. Use strict `===` in all tri-state comparisons.
2. Ensure `'allow_null' => true` on all tinyint tri-state columns.
3. Verify the REST route `args` declares `'type' => ['boolean', 'null']` for tri-state fields — missing `null` in the type array causes WordPress to coerce JSON `null` to `false` before the sanitize callback runs.

---

## Raw JSON string in REST response

**Symptom**: `mcp_servers` (or similar array column) is returned as a JSON string `"[\"server-1\"]"` instead of an array `["server-1"]`.

**Root cause**: The Row class is not decoding the column in its constructor.

Fix:

```php
public function __construct( $item ) {
    parent::__construct( $item );
    $this->mcp_servers = ( null !== $this->mcp_servers )
        ? json_decode( $this->mcp_servers, true )
        : null;
}
```

---

## Existing table columns not updated after schema change

**Symptom**: After changing a column definition (e.g. adding `allow_null`), existing installs still have the old column definition.

**Root cause**: `maybe_upgrade()` only runs when `$version` has changed.

Fix: bump `$version` in the Table class (e.g. `'1.0.0'` → `'1.0.1'`), then call `maybe_upgrade()` from both the activation hook and `admin_init`.

---

## PHP 7.4 union return type fatal

**Symptom**: Fatal error on activation — `Union types are not supported in PHP 7.4`.

**Root cause**: REST permission callback declared with native `bool|\WP_Error` return type, which requires PHP 8.0+.

Fix: use PHPDoc only, no native union type:

```php
/**
 * @return bool|\WP_Error
 */
public function check_permission( \WP_REST_Request $request ) {
    // no ": bool|\WP_Error" here
}
```

---

## "Invalid parameter(s)" REST 400 before callback runs

**Symptom**: WordPress returns `{"code":"rest_invalid_param","message":"Invalid parameter(s): mcp_type, mcp_servers"}` before your handler runs.

**Root cause**: The `register_rest_route()` `args` array doesn't declare those fields, so WordPress's built-in parameter validation rejects them.

Fix: explicitly declare ALL fields in `args`, including nullable ones with `'type' => ['string', 'null']` or `'type' => ['boolean', 'null']`. WordPress REST API accepts union types in the `type` array for PHP-layer nullability.

```php
'mcp_type' => array(
    'required' => false,
    'type'     => array( 'string', 'null' ),
    'enum'     => array( 'tool', 'resource', 'prompt', null ),
),
'mcp_servers' => array(
    'required' => false,
    'type'     => array( 'array', 'null' ),
    'items'    => array( 'type' => 'string' ),
),
```
