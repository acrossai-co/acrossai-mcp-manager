# BerlinDB Schema & Table Reference

## Schema class — column definition keys

BerlinDB `Column::parse_args()` accepts these keys (excerpt of the most-used):

| Key | Type | Default | Notes |
|---|---|---|---|
| `name` | string | `''` | Column name in DB |
| `type` | string | `''` | MySQL type: `bigint`, `tinyint`, `varchar`, `longtext`, `datetime` |
| `length` | int | `''` | Column length (e.g. `'20'`, `'255'`, `'1'`) |
| `unsigned` | bool | `false` | `UNSIGNED` modifier |
| `allow_null` | bool | **`false`** | Whether `NULL` is a valid value — **use `allow_null`, NOT `null`** |
| `default` | mixed | `''` | Column default; pass `null` only when `allow_null => true` |
| `primary` | bool | `false` | Mark as PRIMARY KEY |
| `extra` | string | `''` | MySQL extra clause (e.g. `'auto_increment'`) |
| `created` | bool | `false` | Auto-fill with current time on INSERT |
| `modified` | bool | `false` | Auto-fill with current time on INSERT and UPDATE |
| `sortable` | bool | `false` | Allow ordering by this column in `Query::query()` |
| `searchable` | bool | `false` | Include in full-text search |
| `date_query` | bool | `false` | Enable date_query support for this column |

### The `allow_null` vs `null` gotcha

`Column::parse_args()` uses `wp_parse_args( $args, $defaults )` where `$defaults` includes `'allow_null' => false`.

- Passing `'null' => true` in your column array: **silently ignored** — `wp_parse_args` does not treat `null` as an alias for `allow_null`. The Column object keeps `$allow_null = false`.
- Result: `validate_item()` returns `false` when any null value is encountered, aborting the INSERT/UPDATE.
- Secondary result: `sanitize_default()` casts a `null` default to `0` for numeric types when `allow_null = false`.

**Always use `'allow_null' => true`**.

### Tri-state tinyint columns

For columns that represent a tri-state (Yes / No / Inherit = NULL):

```php
array(
    'name'       => 'site_allowed',
    'type'       => 'tinyint',
    'length'     => '1',
    'allow_null' => true,   // allow SQL NULL (Inherit state)
    'default'    => null,   // default is Inherit
),
```

DB values: `1` = Yes, `0` = No, `NULL` = Inherit. Never store any other value.

### Nullable varchar / longtext columns

```php
array(
    'name'       => 'mcp_type',
    'type'       => 'varchar',
    'length'     => '100',
    'allow_null' => true,
    'default'    => null,
),
array(
    'name'       => 'mcp_servers',
    'type'       => 'longtext',
    'allow_null' => true,
    'default'    => null,
),
```

### Datetime columns (audit timestamps)

```php
array(
    'name'       => 'created_at',
    'type'       => 'datetime',
    'allow_null' => false,
    'default'    => 'CURRENT_TIMESTAMP',
    'created'    => true,     // auto-filled on INSERT
    'date_query' => true,
    'sortable'   => true,
),
array(
    'name'       => 'updated_at',
    'type'       => 'datetime',
    'allow_null' => false,
    'default'    => 'CURRENT_TIMESTAMP',
    'modified'   => true,     // auto-filled on INSERT and UPDATE
    'date_query' => true,
    'sortable'   => true,
),
```

### Nullable bigint (user ID audit columns)

```php
array(
    'name'       => 'created_by',
    'type'       => 'bigint',
    'length'     => '20',
    'unsigned'   => true,
    'allow_null' => true,
    'default'    => null,
),
```

---

## Table class

```php
class MyTable extends BerlinDB\Database\Table {
    protected $name    = 'my_table';       // without WP table prefix ($wpdb->prefix added automatically)
    protected $version = '1.0.0';          // bump to trigger ALTER on existing installs
    protected $schema  = MySchema::class;  // PHP Schema class (for Column objects)

    protected function set_schema(): void {
        // SQL DDL — used to CREATE TABLE and ALTER TABLE
        // This is independent of the PHP Schema class above.
        // Both must agree on nullable columns — a mismatch causes the PHP validation
        // to reject values that the DB would happily accept.
        $this->schema = "
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ability_slug varchar(255) NOT NULL DEFAULT '',
            site_allowed tinyint(1) DEFAULT NULL,
            mcp_servers longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ability_slug (ability_slug)
        ";
    }
}
```

### Upgrading existing tables

When column definitions change (e.g. adding a column or changing nullability):

1. Bump `$version` (e.g. `'1.0.0'` → `'1.0.1'`).
2. BerlinDB stores the current version in a site option keyed on the table name.
3. `maybe_upgrade()` compares stored vs. current version and runs `ALTER TABLE` when they differ.
4. Call `( new MyTable() )->maybe_upgrade()` from the plugin activation hook AND from `admin_init` to handle upgrades on existing installs.

```php
// In activation hook
( new MyTable() )->maybe_upgrade();
```

**Important**: the SQL DDL and the PHP Schema class are separate. The SQL DDL controls the actual DB column definition. The PHP Schema controls BerlinDB's PHP-layer validation (`validate_item`). If the SQL allows NULL but the PHP Schema has `allow_null = false`, inserts of null values will fail at the PHP layer before reaching the DB.
