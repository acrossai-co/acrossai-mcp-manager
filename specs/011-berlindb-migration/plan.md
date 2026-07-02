# Implementation Plan: BerlinDB Adoption for Four Internal DB Modules (No Backward Compatibility)

**Branch**: `011-berlindb-migration` | **Date**: 2026-07-02 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/011-berlindb-migration/spec.md`
**Memory synthesis**: [memory-synthesis.md](./memory-synthesis.md) — 5 decisions, 5 architecture constraints, 2 deviations, 3 security constraints, 3 bug patterns selected within budget.
**Governance mode**: `speckit-architecture-guard-governed-plan` inline execution (`/speckit.plan` was not a registered slash command in this session; plan generated inline per the skill's documented fallback).

---

## Summary

Feature 011 replaces the four hand-rolled `dbDelta` wrappers under `includes/Database/{MCPServer, CliAuthLog, OAuthToken, OAuthAudit}` with BerlinDB Core 3.0 subclasses following the sibling plugin `acrossai-abilities-manager`'s Feature 038 pattern. Because the plugin ships to zero live installs (Clarification Q4), the feature is a clean-slate rewrite: table names, `db_version_key` option keys, columns, indexes, public Query API, and Row property names are all free to move to sibling conventions. The caller sweep across `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, `admin/Partials/CliAuthLogListTable.php`, `admin/Partials/MCPServerListTable.php`, `admin/Partials/Settings.php`, and `admin/Partials/ApplicationPasswords.php` lands in the same feature branch — no cross-PR staging.

Two behaviour contracts survive the rename and are enforced at the plan-phase gate level:

1. **SEC-001 atomic-CAS** (spec FR-006, memory synthesis B10): the one-shot CLI auth-code redemption method preserves its `WHERE id = %d AND <completed_at> IS NULL` predicate and its `1 === (int) $wpdb->rows_affected` return contract regardless of method rename.
2. **SHA-256 hashed-column widths** (spec FR-010, memory synthesis S3): every column holding a hashed one-shot credential (`char(64)`) or a PKCE S256 challenge (`char(43)`) retains its exact length even under a schema rewrite. A width-narrowing rename would leak plaintext bits.

Three orthogonal boot-time defenses layer at activation to close the class of "table doesn't exist" bugs that motivated the feature:

- **B14 pre-activation vendor guard** — inherited from DEV4/D15 (`activate_<plugin>` priority 1, `wp_die` on missing `vendor/autoload_packages.php`).
- **In-callback vendor autoload require** — new in Feature 011 (`acrossai_mcp_manager_activate()` calls `require_once __DIR__ . '/vendor/autoload_packages.php'` before requiring the Activator, so BerlinDB Kern base classes and the four Query FQNs autoload cleanly).
- **Phantom-version guard** — new on each of the four Table subclasses (`if ( ! $this->exists() ) { delete_option( $this->db_version_key ); } parent::maybe_upgrade();`), silent per Clarification Q1.

The `DefaultServerSeeder::seed()` extraction moves the current inline `insert_default_server()` logic out of the MCPServer Query class (which becomes a base-class subclass) into a stateless pure service helper (A11/A15 family) at `includes/Database/MCPServer/DefaultServerSeeder.php`, called by `Activator::activate()` immediately after `MCPServerTable::instance()->maybe_upgrade()`.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.1+; JavaScript untouched |
| Primary dependencies | `berlindb/core: ^3.0.0` (already installed via Feature 010, `vendor/berlindb/core/src/Database/Kern/`). No new composer packages. |
| Storage | Four custom DB tables (`acrossai_mcp_servers`, `acrossai_mcp_cli_auth_logs`, `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_audit`), all single-site (`$global = false`), all lifecycle-managed by BerlinDB `maybe_upgrade()`. |
| Testing | PHPUnit for phantom-version guard on each Table subclass, OAuthToken `active_only` filter override, and atomic-CAS `rows_affected` contract. PHPCS WPCS strict + PHPStan L8 across whole plugin (including caller sweep). No JS added. |
| Target platform | WordPress 6.9+; single-site only |
| Project type | WordPress plugin — DB layer refactor + caller sweep |
| Performance goals | No measurable regression at activation time (single install run under270 ms round-trip on local WP) or admin page render latency (list tables hit BerlinDB `query()` which uses the standard object-cache group; no new N+1 patterns introduced). |
| Constraints | Constitution §II (PHPStan L8, PHPCS zero-warning), §III (S3 SHA-256, S4 `$wpdb->prepare`), §VII (per-task DoD gating); memory-synthesis A1, A2, A6, A11, A15; DEV1 (must not widen), DEV4 (must preserve); B1 (leading-`\` FQN), B10 (atomic CAS), B14 (P1 pre-guard); zero edits under `vendor/` |
| Scale / scope | 17 files touched — 12 rewrites (4 modules × Table/Schema/Query/Row) + 1 new `DefaultServerSeeder.php` + 1 `Activator.php` delta + 1 `acrossai-mcp-manager.php` one-line insert + ~2 caller-sweep files per module (~5 caller-sweep files total). Estimated 800–1000 LOC net. |

### Concrete DB naming decisions (deferred by spec FR-001..003)

| Module | `$name` | `$db_version_key` | `$version` | `$table_alias` | `$item_name` | `$item_name_plural` |
|---|---|---|---|---|---|---|
| MCPServer | `acrossai_mcp_servers` | `acrossai_mcp_servers_db_version` | `1.0.0` | `mcps` | `mcp_server` | `mcp_servers` |
| CliAuthLog | `acrossai_mcp_cli_auth_logs` | `acrossai_mcp_cli_auth_logs_db_version` | `1.0.0` | `cal` | `cli_auth_log` | `cli_auth_logs` |
| OAuthToken | `acrossai_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens_db_version` | `1.0.0` | `oat` | `oauth_token` | `oauth_tokens` |
| OAuthAudit | `acrossai_mcp_oauth_audit` | `acrossai_mcp_oauth_audit_db_version` | `1.0.0` | `oaa` | `oauth_audit_event` | `oauth_audit_events` |

Rationale: matches sibling plugin's `<name>_db_version` option-key shape and `1.0.0` semver-start for a fresh install. Table names are pluralized where the row represents an instance (`servers`, `logs`, `tokens`); `oauth_audit` stays singular per current spec convention because "audit" reads as a collective noun. The `db_version_key` names DELIBERATELY DIVERGE from the old option keys (`acrossai_mcp_manager_db_version`, `acrossai_mcp_cli_auth_log_db_version`) — Feature 011's compat drop authorizes this.

### Concrete column decisions

Per spec FR-002 + FR-010 + spec Key Entities, each Schema declares:

**MCPServer** (13 columns): `id` (bigint 20 unsigned auto_increment, PK), `server_name` (varchar 255), `server_slug` (varchar 255, default '', sortable+searchable), `description` (varchar 500, default ''), `is_enabled` (tinyint 1, default 0), `registered_from` (varchar 50, default 'plugin'), `server_route_namespace` (varchar 100, default 'mcp'), `server_route` (varchar 255, default ''), `server_version` (varchar 50, default 'v1.0.0'), `claude_connector_client_id` (varchar 255, default ''), `claude_connector_client_secret` (varchar 255, default ''), `claude_connector_redirect_uri` (varchar 500, default ''), `created_at` (datetime, default `CURRENT_TIMESTAMP`, sortable+date_query). Indexes: `primary` on `id`; `key server_slug` on `server_slug`.

**CliAuthLog** (15 columns): `id`, `server_id` (bigint 20 unsigned), `server_slug` (varchar 255, default ''), `user_id` (bigint 20 unsigned), `status` (varchar 32), `failure_code` (varchar 64, default '', nullable), `auth_code_hash` (**char 64** — FR-010 SHA-256 invariant), `app_password_uuid` (varchar 36, default '', nullable), `redirect_uri` (varchar 500, default ''), `code_challenge` (**char 43** — FR-010 PKCE S256 invariant), `code_challenge_method` (varchar 16), `scope` (varchar 255, default ''), `approved_at` (datetime, nullable), `completed_at` (datetime, nullable), `created_at` (datetime, default `CURRENT_TIMESTAMP`, sortable+date_query). Indexes: `primary` on `id`; `unique auth_code_hash` on `auth_code_hash`; `key server_created` on `(server_id, created_at)`; `key server_status_created` on `(server_id, status, created_at)`.

**OAuthToken** (9 columns): `id`, `access_token_hash` (**char 64** — FR-010 SHA-256 invariant), `server_id`, `user_id`, `issued_from_code_id` (bigint 20 unsigned), `scope` (varchar 64, default 'mcp'), `created_at` (datetime, default `CURRENT_TIMESTAMP`, sortable+date_query), `expires_at` (datetime), `revoked_at` (datetime, nullable). Indexes: `primary` on `id`; `unique access_token_hash`; `key server_expires` on `(server_id, expires_at)`; `key user_created` on `(user_id, created_at)`; `key issued_from_code` on `issued_from_code_id`.

**OAuthAudit** (9 columns): `id`, `event_type` (varchar 64), `server_id`, `user_id`, `client_id` (varchar 255), `token_hash_prefix` (char 8), `endpoint` (varchar 255), `details_json` (text, nullable), `created_at` (datetime, default `CURRENT_TIMESTAMP`, sortable+date_query). Indexes: `primary` on `id`; `key event_created` on `(event_type, created_at)`; `key server_created` on `(server_id, created_at)`; `key user_created` on `(user_id, created_at)`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Requirement | This feature |
|---|---|---|
| I. Modular Architecture | Each feature is self-contained; shared logic in `includes/Utilities/` | ✅ Four `includes/Database/<Module>/` subdirs remain self-contained; no shared logic added |
| II. WordPress Standards | PHPCS zero-warning, PHPStan L8, escape at render, sanitize at boundary | ✅ Every task ends on green PHPCS + PHPStan (§VII per-task gate); no user input touched — this is a DB layer, callers do sanitization |
| III. Security First (NON-NEGOTIABLE) | Nonce, capability, `$wpdb->prepare`, SHA-256 tokens, no `__return_true` on mutating routes | ✅ FR-006 preserves atomic-CAS + `$wpdb->prepare`; FR-010 preserves SHA-256 column widths; no new REST or admin surface; no nonce/capability changes |
| IV. User-Centric Design | New forms use DataForm; new lists use DataViews; DEV1 exception for MCP Manager parent menu + list-table classes | ✅ No new admin UI; spec FR-021 explicitly forbids drive-by DataViews conversion of the swept `admin/Partials/CliAuthLogListTable.php` |
| V. Extensibility Without Core Modification | Hooks/extension points only; degrade if optional integration absent | ✅ No hooks added; no vendor files touched; `berlindb/core` optionality is not exercised (it's a required declared dep since Feature 010) |
| VI. Reusability & DRY | Shared logic in `includes/Utilities/`; existing utilities reused before new ones | ✅ `DefaultServerSeeder` is a per-module helper, not shared — correct placement per A15 |
| VII. Definition of Done | PHPCS, PHPStan L8, ESLint, security review, tests, DataForm/DataViews, DRY, `acrossai_mcp_` prefix, AGENTS.md, `npm run validate-packages` | ✅ All applicable gates apply per task; no JS in this feature so ESLint is trivially green |

**Result: PASS** — no constitution violations detected. Complexity Tracking section left empty.

## Project Structure

### Documentation (this feature)

```text
specs/011-berlindb-migration/
├── spec.md                    # Feature spec (rewritten 2026-07-02 for no-compat scope)
├── plan.md                    # This file
├── memory-synthesis.md        # Memory-first synthesis (refreshed 2026-07-02)
├── security-constraints.md    # Phase-gate output (generated by governed-plan Step 4)
├── architecture-violations.md # Phase-gate output (generated by governed-plan Step 5)
├── checklists/
│   └── requirements.md        # Spec quality checklist
└── tasks.md                   # Phase 2 output (/speckit-tasks, not created by this command)
```

### Source Code (repository root)

```text
acrossai-mcp-manager.php                # 1 line added inside acrossai_mcp_manager_activate()
includes/
├── Activator.php                       # delta edit: 4 use-imports + 4 maybe_upgrade calls + DefaultServerSeeder::seed()
├── Database/
│   ├── MCPServer/
│   │   ├── Table.php                   # rewrite → extends \BerlinDB\Database\Kern\Table
│   │   ├── Schema.php                  # rewrite → extends \BerlinDB\Database\Kern\Schema
│   │   ├── Query.php                   # rewrite → extends \BerlinDB\Database\Kern\Query
│   │   ├── Row.php                     # rewrite → extends \BerlinDB\Database\Kern\Row
│   │   └── DefaultServerSeeder.php     # NEW — stateless pure service (A11/A15)
│   ├── CliAuthLog/
│   │   ├── Table.php                   # rewrite
│   │   ├── Schema.php                  # rewrite
│   │   ├── Query.php                   # rewrite — preserves redeem_atomic + delete_expired_oauth_codes semantics
│   │   └── Row.php                     # rewrite
│   ├── OAuthToken/
│   │   ├── Table.php                   # rewrite
│   │   ├── Schema.php                  # rewrite
│   │   ├── Query.php                   # rewrite — active_only PHP filter override
│   │   └── Row.php                     # rewrite
│   ├── OAuthAudit/
│   │   ├── Table.php                   # rewrite
│   │   ├── Schema.php                  # rewrite
│   │   ├── Query.php                   # rewrite — preserves delete_older_than semantics
│   │   └── Row.php                     # rewrite
│   ├── CliAuthLog/Recorder.php         # caller-sweep edit
│   ├── OAuth/                          # caller-sweep edits (all files)
│   ├── REST/CliController.php          # caller-sweep edit
│   └── MCP/Controller.php              # caller-sweep edit
admin/
└── Partials/
    ├── CliAuthLogListTable.php         # caller-sweep edit (renames only — DEV1 boundary preserved)
    ├── MCPServerListTable.php          # caller-sweep edit (renames only — same DEV1 boundary as CliAuthLogListTable)
    ├── Settings.php                    # caller-sweep edit (7 `new Query()` → `Query::instance()` conversions)
    └── ApplicationPasswords.php        # caller-sweep edit (1 `new Query()` → `Query::instance()` conversion)
tests/
└── phpunit/
    └── Database/
        ├── PhantomVersionGuardTest.php # NEW — verifies guard fires on missing table
        ├── AtomicCasTest.php           # NEW — verifies SEC-001 rows_affected contract
        └── ActiveOnlyFilterTest.php    # NEW — verifies OAuthToken active_only PHP filter
docs/
├── memory/
│   ├── DECISIONS.md                    # D9 + D7 → Superseded (Feature 011); D4 annotated
│   ├── WORKLOG.md                      # Feature 011 milestone entry
│   └── INDEX.md                        # rows updated
├── planings-tasks/
│   └── README.md                       # append 011-berlindb-migration.md row
README.txt                              # Unreleased changelog bullet
```

**Structure Decision**: retain the current plugin's `includes/Database/<Module>/<Class>.php` subdir-per-module layout with unprefixed class names — this differs from the sibling plugin's flat `Database/AcrossAI_<Module>_<Class>.php` shape, but changing to the sibling's convention would ripple across every existing caller import for zero functional gain. FR-001's "sibling-plugin convention" mandate is interpreted as **naming shape of protected properties + method signatures + phantom-version-guard body**, not directory layout.

## Task Groups (Phase 2 preview)

Task decomposition is `/speckit-tasks` territory, but the plan-time expected task boundaries mirror the user's original 8-task sketch (adapted to the no-compat scope):

| Group | Files touched | Gate |
|---|---|---|
| **T1 — Vendor autoload timing fix** | `acrossai-mcp-manager.php` only | PHPStan L8 + PHPCS green on the one changed file |
| **T2 — MCPServer BerlinDB rewrite + DefaultServerSeeder + Activator MCPServer delta** | 4 module files + 1 new seeder + Activator delta | PHPStan L8 + PHPCS + activation smoke test creates `acrossai_mcp_servers` table with default row |
| **T3 — CliAuthLog BerlinDB rewrite + Activator delta** | 4 module files + Activator delta | Above + `AtomicCasTest` passes |
| **T4 — OAuthToken BerlinDB rewrite + Activator delta** | 4 module files + Activator delta | Above + `ActiveOnlyFilterTest` passes |
| **T5 — OAuthAudit BerlinDB rewrite + Activator delta** | 4 module files + Activator delta | PHPStan L8 + PHPCS green |
| **T6 — Final Activator cleanup + PhantomVersionGuardTest** | Activator sanity pass + new test file | `grep '\bmaybe_create_table\b'` returns zero; `PhantomVersionGuardTest` passes |
| **T7 — Caller sweep (batched by module)** | `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, `admin/Partials/CliAuthLogListTable.php`, `admin/Partials/MCPServerListTable.php`, `admin/Partials/Settings.php`, `admin/Partials/ApplicationPasswords.php` | PHPStan L8 + PHPCS green across whole plugin; DEV1 boundary check on BOTH list-table files (`grep -c 'extends WP_List_Table'` returns 1 on each; DataViews-import grep returns 0 on both) |
| **T8 — Memory hygiene + changelog** | `README.txt`, `docs/memory/{DECISIONS,WORKLOG,INDEX}.md`, `docs/planings-tasks/README.md` | INDEX rows match DECISIONS statuses; D9 + D7 marked Superseded (Feature 011) |

Each task ends on the constitution §VII per-task DoD gate. `/speckit-tasks` will refine this into numbered TASK-XXX entries with acceptance criteria per task.

## Constitution Re-check (post-Phase-1 design)

Design decisions above do not introduce new constitution violations:

- No new hooks → A1 unaffected
- No new singleton classes without `instance(): self` → A2 satisfied
- No non-Utilities shared logic → §VI unchanged; `DefaultServerSeeder` is single-consumer per-module, correct placement per A15
- No new admin UI → §IV unchanged; DEV1 explicitly preserved by FR-021
- No new REST routes → S2 unaffected
- SEC-001 atomic-CAS + SHA-256 widths → FR-006 + FR-010 preserve invariants

**Result: PASS on second gate.**

## Complexity Tracking

No constitution violations to justify.
