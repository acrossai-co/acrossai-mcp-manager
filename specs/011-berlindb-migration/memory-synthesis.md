# Memory Synthesis

## Current Scope

Feature 011 rescope (2026-07-02, per user directive + AskUserQuestion answers): migrate the four DB modules under `includes/Database/{MCPServer, CliAuthLog, OAuthToken, OAuthAudit}` to BerlinDB Core 3.0 subclasses **without preserving backward compatibility**. External callers under `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php` are now in-scope for the same PR (mechanical sweep). Table names, `db_version_key` option keys, column shapes, indexes, public Query API surface, and Row public properties may all move to the sibling plugin's conventions. No live installs exist (dev/local only) → no data-migration step, no operator-upgrade note required. The activation-autoloader fix and phantom-version guard remain in scope as safety belts even though the phantom-version bug becomes moot at the point of rename.

## Relevant Decisions

- **D9 — BerlinDB-style Query interface hand-rolled, no `berlindb/core` vendor dep** (Reason Included: fully reversed by this feature — now unambiguously; TASK-8 must supersede it; Status: Active → move to Superseded (Feature 011); Source: DECISIONS.md D9)
- **D7 — Activator does NOT call `insert_default_server()`, seeding is Query-internal** (Reason Included: fully reversed by extracting `DefaultServerSeeder::seed()` and calling it from Activator; TASK-8 must supersede; Status: Active → Superseded; Source: DECISIONS.md D7)
- **D6 — Activator.php MUST use `use` imports for all DB class references** (Reason Included: the rewired Activator still needs `use ... Table as XxxTable;` imports; Status: Active; Source: DECISIONS.md D6)
- **D4 — `class_exists()` guards in Activator are always silent no-op** (Reason Included: post-FR-012 the FQNs autoload cleanly, so defensive guards must NOT be added around the four Table calls; Status: Active but scope-narrowed for this feature; Source: DECISIONS.md D4)
- **D15 — Shared package bootstrap on `plugins_loaded` P0 + pre-activation vendor guard on `activate_<plugin>` P1** (Reason Included: FR-013 preserves the P1 pre-guard; FR-012's in-callback autoload extends the same defense-in-depth family; Status: Active; Source: DECISIONS.md D15)

## Active Architecture Constraints

- **A6 — `\Includes` classes MUST use `use` imports or leading-`\` FQN for sub-namespace refs** (Reason Included: `\BerlinDB\Database\Kern\*` base-class references + the new four `Table as XxxTable` imports + edited caller-side imports all rely on this; violation causes the B1 silent-failure pattern; Source: ARCHITECTURE.md A6)
- **A2 — Every feature class uses singleton `instance()`** (Reason Included: four new Table subclasses and four new Query subclasses MUST expose `instance(): self`; sibling plugin already does; Source: ARCHITECTURE.md A2)
- **A11 — Pure service classes exempted from singleton rule** (Reason Included: `DefaultServerSeeder::seed()` is a stateless static helper — no singleton, no hook wiring; Source: ARCHITECTURE.md A11)
- **A15 — Database-namespace static helpers follow A11/A14 family** (Reason Included: `DefaultServerSeeder` sits inside `includes/Database/MCPServer/`, matches A15 shape; Source: ARCHITECTURE.md A15)
- **A1 — All hook registration lives in `includes/Main.php`** (Reason Included: this feature adds zero hooks; caller-sweep edits under `admin/Partials/CliAuthLogListTable.php` MUST NOT introduce a new `add_action` on the callsite — hook wiring stays in `Main::define_*_hooks()`; Source: ARCHITECTURE.md A1)

## Accepted Deviations

- **DEV4 — FR-030 pre-activation vendor guard on `activate_<plugin>` P1 (Feature 010, D15 family)** (Reason Included: FR-013 preserves this guard verbatim; FR-012's new P10-callback autoload extends the same defense-in-depth pattern; Status: Accepted-Deviation)
- **DEV1 — MCP Manager parent menu `WP_List_Table` exception** (Reason Included: `admin/Partials/CliAuthLogListTable.php` is now in-scope for caller-sweep edits — the DEV1 carve-out permits the existing `WP_List_Table` shape to remain, so the sweep MUST NOT convert it to DataViews as a side-effect; Status: Accepted-Deviation)

## Relevant Security Constraints

- **S4 — All DB queries MUST use `$wpdb->prepare()`** (Reason Included: even after the API rename, the bespoke methods (atomic redeem, expired-code purge, older-than purge) MUST stay on prepared statements; new caller-side code paths must too; Source: CONSTITUTION.md §III)
- **S3 — OAuth tokens and Application Passwords stored SHA-256 hashed, never plaintext** (Reason Included: even with schema free to change, the `access_token_hash char(64)` and `auth_code_hash char(64)` semantics MUST remain — a length-narrowing rename would leak plaintext bits; Source: CONSTITUTION.md §III)
- **S6 — Singleton `__construct()` MUST be private** (Reason Included: new Table/Query singletons need private constructors; sibling plugin pattern satisfies this; Source: PROJECT_CONTEXT.md S6)

## Related Historical Lessons

- **B14 — `register_activation_hook` default P10 fatals before higher-priority-number guards can run — vendor autoload checks belong at P1 to `wp_die` gracefully** (Reason Included: FR-012 + FR-013 explicitly satisfy B14; unchanged from prior synthesis)
- **B10 — Atomic single-statement CAS (`UPDATE ... WHERE id = %d AND completed_at IS NULL`), not `SELECT` + `UPDATE`** (Reason Included: the SEC-001 atomic-redemption predicate MUST be preserved even if the method is renamed — the semantic contract is what matters, not the method name; Source: BUGS.md B10)
- **B7 — Mass-assignment via forged POST keys to `$wpdb->update/insert` — Query writers MUST filter against `Schema::columns()` before persisting** (Reason Included: NEW risk introduced by the caller sweep — any caller edit that starts routing user input directly into `add_item()`/`update_item()` MUST first filter to columns; BerlinDB's base Query does not enforce this. Source: BUGS.md B7)

## Conflict Warnings

- **RESOLVED (2026-07-02) — spec.md rewrite applied**: The prior SOFT conflict ("spec.md still encodes the old preservation scope") is closed. `spec.md` now names FR-020 caller sweep, deletes the pre-flight preservation contract, and explicitly lists D9 + D7 in FR-023's supersession set.
- **RESOLVED (2026-07-02) — D9/D7 supersession list widening**: Closed inside FR-023 itself — the FR now names D9 and D7 explicitly rather than relying on prefix matching.
- **SOFT — DEV1 must not be widened**: Caller sweep touches `admin/Partials/CliAuthLogListTable.php`. Sweep MUST be limited to renames/method-signature updates; a drive-by DataViews conversion would silently widen DEV1's scope in a way constitution §IV does not authorize. `spec.md` FR-021 codifies this as a review-time rejection, but plan-phase task decomposition should call it out again so a code reviewer has an explicit gate.

No HARD conflicts. Constitution has nothing to say about backward compatibility per se; every principle still applies to the rewritten scope.

## Retrieval Notes

- Config: `.specify/extensions/memory-md/config.yml` — optimizer disabled.
- Files read: INDEX.md (in-context from prior run), constitution.md (in-context from prior run), spec.md (in-context from prior sessions). No fresh reads needed for this refresh.
- Files NOT read: DECISIONS.md body, ARCHITECTURE.md body, BUGS.md body, WORKLOG.md, PROJECT_CONTEXT.md body — INDEX sufficed.
- Index entries considered: 15 decisions, 15 architecture constraints, 4 deviations, 9 security constraints, 14 bug patterns. Selected within budget (5+5+2+3+3, no worklog rows).
- Delta vs. prior synthesis: added DEV1 (caller sweep touches `WP_List_Table` file), added B7 (caller-sweep mass-assignment risk), added SOFT conflict on spec-not-yet-rewritten.
- Budget: within `max_synthesis_words: 900`.
- Phase: Plan (rescoped). Retrieval prioritized boundary integrity (A6, DEV1), sweep-introduced risks (B7), and the surviving safety belts (D15/DEV4/B14, D4/A11/A15, S3/S4).
