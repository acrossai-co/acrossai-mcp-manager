# Security Review — Plan-Level Constraints

**Reviewed plan**: `specs/011-berlindb-migration/plan.md`
**Reviewed spec**: `specs/011-berlindb-migration/spec.md`
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-07-02
**Reviewer**: governed-plan orchestrator (inline `speckit-security-review-plan` fallback)

---

## Scope

Feature 011 is a DB-layer refactor + caller sweep. It has **no forms, no REST route additions, no user input surface** — every existing REST/CLI route is unchanged in HTTP contract (governed by Feature 006 and out of scope per plan §Constitution Check). The security-relevant work is entirely about **preserving invariants that pre-date this feature**:

1. **SEC-001 atomic-CAS** for one-shot CLI auth-code redemption (spec FR-006).
2. **SHA-256 hashed-column widths** — every column holding a hashed one-shot credential or hashed access token (spec FR-010, memory-synthesis S3).
3. **PKCE S256 challenge-column width** — `char(43)` invariant (spec FR-010).
4. **`$wpdb->prepare()` on every DB path** — no raw interpolation introduced by the rewrite (memory-synthesis S4).
5. **`register_activation_hook` P1 pre-guard on missing vendor** — DEV4/D15/B14 chain (spec FR-012).

Because the plugin ships to **zero live installs** (spec Clarifications Q4), there is no data-preservation attack surface, no operator-visible upgrade migration to spoof, and no legacy-data quarantine question.

---

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| WordPress activation hook → plugin bootstrap | inbound at activation-time | If `vendor/autoload_packages.php` is missing when `acrossai_mcp_manager_activate()` runs, the class-level `use` imports in `Activator.php` resolve BerlinDB Kern FQNs against no autoloader and the PHP process fatals with `class not found`. This is an activation-time DoS, not a data-integrity threat, but it produces an operator-visible fatal that can panic-drive downgrades. | Layered: (a) **P1 pre-guard** on `activate_<plugin>` `wp_die`s with a clear message if the file is absent (DEV4 / spec FR-012); (b) **in-callback `require_once`** loads the autoloader before the Activator itself loads (spec FR-011); (c) BerlinDB Kern classes cannot be resolved until the autoloader is registered, so a bypass of (a) still fails cleanly instead of executing partial init. |
| CLI auth-code redemption flow → CliAuthLog Query (`redeem_atomic` or renamed equivalent) | inbound at request-time | Concurrent HTTP hits on the same one-shot auth code. If the redemption is not atomic (e.g., `SELECT ... IF empty THEN UPDATE`), two callers can both observe `completed_at IS NULL` and both proceed to issue tokens — a critical duplicate-redemption bug (BUGS.md B10). | Spec FR-006 makes the atomic-CAS semantic contract a hard invariant: `WHERE id = %d AND <completed_at> IS NULL` in a single `UPDATE` statement, `1 === (int) $wpdb->rows_affected` as the sole truthiness signal. PHPUnit gate at plan `AtomicCasTest.php` seeds one row and invokes the method twice — expects exactly one `true` and one falsy return. Rename is allowed, semantic drift is not. |
| Column-storage layer → hashed-credential columns | at-rest | Storing SHA-256 hashes in a column narrower than 64 hex chars silently truncates the hash. Truncated hashes are (a) trivially collidable and (b) no longer indistinguishable from arbitrary input — either failure mode compromises the one-shot code redemption or the token authentication. | Spec FR-010 fixes `char(64)` on both the auth-code hash column and the access-token hash column, and `char(43)` on the PKCE S256 challenge column. PHPCS + PHPStan cannot catch column-width drift by themselves; the schema-column widths are enforced by code review + the sibling-plugin reference pattern. |
| Column-storage layer → `token_hash_prefix` (OAuthAudit) | at-rest | The 8-char prefix is intentionally a **truncated** hash used for search only — never for authentication. A width change here is not the same as the FR-010 invariant. | `char(8)` is preserved from the current schema. Documented as a debug/search field, not a credential. |
| Plugin runtime → BerlinDB Kern base classes | inbound at request-time (indirect) | BerlinDB Core is a vendored package; a compromise of the package could poison every `Query::query()` / `add_item()` / `add_item()` return. | Package integrity is the responsibility of Feature 010's supply-chain gates (`composer.lock` + `git diff --exit-code vendor/`). Feature 011 does not modify the vendor tree. |
| Feature 011 caller sweep → user-input write paths | inbound at request-time (indirect) | The caller sweep touches files that may already route user input into `add_item()`/`update_item()`. If a swept caller starts passing an unfiltered assoc array to BerlinDB's `add_item`, the base Query does NOT filter against schema columns — BUGS.md B7 (mass-assignment via forged POST keys). | Spec Security Checklist item explicitly forbids this. Callers writing user input MUST filter to `Schema::columns()` before calling `add_item`/`update_item`. Plan §Task Groups T7 acceptance criteria include a B7 audit pass. |

---

## Authorization Assumptions

Feature 011 introduces zero new capability checks, permission callbacks, or nonces. It preserves:

- **REST `permission_callback` on every mutating route** — governed by Feature 006 (`includes/REST/CliController.php`) and not changed by this feature.
- **`current_user_can( 'manage_options' )` on every admin page render** — governed by `admin/Partials/Menu.php` / `admin/Partials/Settings.php` and not changed by this feature.
- **Consent-surface exception** (Feature 007 §III amendment) — Feature 011 does not touch `public/Partials/FrontendAuth.php` or the consent flow.
- **`class_exists( '\WP\MCP\Plugin' )` guard** — Feature 009's MCP Controller guard preserved (this feature does NOT touch `includes/MCP/Controller.php` semantically — the caller sweep touches only Row property references and Query method names inside the file).
- **Application Password authentication path** — the CLI auth-code flow's hashed-code column preservation (FR-010) is the load-bearing security invariant for the App-Password issuance surface.

---

## Data Isolation & Validation Risks

- **Fresh install premise** — spec Clarification Q4 authorizes free renames because no live installs exist. This premise is trust-critical: if a live install is discovered after merge, the activation lifecycle will treat the pre-migration tables as unrelated and either (a) leave them orphaned (data leak — old rows persist untracked) or (b) fresh-install a parallel schema (state divergence). Merge gate: verify with the user before ship that no site has actually installed a Feature-002 build to production.
- **`activate_only` PHP filter** (spec FR-008) — the post-`parent::query()` filter is a PHP-side predicate. If a future caller starts combining `active_only` with pagination-bearing arguments (`per_page`, `paged`, `number`), the filter will silently return fewer rows than the pagination boundary claims (the filter runs after LIMIT). This is a correctness risk, not a security risk — but it can produce operator-visible surprise when audits go missing. Spec Assumption already documents the current-caller matrix.
- **`DefaultServerSeeder::seed()` idempotency** — the SELECT-COUNT-first guard is a data-integrity invariant. If it regresses (e.g., someone removes the guard "because BerlinDB handles inserts"), every activation would re-insert the default row and produce duplicates on `server_slug`. The seeder's PHPUnit test (implicit in the plan; add to task list if not already) MUST verify double-invocation is a no-op.

---

## Async / Concurrency Security Context

- **Concurrent redemption of a one-shot auth code** — covered above under Trust Boundaries. This is the concurrency risk that motivated SEC-001. Plan gate is `AtomicCasTest.php`.
- **Concurrent activation** — WordPress serializes activation hooks per plugin per site; there is no realistic concurrent-activation race. Not analyzed further.
- **Concurrent OAuth token issuance** — governed by `unique` index on `access_token_hash`. Two callers cannot successfully insert the same hashed token; the second gets a DB unique-violation error. Preserved by spec plan-decision `unique access_token_hash` index.
- **Concurrent audit-log write** — no atomicity requirement; audit rows are append-only and unordered.

---

## Missing Gates / Recommendations

- **RECOMMEND — production-install audit before merge**: verify with the user that no site outside `~/local-sites/` runs this plugin against real data. If any live install exists, the compat-drop premise (Clarification Q4) is broken and the spec needs another round.
- **RECOMMEND — reviewer-visible callout for FR-010 column widths**: PHPCS/PHPStan cannot catch a schema column-width narrowing. The T2..T5 code-review checklist should include an explicit "verify `char(64)` on hashed columns" gate.
- **RECOMMEND — B7 audit at T7**: caller sweep is the natural regression window for mass-assignment (BUGS.md B7). Explicit gate in T7's DoD.
- **RECOMMEND — DEV1 boundary automated check**: T7's DoD includes `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` returning 1 (spec FR-021 codifies this).

---

## Status

**PASS** — no HARD security-architecture conflicts identified. Three RECOMMEND items surface as advisory gates to fold into `/speckit-tasks` T7 acceptance criteria + one merge-readiness gate for the user to confirm.

The plan's overall security posture matches or slightly strengthens the pre-feature baseline (new phantom-version guard + new in-callback autoload require add defense-in-depth against activation-time missing-vendor + missing-table classes of failure).
