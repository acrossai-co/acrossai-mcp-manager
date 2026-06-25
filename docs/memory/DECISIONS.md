# Decisions

## Entry Lifecycle

Each decision follows this lifecycle:

```
Active → Needs Review → Superseded → (pruned)
```

- **Active**: The decision is current and must be honored by all features and AI agents.
- **Needs Review**: Implementation reality or new context suggests this decision may be outdated. It should still be honored until reviewed and explicitly changed.
- **Superseded**: A newer decision has replaced this one. Keep it for historical context until the next audit, then consider pruning.
- **Pruned**: During an audit, remove superseded entries that no longer provide historical value. This keeps the file focused.

### When to change status

| Current Status | Change To    | When                                                                                                       |
| -------------- | ------------ | ---------------------------------------------------------------------------------------------------------- |
| Active         | Needs Review | Verified implementation or tests contradict the decision, or recurring features follow a different pattern |
| Active         | Superseded   | A newer decision explicitly replaces this one                                                              |
| Needs Review   | Active       | Team confirms the decision still holds after review                                                        |
| Needs Review   | Superseded   | Team confirms a replacement decision                                                                       |
| Superseded     | _(remove)_   | Audit finds no remaining historical value                                                                  |

### Rules

- Never delete an Active decision without replacing or superseding it.
- Never silently ignore a decision. If it feels wrong, mark it Needs Review and resolve it.
- Keep at most 3–5 Superseded entries for context. Prune older ones during audits.

---

## Template

### YYYY-MM-DD - Decision title

**Status**
Active | Superseded | Needs review

**Why this is durable**
What cross-feature choice is likely to matter again?

**Decision**
What was decided and what boundary does it create?

**Tradeoffs**
What was gained, what was made harder, and when should this be reconsidered?

**Future mistake prevented**
What likely incorrect approach does this rule out?

**Evidence**
Diff, tests, review, incident, or repeated implementation evidence.

**Where to look next**
Files, modules, or specs future maintainers should inspect.

---

### 2026-05-29 — PLUGIN_NAME_SLUG defined as literal string

**Status**
Active

**Context**
`define_constants()` in `includes/Main.php` runs before any properties are
set in the constructor. `$this->plugin_name` is null at that call site.

**Decision**
`ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` MUST be defined as the literal string
`'acrossai-mcp-manager'`, not `$this->plugin_name`.
`get_plugin_name()` continues returning `$this->plugin_name` (not the constant)
to avoid touching every caller and to prevent ordering bugs in test bootstraps
that don't load the full plugin. Both hold the same value — the return source
is an explicit design choice.

**Rationale**
`define()` silently accepts null. Using `$this->plugin_name` (null) would
define the constant as an empty string — a silent misconfiguration.

**Alternatives rejected**
- Reorder constructor so `$this->plugin_name` is set first — rejected; it
  changes the boot contract (define_constants always runs first, FR-001).
- Return the constant from `get_plugin_name()` — rejected; changes every
  caller and introduces an ordering dependency in test bootstraps.

---

### 2026-05-29 — Rewrite rules registered immediately at activation

**Status**
Active

**Context**
`FrontendAuth` and `ClaudeConnectors` handler classes do not exist at plugin
activation time (they are implemented in Phases 3 and 6 respectively).

**Decision**
Register rewrite rules with placeholder query vars at activation using literal
path strings (not class constants). Requests to these paths return a graceful
WordPress 404 until the handler classes are implemented. No deferral, no
conditional registration.

**Rationale**
Deferral approaches (deferred registration, or conditional on class_exists)
would require a separate rewrite flush later and could leave site permalinks
in an inconsistent state. Registering immediately ensures routes are always
present from first activation.

**Alternatives rejected**
- Defer registration until handler class is registered via a hook — rejected;
  adds complexity, requires an extra flush event later.
- Use class constants (`FrontendAuth::PAGE_SLUG`) — rejected; classes don't
  exist at activation time, causing a fatal.

---

### 2026-05-29 — Compat.php placed in includes/ not includes/Utilities/

**Status**
Active

**Context**
Constitution Principle I says shared logic MUST go in `includes/Utilities/`.
`Compat.php` is a shared utility. However, the boot flow loads Compat BEFORE
other Utilities/ classes are available (it provides PHP 7.4 polyfill guards
used by early-loading code).

**Decision**
`includes/Compat.php` with namespace `AcrossAI_MCP_Manager\Includes` is an
accepted exception to Principle I. This is documented as DEV2 in INDEX.md.

**Rationale**
`Compat` is a boot-time compat shim, not a feature-level utility. Placing it
in `Utilities/` would create an autoloader ordering dependency at PHP < 8.0.
The existing source (`src/Core/Compat.php`) confirms the `Core` placement
pattern.

**Alternatives rejected**
- `includes/Utilities/Compat.php` — rejected; autoloader resolves Utilities/
  after includes/ classes in some edge cases on PHP 7.4.

---

### 2026-05-29 — class_exists() guards in Activator are always silent no-op

**Status**
Active

**Context**
DB Query classes (`MCPServer\Query`, `CliAuthLog\Query`,
`ConnectorAuditLog\Query`) do not exist until Phase 4.

**Decision**
Every DB call in `Activator::activate()` MUST be guarded with
`class_exists( ClassName::class )`. If the class is absent, the call is
silently skipped — no log entry, no wp_options flag, no admin notice.

**Rationale**
Silent skip prevents activation failure on fresh installs where Phase 4 has
not yet been merged. Logging a non-fatal notice would confuse administrators.

**Alternatives rejected**
- Hard-code the check and throw on failure — rejected; breaks fresh installs.
- Use `try/catch` — rejected; `class_exists` is cleaner and has no
  performance overhead.

---

### 2026-05-29 — PHPCS Baseline Exceptions in phpcs.xml.dist [Feature-001]

**Status**
Active

**Why this is durable**
Baseline exclusions are required for all phases until structural refactoring of the boilerplate is done.

**Decision**
Five rule groups are intentionally suppressed in `phpcs.xml.dist`: filename casing (`WordPress.Files.FileName`), `$_instance` underscore prefix (`PSR2.Classes.PropertyDeclaration.Underscore`), file docblocks (`Squiz.Commenting.FileComment`), `namespace Public` reserved keyword (`PHPCompatibility.Keywords.ForbiddenNames`, `Universal.NamingConventions.NoReservedKeywordParameterNames`), and PSR12 file-header order. Also suppressed: `CommentedOutCode.Found` and `InlineComment.InvalidEndChar` for stub pattern comments.

**Tradeoffs**
Allows PHPCS to exit 0 without renaming all PascalCase files or restructuring `namespace Public`. Cannot be removed until `public/Main.php` is renamed or the namespace changed.

**Future mistake prevented**
Do not attempt to "fix" these PHPCS violations inline — they require renaming files or restructuring namespaces, both of which are out of scope during migration phases.

**Evidence**
Feature 001 clarification Q4 (2026-05-29). All 6 modified files pass PHPCS exit 0.

**Where to look next**
`phpcs.xml.dist` — the `<rule ref="WordPress-Extra">` block contains all exclusion comments.

---

### 2026-05-29 — Activator Uses use Imports for DB Class References [Feature-001]

**Status**
Active

**Why this is durable**
Any file in namespace `AcrossAI_MCP_Manager\Includes` that references sub-namespace classes must use `use` imports — see BUGS.md B1.

**Decision**
All DB class references in `Activator.php` MUST use top-of-file `use … as` aliases (e.g. `use … MCPServer\Query as MCPServerQuery`). Bare relative names inside the `Includes` namespace produce a double-`Includes` FQN silently. Inline FQN strings in `class_exists()` are forbidden.

**Tradeoffs**
Slightly more verbose at file top; eliminates silent activation failures.

**Future mistake prevented**
Never write `class_exists( Includes\SomeClass::class )` inside a file in `AcrossAI_MCP_Manager\Includes`.

**Evidence**
BUGS.md B1 pattern. Feature 001 Activator.php lines 4-6 use correct `use` imports.

**Where to look next**
`includes/Activator.php` lines 1-10; any new file added to `includes/`.

---

### 2026-05-29 — Activator Does Not Call insert_default_server() [Feature-001]

**Status**
Active

**Why this is durable**
This separates Phase 2 activation responsibility from Phase 4 data-seeding responsibility.

**Decision**
Default MCP server row insertion is a Phase 4 concern deferred to `MCPServerQuery::maybe_create_table()` internal logic. `Activator::activate()` MUST NOT call any `insert_default_server()` method — it does not exist in Phase 1 and will not be an Activator responsibility.

**Tradeoffs**
Activator is simpler; data seeding is colocated with schema creation.

**Future mistake prevented**
Do not add `insert_default_server()` or similar seeding calls to `Activator.php`.

**Evidence**
Feature 001 clarification Q1 (2026-05-29). Spec FR-009 updated to reflect this.

**Where to look next**
`includes/Activator.php::activate()`, `includes/Database/MCPServer/Query.php::maybe_create_table()` (Phase 4).

---

### 2026-05-29 — AccessControl Stub Targets wpb-access-control Vendor Package [Feature-001]

**Status**
Active

**Why this is durable**
Phase 7 implementation MUST use the vendor package, not an internal wrapper class.

**Decision**
The `AccessControl` hook stub in `define_admin_hooks()` uses `\WPBoilerplate\AccessControl\AccessControlManager` from `wpboilerplate/wpb-access-control ^1.0`. Phase 7 MUST consume this package directly via Composer.

**Tradeoffs**
Creates a Composer dependency on a vendor package. Ensures no internal class diverges from the package.

**Future mistake prevented**
Do not create `AcrossAI_MCP_Manager\Includes\AccessControl\AccessControlManager` as an internal class — use the vendor package FQN.

**Evidence**
Feature 001 clarification Q2 (2026-05-29). Main.php stub line ~294.

**Where to look next**
`includes/Main.php::define_admin_hooks()`, `composer.json` (Phase 7 — add `wpb-access-control ^1.0`).

---

### 2026-06-17 — BerlinDB-style Query Interface Hand-Rolled Without the Vendor [Feature-002]

**Status**
Active

**Why this is durable**
Future custom-table needs in this plugin (and sister plugins in the same stack) should follow this minimal pattern rather than pulling `berlindb/core` into composer.

**Decision**
When a custom DB table needs a Query-style instance interface (`query()`, `add_item()`, `update_item()`, `delete_item()`), build it hand-rolled as four PHP classes per table — `Schema` (column metadata), `Table` (dbDelta lifecycle + `maybe_create_table()`), `Row` (typed value object with `to_array()`), `Query` (singleton-style static `maybe_create_table()` + per-call instance methods). All DB I/O uses `$wpdb->prepare()` internally. The contract is the **interface**, not the BerlinDB library. Spec/plan may refer to "BerlinDB Query classes" — read this as shorthand for the four-method interface.

**Tradeoffs**
- Pro: zero new composer deps; no vendor lock-in; ~200 lines per table is manageable
- Con: must re-implement BerlinDB conveniences (caching, type coercion, query introspection) if needed later

**Future mistake prevented**
Do not add `berlindb/core` to `composer.json` just because the spec says "BerlinDB". Read the FR-022 interface clause as authoritative — the library name is shorthand.

**Evidence**
Feature 002 Q4 clarification (2026-06-17). Implementation: `includes/Database/{MCPServer,CliAuthLog}/{Schema,Table,Row,Query}.php`. Q4 entry in `specs/002-admin-ui/spec.md`.

**Where to look next**
`includes/Database/MCPServer/Query.php` for the canonical reference implementation. Future custom tables: copy that 4-file pattern.

---

### 2026-06-17 — Minimal-Port Deferral Pattern for Multi-Class Dependencies [Feature-002]

**Status**
Active

**Why this is durable**
When migrating a class whose source dependencies include other un-ported classes, a partial port with deferred work is preferable to either porting the entire dependency tree (scope creep) or stubbing the class (regression).

**Decision**
A "minimal port" ships the subset of the source class's API needed to satisfy the user-story FRs and stubs / omits the parts that depend on un-ported sibling classes. The pattern requires:
1. The deferred functionality is **explicitly documented** in the new class's docblock (which dependencies are missing, which FRs they unlock)
2. A **follow-up task ID is reserved** in tasks.md (or a follow-up phase identified)
3. The current implementation does NOT silently fail or throw when the missing functionality is invoked from the UI — either the UI excludes the call site, or the method returns a graceful response

**Tradeoffs**
- Pro: unblocks user-story delivery; surfaces the deferred work in tracker
- Con: future readers need to consult docblock to understand why the class is smaller than the source; carries risk of "minimal" becoming permanent

**Future mistake prevented**
Do not block a Phase N port on Phase N+1 deliverables. Do not stub a class as a placeholder either — partial port + explicit deferral is the third path.

**Evidence**
Feature 002 T025 (2026-06-17): `admin/Partials/ApplicationPasswords.php` ships 2 of 3 source REST endpoints, no `Includes\MCPClients\*` (7 classes) — the deferred MCPClient namespace is noted in the class docblock and tracked as RT-3 in `specs/002-admin-ui/governance-summary.md` follow-ups.

**Where to look next**
`admin/Partials/ApplicationPasswords.php` docblock for the canonical "what was deferred" note format.

---

### 2026-06-18 — Phase X.0 Absorption Pattern for Missing Prerequisites [Feature-004]

**Status**
Active

**Why this is durable**
This pattern has been used twice in two phases (Phase 2.0 absorbed the BerlinDB Query layer prereq; Phase 4.0 absorbed the PHPUnit harness prereq). Without naming the pattern, future phases will rediscover it ad-hoc — or worse, block waiting for "someone else" to ship the prereq.

**Decision**
When a Spec-Kit phase's P0 gate (T004 or equivalent) fails because a prerequisite shared-infrastructure piece (DB layer, test harness, build pipeline, etc.) doesn't yet exist, the implementing phase MUST absorb the prerequisite setup as a sub-phase called **"Phase X.0"** — not stop and wait for a separate phase to ship it.

The sub-phase:
- Is documented inline in the implementing phase's tasks.md (typically renaming T005-T007 to become the harness setup)
- Is committed in the same PR as the consuming phase's implementation
- MUST stay minimal — set up what's needed for THIS phase's work, no more
- MUST NOT bundle scope creep ("while we're setting up the test harness, let me also add a JS test runner")
- SHOULD note in the commit message that the sub-phase work UNBLOCKS sibling deferred tasks in other phases when applicable

**Examples**:
- **Phase 2.0** (2026-06-17): set up BerlinDB-style Query layer for `MCPServer` + `CliAuthLog` tables because the Admin UI in Phase 2 needed them. 8 files, ~828 lines. Unblocked Phase 2 itself.
- **Phase 4.0** (2026-06-17): set up PHPUnit harness (`phpunit.xml.dist`, `tests/bootstrap.php`, `.phpunit.cache/` gitignore entry) because the MCPClients tests required it. ~60 lines. Unblocked Phase 4 AND Phase 2's 14 previously-deferred test tasks.

**Tradeoffs**
Gained: phases ship in finite time without coordination-deadlocked dependencies; sub-phase work surfaces as scope in the commit log.
Reconsider: if the prereq is bigger than the consuming phase (e.g., setting up a full CI/CD pipeline to land one test), it's no longer a "Phase X.0" candidate — it deserves its own dedicated phase number.

**Future mistake prevented**
Don't write "TODO: shared infrastructure must exist before this phase begins" and stall the phase. Absorb it as X.0 and move forward.

**Evidence**
Feature 002 commit `cc536f7` (Phase 2.0 Query layer); Feature 004 commit `d979391` (Phase 4.0 PHPUnit harness).

**Where to look next**
`specs/002-admin-ui/spec.md` Clarifications section (Q4) for the Phase 2.0 sub-phase precedent; `specs/004-mcp-clients/governed-implementation-summary.md` for the Phase 4.0 "side benefit" framing of unblocking sibling phases.

---

### 2026-06-25 — Bulk Task-Status Updates Must Be Re-Audited for Environment Gates [Feature-005]

**Status**
Active

**Why this is durable**
At the end of Phase 5 governed-implement (2026-06-23), all 90 `tasks.md` checkboxes were marked `[x]` via a single `sed` invocation. The next governance pass (`/speckit-analyze`) flagged this as K2-HIGH: T082 (`vendor/bin/phpunit --testsuite=oauth`) wasn't actually run — it requires a WP-PHPUnit DB; T085 (`npm run validate-packages`) wasn't run; T086 (manual quickstart walk) wasn't done; T087 (flip spec DoD checkboxes) was claimed but the spec checkboxes were still `[ ]`; T088 (data-model hand-off note) wasn't written. **Five false-`[x]` claims in one shot.** Honest task status is the foundation of every downstream review (analyze, verify, security-review, refactor-generator).

**Decision (D12)**
After ANY bulk task-status mutation (sed/awk/find-replace marking ≥3 tasks `[x]` at once), the implementer MUST:
1. Re-read every newly-`[x]` task and triage into three buckets: **(a) verified now with evidence in this session**, **(b) environment-dependent / requires manual action**, **(c) documentation-only edit not yet performed**
2. Revert bucket (b) and (c) to `[ ]` with an inline deferral note explaining what's blocking (e.g. `(deferred: requires WP-PHPUnit harness — provision via bin/install-wp-tests.sh)`)
3. Run the canonical post-implementation gates in the session: PHPCS, PHPStan, the language-equivalent regression suite that DOESN'T require external setup. If any fail, revert their corresponding tasks too.

This applies to bulk updates only. Single-task updates done as work completes are exempt — those are inherently honest.

**Tradeoffs**
Gained: downstream reviews (analyze, verify, security-review) start from honest data; PR descriptions don't carry false-positive completion claims; merge gates fail loudly when they should.
Reconsider: if all DoD gates ever become hermetic (run entirely in a Docker container with the test DB baked in), the manual triage step is no longer needed.

**Future mistake prevented**
Don't claim "all 90 tasks complete" when 5 of them required environments you don't have. The next reviewer will catch it; better to catch it yourself in the same turn.

**Evidence**
- Phase 5 implementation summary (2026-06-23) marked all 90 tasks `[x]` via sed
- `/speckit-analyze` K2 finding (2026-06-24) caught T082/T085/T086/T087/T088 as false-`[x]`
- Reverted to `[ ]` with explicit deferral notes on 2026-06-24

**Where to look next**
`specs/005-oauth-connectors/tasks.md` lines 310-321 for the deferral-note format pattern.
