# BerlinDB Query Reference

## Query class setup

```php
class MyQuery extends BerlinDB\Database\Query {
    // MUST use ::class constants — bare strings cause runtime failures
    protected $table_schema = MySchema::class;
    protected $item_shape   = MyRow::class;
    protected $table_name   = 'my_table';   // without WP prefix
}
```

Using bare strings (e.g. `'MySchema'`) instead of `::class` breaks PHPStan L8 type inference and may cause class-not-found errors depending on autoloader state.

---

## `add_item()` — insert a new row

**Return value**: integer ID on success, `false` on failure.

```php
$result = $this->add_item( $data );

// CORRECT success check
if ( false === $result || (int) $result <= 0 ) {
    // insert failed
}
$new_id = (int) $result;

// WRONG — (int) false = 0 which is also a falsy ID; using plain if ($result) is ambiguous
```

### What `add_item()` does internally

1. Merges `$data` with column defaults from the Schema.
2. Calls `reduce_item('insert', $save)` — strips fields where the current user lacks the column's `insert` capability. Default capability is `'exist'` (always true for logged-in users) so no fields are stripped unless you explicitly set restrictive caps.
3. Calls `validate_item($reduce)` — validates each field. **Returns `false` (not an array) if ANY field is `null` and its Column has `allow_null = false`.** This is the most common cause of silent insert failures.
4. If `$save` is non-empty after step 3, calls `$wpdb->insert()`.
5. Returns `$wpdb->insert_id` (integer) or `false`.

### Pre-encoding JSON arrays

BerlinDB does NOT auto-encode PHP arrays. Any array stored in a `longtext` JSON column must be encoded before calling `add_item()`:

```php
if ( isset( $fields['mcp_servers'] ) && is_array( $fields['mcp_servers'] ) ) {
    $fields['mcp_servers'] = wp_json_encode( $fields['mcp_servers'] );
}
$this->add_item( $fields );
```

Without this, `$wpdb->insert()` receives `Array` as a string value — the column is stored as the literal text `"Array"`.

---

## `update_item()` — update an existing row

**Return value**: updated item object on success, `false` on failure.

```php
// CORRECT — first arg is the integer primary key
$result = $this->update_item( $existing->id, $data );
if ( false === $result ) {
    // update failed
}

// WRONG — passing the slug string as the first arg
$result = $this->update_item( $slug, $data );  // ← BerlinDB silently fails to find the row
```

The first argument is always the **integer primary key**, obtained from `$existing->id` on the Row object. The slug (or any other business key) is NOT the primary key.

---

## `delete_item()` — delete a row

**Return value**: `true` on success, `false` on failure.

```php
// CORRECT — integer primary key
$result = $this->delete_item( $existing->id );

// WRONG — slug string
$result = $this->delete_item( $slug );
```

---

## `query()` — retrieve rows

```php
$results = $this->query( array(
    'ability_slug' => $slug,
    'number'       => 1,
) );
```

Returns an array of `$item_shape` objects (or an empty array). Always check `instanceof` before accessing properties if the result might be empty:

```php
if ( empty( $results ) || ! $results[0] instanceof MyRow ) {
    return null;
}
return $results[0];
```

---

## Upsert pattern (get-or-insert + update)

```php
public function save_override( string $slug, array $fields ): bool {
    // 1. JSON-encode any array columns before handing off to BerlinDB
    if ( isset( $fields['mcp_servers'] ) && is_array( $fields['mcp_servers'] ) ) {
        $fields['mcp_servers'] = wp_json_encode( $fields['mcp_servers'] );
    }

    $existing = $this->get_override_by_slug( $slug );
    $now      = current_time( 'mysql', true );
    $user_id  = get_current_user_id();

    if ( null === $existing ) {
        // INSERT path
        $fields['ability_slug'] = $slug;
        $fields['created_at']   = $now;
        $fields['created_by']   = $user_id;
        $fields['updated_at']   = $now;

        $result = $this->add_item( $fields );
        return false !== $result && (int) $result > 0;
    }

    // UPDATE path — first arg is integer PK, NOT the slug
    $fields['updated_at'] = $now;
    $fields['updated_by'] = $user_id;
    unset( $fields['created_at'], $fields['created_by'] ); // preserve original audit fields

    $result = $this->update_item( $existing->id, $fields );
    return false !== $result;
}
```

---

## `validate_item()` internals (why saves silently fail)

```php
// BerlinDB source — Query::validate_item()
foreach ( $item as $key => $value ) {
    $column = $this->get_column_by( array( 'name' => $key ) );

    if ( is_null( $value ) ) {
        if ( false === $column->allow_null ) {
            return false;   // ← aborts entire INSERT/UPDATE, not just this field
        }
    }
    // ...
}
```

When `validate_item()` returns `false`, `add_item()` sees `! empty( $save )` is false (because `$save` is `false`, not an empty array) and returns `false` without calling `$wpdb->insert()`. No database error is thrown — the insert simply doesn't happen.

This is why a missing `allow_null` on a nullable column causes a silent 500: the PHP REST handler gets `false` from `save_override()` and returns a `WP_Error` with status 500, but the DB log shows no failed query.

---

## `reduce_item()` internals (capability-based field filtering)

```php
// BerlinDB source — Query::reduce_item()
foreach ( $item as $key => $value ) {
    $caps = $this->get_column_field( array( 'name' => $key ), 'caps' );
    if ( empty( $caps[ $method ] ) || ! current_user_can( $caps[ $method ] ) ) {
        unset( $item[ $key ] );
    }
}
```

Default column caps (set by `Column::sanitize_capabilities()`):
```php
array(
    'select' => 'exist',
    'insert' => 'exist',
    'update' => 'exist',
    'delete' => 'exist',
)
```

`current_user_can('exist')` returns `true` for any authenticated user, so by default no fields are stripped. Fields are only stripped if you define restrictive caps on a column AND the current user lacks them. In REST API context, the permission callback already validates the user before `save_override()` is called, so `reduce_item()` is never the root cause of missing fields.
