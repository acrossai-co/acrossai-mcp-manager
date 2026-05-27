---
name: berlindb
description: "Use when implementing custom database tables in WordPress plugins with berlindb/core. Covers Schema/Table/Row/Query class wiring, the allow_null gotcha, upsert return-value handling, JSON column encoding, tri-state tinyint columns, and all failure modes discovered in production use."
compatibility: "berlindb/core ^2.0, WordPress 6.0+, PHP 7.4+"
---

# BerlinDB (`berlindb/core`)

## When to use

Apply this skill whenever you are working with `berlindb/core` in a WordPress plugin:

- defining a new custom table (Schema + Table)
- writing a Row class with typed properties
- writing a Query class with `add_item` / `update_item` / `delete_item`
- debugging silent INSERT/UPDATE failures (500 errors, `save_override` returning false)
- storing JSON arrays (e.g. server lists, metadata arrays) in a longtext column
- implementing tri-state columns (`1` = Yes, `0` = No, `NULL` = Inherit)

## Procedure

### 1) Install and namespace via Mozart

```bash
composer require berlindb/core:^2.0
composer exec mozart compose   # prefix BerlinDB under your plugin namespace
composer dump-autoload
```

After Mozart, BerlinDB classes live at e.g. `YourPlugin\Vendor\BerlinDB\Database\{Schema,Table,Row,Query}`.

Use `BerlinDB\Database\{Schema,...}` in `use` statements — Mozart rewrites the namespace automatically.

### 2) Define the Schema class — critical `allow_null` rule

Extend `BerlinDB\Database\Schema` and populate `$columns`.

**The single most common gotcha**: nullable columns MUST use `'allow_null' => true`, NOT `'null' => true`.

`Column::parse_args()` only recognises `allow_null` as a key. Passing `'null' => true` is silently ignored by `wp_parse_args` — the column keeps `allow_null = false`. Then `validate_item()` returns `false` the moment any null value appears in the data array, and the entire INSERT/UPDATE is aborted without any error message.

```php
// WRONG — 'null' is not a BerlinDB Column key; silently ignored
array(
    'name'    => 'site_allowed',
    'type'    => 'tinyint',
    'length'  => '1',
    'null'    => true,       // ← has NO effect
    'default' => null,
),

// CORRECT
array(
    'name'       => 'site_allowed',
    'type'       => 'tinyint',
    'length'     => '1',
    'allow_null' => true,    // ← BerlinDB Column property name
    'default'    => null,
),
```

Apply `'allow_null' => true` + `'default' => null` to every nullable column: tri-state tinyints, nullable varchars, longtext, and audit user-ID bigints.

**Secondary effect of the bug**: when `allow_null = false` and `default = null`, `Column::sanitize_default()` falls through and casts the default to `(int) null = 0` for numeric types. A tinyint column that should default to `NULL` silently defaults to `0` — making "Inherit" and "No" indistinguishable in the DB.

See: `references/schema.md`

### 3) Define the Table class

```php
class MyTable extends BerlinDB\Database\Table {
    protected $name    = 'my_table';        // without WP prefix
    protected $version = '1.0.0';           // bump to trigger ALTER on existing tables
    protected $schema  = MySchema::class;

    protected function set_schema(): void {
        $this->schema = "
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ability_slug varchar(255) NOT NULL DEFAULT '',
            site_allowed tinyint(1) DEFAULT NULL,
            ...
            PRIMARY KEY (id)
        ";
    }
}
```

- `set_schema()` defines the *SQL DDL* used to CREATE/ALTER the table. This is independent of the PHP Schema class — you can have correct SQL while the PHP validation still fails if the Schema class has wrong `allow_null` values.
- Bump `$version` whenever column definitions change so `maybe_upgrade()` runs `ALTER TABLE` on existing installs.
- Call `( new MyTable() )->maybe_upgrade()` in your plugin's activation hook.

See: `references/schema.md`

### 4) Define the Row class

```php
class MyRow extends BerlinDB\Database\Row {
    public int    $id;
    public string $ability_slug;
    public ?bool  $site_allowed = null;
    public ?string $mcp_servers = null;

    public function __construct( $item ) {
        parent::__construct( $item );

        // Cast tinyint DB values to PHP bool|null — BerlinDB returns raw strings from MySQL
        $this->site_allowed = AcrossAI_Sanitizer::cast_tri_state( $this->site_allowed );

        // JSON-decode longtext columns — BerlinDB does NOT auto-decode
        $this->mcp_servers = ( null !== $this->mcp_servers )
            ? json_decode( $this->mcp_servers, true )
            : null;
    }
}
```

Key points:
- BerlinDB passes raw MySQL strings to the Row constructor — `tinyint(1)` columns come back as `"0"`, `"1"`, or `null`, not PHP booleans.
- JSON longtext columns come back as a raw JSON string — decode them in the constructor.
- Use a shared utility (`cast_tri_state`) for tri-state casting, not a private method on the Row (DRY).

See: `references/row.md`

### 5) Define the Query class — upsert patterns and return values

```php
class MyQuery extends BerlinDB\Database\Query {
    protected $table_schema = MySchema::class;  // MUST be ::class, not a string
    protected $item_shape   = MyRow::class;     // MUST be ::class, not a string
    protected $table_name   = 'my_table';
}
```

**`add_item()` return value**: returns the new **integer ID** on success, `false` on failure.
- Check: `$result !== false && (int) $result > 0`
- Do NOT check with `if ( $result )` — ID `0` is falsy but means "invalid ID", while `false` means "insert failed".

**`update_item()` return value**: returns the updated **item object** on success, `false` on failure.
- First argument MUST be the **integer primary key** (`$existing->id`), NOT the slug string.
- Check: `$result !== false`

**`delete_item()` return value**: returns `true` or `false`.
- First argument MUST be the **integer primary key** (`$existing->id`), NOT the slug string.

**JSON encoding before write**: BerlinDB does NOT auto-encode PHP arrays. Any column stored as JSON (longtext) must be encoded before passing to `add_item` or `update_item`:

```php
if ( isset( $fields['mcp_servers'] ) && is_array( $fields['mcp_servers'] ) ) {
    $fields['mcp_servers'] = wp_json_encode( $fields['mcp_servers'] );
}
$this->add_item( $fields );
```

See: `references/query.md`

### 6) Wire everything up

```php
// Activation hook
( new MyTable() )->maybe_upgrade();

// Module / controller
$query = new MyQuery();
$query->add_item( $data );
$query->update_item( $existing->id, $data );
$query->delete_item( $existing->id );
```

## Pre-ship checklist

- [ ] All nullable columns use `'allow_null' => true` (not `'null' => true`) in the Schema class
- [ ] All nullable columns also set `'default' => null`
- [ ] `$table_schema` and `$item_shape` in the Query class use `::class` constants
- [ ] `add_item()` success is checked with `!== false && (int) $result > 0`
- [ ] `update_item()` first arg is the integer PK from `$existing->id`
- [ ] JSON array columns are `wp_json_encode()`'d before write and `json_decode()`'d in the Row constructor
- [ ] Tri-state tinyint values are cast with a shared `cast_tri_state()` utility in the Row constructor
- [ ] `maybe_upgrade()` is called in the plugin activation hook
- [ ] `$version` is bumped whenever column definitions change

## Failure modes / debugging

See `references/debugging.md` for the full failure catalogue.

**Silent INSERT failure (save returns false, REST 500)** — most common cause:
A nullable column has `'null' => true` instead of `'allow_null' => true` in the Schema class. `validate_item()` sees `allow_null = false` on the Column object and returns `false` when it encounters any null value, aborting the entire insert. Fix: replace all `'null' => true` with `'allow_null' => true`.

**Wrong primary key type in update/delete** — second most common:
Passing the slug string as the first argument to `update_item()` or `delete_item()` instead of `$existing->id` (integer). BerlinDB silently fails to find the row.

**Raw JSON string in REST response** — forgot to decode in Row constructor:
`mcp_servers` (or any JSON longtext column) is returned as a raw JSON string instead of a PHP array. Fix: add `json_decode()` in the Row constructor.

**Tri-state Inherit === No (both stored as 0)** — `null` default cast to `0`:
Caused by `allow_null = false` on a tinyint column with `default = null` — `sanitize_default()` casts `null` to `(int) null = 0`. Fix: `'allow_null' => true`.

**Existing table not updated after schema change** — version not bumped:
`maybe_upgrade()` only runs ALTER when `$version` has changed. Bump the version string whenever you change a column.
