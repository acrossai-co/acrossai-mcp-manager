# BerlinDB Row Reference

## Row class basics

```php
class MyRow extends BerlinDB\Database\Row {

    public int     $id;
    public string  $ability_slug;
    public ?bool   $site_allowed  = null;
    public ?bool   $readonly      = null;
    public ?bool   $destructive   = null;
    public ?string $mcp_type      = null;
    public ?string $mcp_servers   = null;  // stored as JSON, decoded in constructor
    public ?string $created_at    = null;
    public ?string $updated_at    = null;
    public ?int    $created_by    = null;
    public ?int    $updated_by    = null;

    public function __construct( $item ) {
        parent::__construct( $item );

        // Cast tinyint columns to PHP bool|null
        $this->site_allowed = MyPlugin_Sanitizer::cast_tri_state( $this->site_allowed );
        $this->readonly     = MyPlugin_Sanitizer::cast_tri_state( $this->readonly );
        $this->destructive  = MyPlugin_Sanitizer::cast_tri_state( $this->destructive );

        // Decode JSON longtext columns
        $this->mcp_servers = ( null !== $this->mcp_servers )
            ? json_decode( $this->mcp_servers, true )
            : null;
    }
}
```

---

## Why you must cast tinyint values manually

MySQL returns tinyint column values as strings (`"0"`, `"1"`) or PHP `null` (for SQL `NULL`). BerlinDB does not cast column values — it passes the raw row object directly to the Row constructor.

Without casting:
- `$row->site_allowed` is `"0"`, `"1"`, or `null`
- `"0"` is truthy in PHP (`! "0"` is `false`), so `if ( $row->site_allowed )` does not work correctly

With tri-state casting:

```php
public static function cast_tri_state( $value ): ?bool {
    if ( 1 === $value || '1' === $value ) {
        return true;
    }
    if ( 0 === $value || '0' === $value ) {
        return false;
    }
    return null;   // SQL NULL → PHP null (Inherit)
}
```

**Critical**: use strict `===` comparisons. PHP loose equality treats `null == false` as `true`, conflating "no override" (null) with "explicit No override" (false). This silently corrupts governance semantics.

**Do not duplicate `cast_tri_state` on the Row class.** Define it once as a shared static utility (e.g. `AcrossAI_Sanitizer::cast_tri_state()`). RF-02: single source of truth for casting logic.

---

## Why you must decode JSON columns manually

BerlinDB does not inspect column types on read — it passes raw DB values as-is. A `longtext` column that stores JSON is returned as a PHP string.

```php
// In the Row constructor — required for any JSON column
$this->mcp_servers = ( null !== $this->mcp_servers )
    ? json_decode( $this->mcp_servers, true )
    : null;
```

Without this:
- REST responses return `"{\"id\":\"server-1\"}"` (a string) instead of `[{"id":"server-1"}]` (an array)
- JS client receives the wrong type and JSON.parse may double-decode

---

## Tri-state semantics

| DB value | PHP type after cast | Meaning |
|---|---|---|
| `1` | `true` | Explicitly set to Yes |
| `0` | `false` | Explicitly set to No |
| `NULL` | `null` | Inherit — no override for this field |

The distinction between `false` (explicit No) and `null` (Inherit) is load-bearing. In a governance system, "I explicitly disallowed this" is different from "I haven't touched this setting." Losing this distinction silently converts admin decisions into "no opinion."

**Never use `!empty()` or `!!` to check tri-state values** — both collapse `null` and `false` into the same falsy bucket.

```php
// WRONG — treats Inherit (null) the same as No (false)
if ( ! empty( $override->site_allowed ) ) { ... }
if ( !! $override->site_allowed ) { ... }

// CORRECT
if ( null !== $override->site_allowed ) { ... }  // "has any explicit override"
if ( false === $override->site_allowed ) { ... } // "explicitly set to No"
if ( true === $override->site_allowed )  { ... } // "explicitly set to Yes"
```

---

## JSON column encode/decode contract

| Direction | Where | Operation |
|---|---|---|
| Write (INSERT/UPDATE) | `Query::save_override()` | `wp_json_encode( $array )` before passing to BerlinDB |
| Read | `Row::__construct()` | `json_decode( $string, true )` in the constructor |

Always use `wp_json_encode()` (not `json_encode()`) on write — it handles unicode and uses WordPress-standard encoding options.

Always pass `true` as the second argument to `json_decode()` — you want an associative array, not a `stdClass` object.
