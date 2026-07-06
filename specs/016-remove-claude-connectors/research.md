# Phase 0 Research — Feature 016

**Scope reduction (2026-07-07)**: User directive removed the self-heal / backward-compatibility path. The plugin ships fresh-install-only; the operator handles pre-Feature-016 physical data with the manual SQL recipe in spec §User Story 2. Decisions 1, 2, 5, and 6 below reflect the simpler post-directive scope.

## Decision 1 — No in-plugin schema migration (SUPERSEDED prior "BerlinDB version bump" plan)

**Decision**: Do NOT bump `MCPServer/Table.php::$version` from `0.0.1`. Do NOT create a `ConnectorColumnMigration` fallback helper. Do NOT invoke any `ALTER TABLE ... DROP COLUMN` from the plugin. The `Schema.php` file simply omits the three retired column definitions; on a fresh install BerlinDB creates the 10-column table matching the reduced schema.

**Rationale**:
- Operator directive 2026-07-07: "we are not looking for backward compatibility I am going to delete the table by myself and activate and deactivate the plugin". This shifts the migration responsibility from the plugin to the operator.
- No in-plugin migration code means no test surface for migration edge cases, no `ConnectorColumnMigration` helper, no `column_exists`-gated fallback, and no risk of accidentally editing BerlinDB's diff engine into an alternate code path.
- On existing installs where the operator has already dropped the columns manually (per spec §User Story 2 SQL recipe), BerlinDB sees `$version == 0.0.1` on both sides and skips `maybe_upgrade()` — a clean no-op.
- On fresh installs, BerlinDB creates the 10-column table matching `Schema.php` — no migration needed.

**Alternatives considered**:
- **Bump `$version` anyway (harmless)**: rejected — a version bump on a schema that isn't actually changing sets a precedent that "we bump `$version` for style". Better to leave `$version` untouched and treat it as the immutable data-shape identifier it's designed to be.
- **Idempotent DROP in Activator as safety net**: rejected — operator directive is explicit; adding "just in case" cleanup contradicts the directive and enlarges the diff.
- **Warning notice for operators who forgot the manual SQL**: rejected — YAGNI; the plugin runs cleanly with residual dead columns/tables, just with a slightly bigger DB footprint.

---

## Decision 2 — Uninstall.php safety net stays; no new Activator cleanup (SIMPLIFIED)

**Decision**: `uninstall.php` continues to list `wp_acrossai_mcp_oauth_tokens` and `wp_acrossai_mcp_oauth_audit` in its `DROP TABLE IF EXISTS` list (already present pre-Feature-016 per FR-011). The two `db_version` options and `acrossai_mcp_claude_connectors_enabled` continue to appear in the options-delete list. All entries live AFTER the `DEC-UNINSTALL-OPT-IN-GATE` short-circuit. `Activator::activate()` gets NO new cleanup statements.

**Rationale**:
- `uninstall.php` runs only when the operator explicitly deletes the plugin AND has opted into `acrossai_mcp_uninstall_delete_data = 1`. That's a strong signal they want a clean slate — the safety net there is cheap.
- The operator directive to handle migration manually is about the REACTIVATION path, not the uninstall path. Uninstall is fundamentally different: it's the terminal state, and dropping known-defunct tables there is idiomatic WordPress plugin hygiene.
- No new Activator cleanup means the retirement diff is smaller (pure deletion, no new destructive statements to review for safety).

**Alternatives considered**:
- **Remove OAuth table names from uninstall.php too**: rejected — the marginal cost of keeping them is zero; the marginal benefit if some operator forgot the manual SQL is nonzero.

---

## Decision 3 — Retired shortcode behavior

**Decision**: Do NOT register a replacement shortcode. WordPress's default behavior (return the raw shortcode text unchanged) is the acceptance behavior specified in Edge Cases §4.

**Rationale**:
- The Edge Case in spec.md already articulates this: `[acrossai_mcp_claude_connector_block server=1]` renders as literal text when unregistered — no fatal, no notice.
- Site admins removing the shortcode from posts/pages is a manual cleanup task equivalent to any other retired plugin surface. Registering a "This has been retired" stub creates ongoing maintenance for a message that says "we removed something".
- Two-year rule of thumb: if the retirement is publicly documented (README.txt Unreleased entry) and the surface is admin-visible (admin will see raw shortcode text on any test page load), no in-plugin messaging is needed.

**Alternatives considered**:
- **Registered no-op shortcode**: rejected — adds code to accomplish "nothing renders".
- **Retirement-notice stub for admins**: rejected — implies a rendering-with-capabilities check, which is code for a message.

---

## Decision 4 — Test file deletion vs migration approach

**Decision**: Delete the entire `tests/phpunit/OAuth/` directory (including `fixtures/`) and `tests/phpunit/Public/MainEnqueueTest.php`. Prune `tests/phpunit/Admin/SettingsMenuTest.php` and `tests/phpunit/Admin/ServerTabs/RegistryTest.php` to remove connector-specific assertions. Read `tests/phpunit/Public/Renderers/PublicApiTest.php` first — if entirely connector-scoped, delete; otherwise prune only.

**Rationale**:
- OAuth test directory (22 files) exclusively tests retired classes. Nothing there tests anything else.
- MainEnqueueTest.php exclusively tests `Public\Main::enqueue_styles/scripts` which are being deleted; no other content.
- SettingsMenuTest / RegistryTest test the entire admin surface; connector assertions are a minority — prune, don't delete.
- PublicApiTest.php: the memory synthesis identified this as connector-heavy but not necessarily connector-only; read at implementation time to decide.

**Alternatives considered**:
- **Keep OAuth test dir with `@group skip` markers**: rejected — leaves dead code that references deleted classes; PHPStan/PHPCS will flag.
- **Migrate OAuth tests to test the removal itself**: rejected — YAGNI; the acceptance scenarios in spec.md are the correct forcing function, tested in Phase 1's `quickstart.md`.

---

## Decision 5 — Activator retirement is pure deletion (SIMPLIFIED)

**Decision**: `Activator::activate()` AND `Deactivator::deactivate()` receive ONLY subtractive edits. Delete from Activator: the `ClaudeConnectors::instance()->register_rewrite_rules()` call + trailing `flush_rewrite_rules()` companion, the `wp_schedule_event('acrossai_mcp_oauth_cleanup', ...)` block, the two `Table::instance()->maybe_upgrade()` calls for `OAuthToken\Table` + `OAuthAudit\Table`, and all associated `use` imports. Delete from Deactivator: the `wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' )` line. Do NOT add any new destructive SQL. The existing `flush_rewrite_rules()` call at the end of `Activator::activate()` (part of the F011 activation flow) STAYS — it rebuilds the rewrite table from the current registered set, which no longer contains the OAuth rewrite rules.

**Rationale**:
- Operator directive 2026-07-07 makes migration the operator's responsibility. Nothing to add.
- The existing final `flush_rewrite_rules()` call in the F011 activation flow is untouched. It naturally drops the retired OAuth rules on next reactivation because those rules are no longer registered by any code.
- The Deactivator line is DELETED because keeping the string `acrossai_mcp_oauth_cleanup` in code fails the FR-015 grep audit. Operator handles the one-time cleanup manually — the retirement SQL recipe in spec §User Story 2 gains a companion CLI step: `wp cron event unschedule acrossai_mcp_oauth_cleanup`.

**Alternatives considered**:
- **Keep the Deactivator line + exempt from grep audit**: rejected — grep-audit exemptions are a maintenance burden; the operator can unschedule the cron in one command as part of the manual retirement recipe.
- **Add `flush_rewrite_rules()` twice** (once at start of activation for cleanup, once at end for setup): rejected — the plugin's activation flow is single-pass by design; two flushes waste a rewrite-table rebuild for no gain.

---

## Decision 6 — Retire in-plugin logging around migration (SIMPLIFIED)

**Decision**: Do NOT add any admin notices, `error_log()` calls, or debug.log messages around the retirement. Per F011's silent-guard invariant (Clarification Q1 in `docs/planings-tasks/011-berlindb-migration.md`), plugin activation is expected to be silent on the happy path. Since the operator handles migration manually and the plugin's contribution is pure deletion, there's nothing to log.

**Rationale**:
- Consistency with F011's silent-guard convention.
- Operator directive puts the migration outside the plugin — logging it from the plugin would be misleading.

**Alternatives considered**:
- **One-time "Feature 016 applied" admin notice**: rejected — nothing was "applied" from the plugin's side; the operator did the work externally.

---

## Decision 7 — Constitutional / durable-memory annotations

**Decision**: Do NOT edit constitution or durable-memory files as part of Feature 016 implementation. Instead, run `/speckit-memory-md-capture-from-diff` POST-implementation to propose annotations for user approval. The specific annotations proposed:
- `PROJECT_CONTEXT.md::S7` — annotate "no consumers post-F016; token endpoint retired".
- `DECISIONS.md::DEC-CLIENT-RENDERER-PUBLIC-API` — annotate "post-F016: 2 shortcodes + 2 dispatch map entries".
- `ARCHITECTURE.md::A13` — annotate "no active consumers post-F016; still valid for future RFC-prescribed forms".
- Constitution Principle I Rationale — annotate "OAuth / Claude Connectors retired in F016; 4 active areas".
- Constitution Architecture > Directory Layout — remove `includes/OAuth/` line (single-line diagram edit).

**Rationale**:
- Separation of concerns: F016 is a code-teardown feature; memory-hygiene is a distinct workflow best triggered by the actual diff.
- The `/speckit-memory-md-capture` flow already exists and is proactively triggered by `/speckit-architecture-guard-governed-plan` in its finale step. Piggyback on that.

**Alternatives considered**:
- **Edit constitution + memory files in the same PR**: rejected — bloats the diff; makes the retirement PR harder to review; couples two distinct concerns.
