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

**Note (Feature 011)**: Scope narrowed — this rule no longer applies to the four Database\{Module}\Table::instance()->maybe_upgrade() calls in Activator per FR-016 (defensive class_exists would mask a real regression after FR-011's autoload fix). Still active for other class_exists patterns.

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
Superseded (Feature 011)

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
Superseded (Feature 011)

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

### 2026-06-30 — Constitution-level Formalization vs. Accepted Deviation Registration [Feature-007]

**Status**
Active

**Why this is durable**
Feature-007 (FrontendAuth) needed to broaden authorization from `manage_options` to "any logged-in user" because the user consents on their own behalf to issue a credential scoped to themselves. The deviation was documented in plan §Complexity Tracking + spec §Assumptions + security-constraints.md, but the constitution itself was unchanged. The 2026-06-30 architecture review flagged this as a CRITICAL violation by strict reading. Resolution: amend Constitution §III to add a formal "Consent-surface exception" with 5 binding conditions, citing Feature-007 as the canonical instance. The same pattern applies to OAuth consent (Feature-005, retroactively) and any future device-grant flow. Future authors get a constitutional reference instead of re-deriving the spec/plan paperwork per feature.

**Decision**
When a feature-local deviation describes a GENERALIZABLE pattern (applies to ≥2 existing features OR has a forward-looking surface that the spec-kit team can name), formalize the exception in `.specify/memory/constitution.md` rather than registering it as an Accepted Deviation in `docs/memory/INDEX.md`. Accepted Deviations are for ONE-OFF carve-outs (e.g. DEV2 boot-time `Compat.php` placement, DEV3 bidirectional Phase 6 ↔ Phase 7 coupling pending T044). Constitution amendments are for reusable patterns. The constitution paragraph MUST include binding conditions (not bare permissive language) so the exception cannot collapse into a generic loophole — at minimum, conditions covering (a) precondition gate, (b) scope binding, (c) operator opt-in, (d) citation requirement, (e) data-source authoritativeness.

**Tradeoffs**
- Gained: future audits find the exception at the canonical source; class docblocks have a single citation target; cross-feature reuse is encouraged; ad-hoc per-feature exception paperwork is replaced with a constitutional reference.
- Made harder: constitution edits are higher-ceremony than INDEX.md row adds — must include binding conditions to prevent the exception from becoming a generic loophole; requires `/speckit-constitution` flow (or equivalent direct amendment) rather than a one-line INDEX.md add.
- Reconsider: if the exception's conditions ever expand to include "any logged-in user is consenting" without the 5 binding constraints, the exception has become a loophole and the constitution should be re-tightened. Also reconsider if a future feature's deviation matches an existing constitution exception but with different conditions — that's a signal to split the exception, not stretch it.

**Evidence**
- 2026-06-30 architecture-review V1 finding (CRITICAL): `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md` and the architecture-review report this turn
- 2026-06-30 constitution amendment: `.specify/memory/constitution.md` §III "Consent-surface exception" paragraph
- 2026-06-30 DEV3 registration (counter-example for one-off coupling acceptance): `docs/memory/INDEX.md` Accepted Deviations table
- Feature-007 sites that now cite the exception: `public/Partials/FrontendAuth.php` class docblock, `specs/007-frontend-cli-auth/spec.md` FR-007.4

**Where to look next**
`.specify/memory/constitution.md` §III for the canonical exception text and the 5 binding conditions. Compare against `docs/memory/INDEX.md` DEV1/DEV2/DEV3 to see what shape qualifies as one-off vs. generalizable.

### 2026-07-01 — Cross-Phase State Observation via Public-Static Predicate on the Owning Module [Feature-008]

**Status**
Active

**Why this is durable**
When Phase N needs to observe state owned by Phase M (a query var value, a transient's payload, an owning-module identity check), the design question "should I inspect M's internals directly or ask M to publish an interface" recurs. Two evidenced resolutions to date:

- Feature-007 / 2026-06-30 (SEC-001 fix): `FrontendAuth` needed to know the transient's authoritative `server_id` for the consent UI. Resolution: `CliController::peek_pending_server( string $auth_code ): ?string` published as public static on the OWNING module (Phase 6). `FrontendAuth` consumes via `use AcrossAI_MCP_Manager\Includes\REST\CliController;` — never touches transients directly.
- Feature-008 / 2026-07-01 (FR-020 fix): `public/Main` needed to know if the current request is on the OAuth authorize surface. Resolution: `ClaudeConnectors::is_authorize_page(): bool` published as public static on the OWNING module (Phase 5). `public/Main` consumes via `use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;` — never duplicates the `'authorize'` / `'acrossai_mcp_oauth'` magic strings.

Both cases were memory-informed decisions during the plan phase (the memory-synthesis for each explicitly steered the consumer AWAY from duplicating the check).

**Decision**
When Phase N needs to observe state owned by Phase M, Phase M publishes the observation as a public static predicate on its own class. Phase N consumes via `use` import. The predicate MUST satisfy A11's pure-stateless-service exemption (no instance state, no hook registration, no side effects, idempotent — safe to call multiple times per request). The consuming module MUST NOT duplicate the magic strings (query var names, transient key prefixes) that Phase M uses internally — always route through the published predicate. If the predicate needs to return richer information than a bool (server_id, user_id, session token), return a `?string` / `?array` shape and apply B11 defensive read on the way out.

**Tradeoffs**
- Gained: single source of truth for cross-phase state coupling. If Phase M renames its query var or restructures its transient shape, the change lives in one place. Consumers never break silently due to magic-string drift. Reviewers audit ONE predicate location rather than N call sites.
- Made harder: Phase N acquires a hard dependency on Phase M's public static API. If Phase M is deleted or reorganized, Phase N breaks at compile time — which is preferable to silent runtime drift. For features with an accepted deviation like DEV3 (bidirectional coupling deferral), this pattern IS the fix that unblocks the deferral.
- Reconsider: if the predicate needs instance state (rare — most cross-phase observations are pure reads), it's no longer A11-eligible and belongs as an instance method. But then reconsider whether cross-phase publication is the right shape at all — instance state usually implies the observation should happen inside the owning module rather than being exposed.

**Evidence**
- Feature-007 / 2026-06-30 SEC-001 fix: `includes/REST/CliController.php` — `peek_pending_server()` published; consumed by `public/Partials/FrontendAuth.php::handle_cli_auth`
- Feature-008 / 2026-07-01 FR-020 fix: `includes/OAuth/ClaudeConnectors.php` — `is_authorize_page()` published; consumed by `public/Main.php::enqueue_styles/scripts`
- Both predicates' docblocks cite each other and A11 + B11 as precedent

**Where to look next**
Read the two published predicates as a pair — the short form (`is_authorize_page`) shows the simplest shape (bool return, no defensive read); the long form (`peek_pending_server`) shows the full pattern with B11 defensive triple-check on a shape-returning payload. Both use zero side effects; both include their consumer's FR identifier in the docblock so reviewers can trace the call graph via grep.

---

### 2026-07-02 — Shared Package Bootstrap in Plugin Entry File (Accepted A1 Deviation) [Feature-010]

**Status**
Active

**Why this is durable**
When a plugin consumes a shared vendor package that OWNS a cross-plugin resource (e.g., a shared parent admin menu, a shared REST route namespace, a shared taxonomy), the vendor package's own bootstrap MUST live in the plugin's ENTRY FILE (`<slug>.php`), not routed through the plugin's Loader. Two evidenced instances across the AcrossAI codebase family:

- `acrossai-abilities-manager` Feature 038 (2026-06 / 2026-07) — `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` scope extension. `\AcrossAI_Main_Menu\SettingsPage` bootstrapped from `acrossai-abilities-manager.php` on `plugins_loaded` priority 0. Established the pattern.
- `acrossai-mcp-manager` Feature 010 (2026-07-02) — FR-029 / FR-030 / FR-031. Same package (`acrossai-co/main-menu 0.0.10`, one patch higher pin), same bootstrap shape, mirrored into `acrossai-mcp-manager.php`.

The Loader is per-plugin-instance and its lifecycle is single-plugin-scoped; the shared resource must be canonically owned regardless of any single consumer's Loader lifecycle. Blindly applying A1 to the shared-package bootstrap causes coordination races where multiple consumer plugins each try to own the resource. Per D13's rule ("escalate to constitution.md when the deviation describes a generalizable pattern ≥2 features"), the pattern IS generalizable across the codebase family and warrants durable registration.

**Decision**
Accept a **scoped deviation from A1** for external-package cross-plugin-resource bootstrap. The deviation is limited to ONE `add_action` call per shared resource, in the plugin's entry file, and MUST be gated by BOTH:

  (a) `did_action('<resource>_bootstrapped')` idempotency guard (prevents duplicate construction across sibling plugins consuming the same package)
  (b) `class_exists( <package\\entrypoint>::class )` defense-in-depth guard (Constitution §V Integration Resilience — graceful degradation when the package is absent)

After successful construction, the bootstrap fires `do_action('<resource>_bootstrapped')` so subsequent sibling plugins' guards short-circuit. The deviation MUST be DOCUMENTED at the call site with a docblock referencing this D-entry AND the matching DEV row in `docs/memory/INDEX.md`.

**Tradeoffs**
- Gained: correct cross-plugin coordination for shared vendor-owned resources. Deterministic single-boot per resource regardless of plugin activation order or jetpack-autoloader version resolution. Consumer plugins can be added/removed without breaking the shared resource lifecycle.
- Made harder: newcomers must recognize this pattern isn't a general A1 escape hatch. The scoped guards (a) + (b) are load-bearing — omitting either breaks the coordination contract. Reviewers must audit that the deviation is scoped to ONE bootstrap per resource, not spread across the entry file.
- Reconsider: if a future feature attempts to apply this pattern to a NON-cross-plugin resource (e.g., an internal admin surface, a plugin-scoped REST route), that use is OUT OF SCOPE and violates A1. The A1 escape hatch is specifically for vendor packages that own cross-plugin resources — not for local shortcuts around the Loader.

**Evidence**
- `acrossai-abilities-manager/acrossai-abilities-manager.php:142–154` — reference implementation (`\AcrossAI_Main_Menu\SettingsPage` bootstrap with `did_action('acrossai_main_menu_bootstrapped')` guard)
- `acrossai-abilities-manager/acrossai-abilities-manager.php:82–96` — sibling FR-030-analog pattern (pre-activation vendor autoload guard on `activate_<plugin>` priority 1)
- `acrossai-mcp-manager/acrossai-mcp-manager.php` — Feature 010 T014a + T014b will mirror both patterns
- Feature 010 spec.md FR-029 / FR-030 / FR-031 documents the deviation contract
- Feature 010 tasks.md T012a executes the D15 + DEV4 registration

**Where to look next**
Read the reference plugin's `acrossai-abilities-manager.php` bootstrap block (lines 82–154) as the canonical shape. Note the two-guard pattern in the `add_action('plugins_loaded', ...)` closure and the priority-1 activation guard's rationale (must run BEFORE the default-priority-10 register_activation_hook callback that would fatal on missing vendor). See INDEX.md `DEV4` row for the deviation's registration + review criteria. Future consumers of the same shared package should copy this bootstrap shape verbatim, adjusting only the plugin-specific slug in error messages.

---

### DEC-BERLINDB-TABLE-REQUEST-BOOT — BerlinDB Table subclasses require request-time instantiation, not just activation-time

**Status**: Active (Feature 011)
**Scope**: Every plugin subclassing BerlinDB Core `\BerlinDB\Database\Kern\Table`
**Tags**: berlindb, boot, request-lifecycle, main-php, generalizable

**Why this is durable**
BerlinDB v3's `Query` subclass looks up its physical table name (`$wpdb->prefix . $name`) from a global DB interface that is populated by the Table subclass's `sunrise()` boot. `sunrise()` runs from the Table constructor. If no Table subclass is instantiated during a given request lifecycle, the Query base class falls back to using `$table_alias` as the FROM clause — producing `Table 'db.<alias>' doesn't exist` fatals at the first Query hit. Calling `Table::instance()` in `Activator::activate()` satisfies DDL lifecycle only; each subsequent request still needs its own `Table::instance()` call to populate the DB interface for that request's Query subclasses.

Feature 011 observed this in the wild on 2026-07-02:
- Admin `?page=acrossai_mcp_manager` → `MCPServerListTable::prepare_items` → `Query::query` → `Table 'local.mcps' doesn't exist`
- REST `rest_api_init` → `MCP\Controller::has_any_enabled_server` → `Query::query` → same fatal

Both code paths bypassed `Activator::activate()` (which only runs at plugin activation) and had no other trigger to instantiate the Table.

**Decision**
Every plugin that hosts BerlinDB Table subclasses MUST instantiate all of them at request time from `Main::load_hooks()` (or equivalent boot method that fires per request during `plugins_loaded`). The call site MUST be reachable BEFORE any admin or public hook that could invoke a Query subclass. Do NOT rely on activation-time instantiation to persist across requests.

For `acrossai-mcp-manager`: a `Main::bootstrap_database_tables()` private helper is invoked from `Main::load_hooks()` inside the `apply_filters( 'acrossai_mcp_manager_load', true )` gate, BEFORE `define_admin_hooks()` and `define_public_hooks()`. It calls `Table::instance()` on each of the four Database\<Module>\Table subclasses.

**Tradeoffs**
- Gained: correct request-time DB interface registration for every Query subclass. Zero runtime SQL fallback to `$table_alias`. Consistent boot semantics per request.
- Made harder: newcomers may add a new BerlinDB Table subclass without adding it to `bootstrap_database_tables()`, causing silent alias-as-FROM fatals on the first request the Query hits. Reviewers must audit that every BerlinDB Table subclass added to `includes/Database/<Module>/Table.php` is also wired into `bootstrap_database_tables()`.
- Reconsider: if BerlinDB Core changes to lazy-register Tables at Query-construction time, this pattern becomes redundant. Until then, the explicit request-time boot is load-bearing.

**Evidence**
- `includes/Main.php` — `Main::bootstrap_database_tables()` (Feature 011 T044)
- `includes/Main.php` — `Main::load_hooks()` invocation
- Sibling plugin `acrossai-abilities-manager/includes/Main.php:349` — `AcrossAI_Abilities_Table::instance()` call inside `define_admin_hooks()` (canonical shape; Feature 011 hoists it to `load_hooks()` for public/REST coverage)
- Live error log 2026-07-02 16:12:56 UTC (`docs/planings-tasks/011-berlindb-migration.md` Emergent Fixes section)
- Feature 011 spec.md FR-028

**Where to look next**
Read `Main::bootstrap_database_tables()` for the canonical shape. Note the call happens INSIDE the `apply_filters` gate (respects the plugin-disable filter) but BEFORE both `define_admin_hooks()` and `define_public_hooks()` (so admin, public, and REST request paths all see the registered Tables). Future BerlinDB-backed features MUST add their Table subclass to this method — audit gate at code review.

---

### DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION — Do not import Kern base class when subclass name matches

**Status**: Active (Feature 011)
**Scope**: Any file that declares a subclass of `\BerlinDB\Database\Kern\{Table,Schema,Query,Row}` using the SAME class name as the parent
**Tags**: berlindb, namespace, class-collision, subclass-naming, workflow-template

**Why this is durable**
This plugin uses a subdirectory-per-module layout (`includes/Database/<Module>/{Table,Schema,Query,Row}.php`) with UNPREFIXED class names — each file declares a class literally named `Table`, `Schema`, `Query`, or `Row` in the module's namespace. If such a file adds `use BerlinDB\Database\Kern\Table;` for readability, PHP imports Kern's `Table` as the local short name `Table` — colliding with the subclass declaration in the same namespace and producing `Cannot redeclare class ... previously declared as local import` fatals. The error surfaces at `php -l`, at `class_exists()` calls, and at autoload time.

Feature 011 hit this across 14 of 16 subclass files during workflow execution (two agents happened to write the correct pattern; twelve did not). The bug was caught by post-workflow `php -l` and fixed by removing the `use` line — the class declarations already used leading-`\` FQN (`extends \BerlinDB\Database\Kern\Table`), so no import was needed.

The sibling plugin `acrossai-abilities-manager` avoids this pattern by prefixing subclass names (`AcrossAI_Abilities_Table` extends `Table`) — so the `use BerlinDB\Database\Kern\Table;` is safe there. That pattern does NOT transfer to a subdir-per-module layout where the subclass shares the parent's short name.

**Decision**
When a plugin file declares a class subclassing a BerlinDB Kern class using the SAME class name (`class Table extends \BerlinDB\Database\Kern\Table`), do NOT add a `use BerlinDB\Database\Kern\<ClassName>;` import. Two safe alternatives:

1. Drop the `use` entirely; extend via leading-`\` FQN (`extends \BerlinDB\Database\Kern\Table`). This is Feature 011's pattern in the `includes/Database/<Module>/` layout.
2. Alias the import (`use BerlinDB\Database\Kern\Table as KernTable; class Table extends KernTable`). Marginally cleaner if the parent is referenced multiple times in the file body.

Either alternative is acceptable; the collision is not. The Kern parent MUST be referenced somewhere in the file (leading-`\` FQN in `extends`, or aliased-form in `extends`) — bare `Table` in `extends` will silently resolve to the CURRENT namespace's `Table` (i.e., recursion into itself) and fail at instantiation.

**Tradeoffs**
- Gained: subclass files with parent-matching names parse cleanly under PHP 8.1+ and load without collision.
- Made harder: newcomers copying a similar-shape file from the sibling plugin (which uses prefixed subclass names) may not realize the `use` line has to go when the subclass name matches. Reviewers must audit every new BerlinDB Kern subclass file for this pattern.
- Reconsider: if this plugin migrates to a flat `includes/Database/AcrossAI_MCP_<Module>_<Class>.php` layout with prefixed class names, the sibling plugin's `use` pattern becomes safe and this decision becomes moot. That migration is out of scope for Feature 011.

**Evidence**
- Feature 011 workflow template bug: 14 of 16 subclass files initially had the collision; caught by post-workflow `php -l` (see `docs/planings-tasks/011-berlindb-migration.md` Emergent Fixes section)
- Post-fix: `find includes admin public *.php -name '*.php' | xargs php -l` returns zero errors
- Sibling plugin `acrossai-abilities-manager` uses prefixed subclass names — the `use` is safe there; the collision is specific to the subdir-per-module unprefixed-class-name layout
- Feature 011 spec.md FR-020 (caller-sweep enumeration, indirectly related as caller files still `use` these subclasses)

**Where to look next**
Read one of Feature 011's Table subclass files (e.g., `includes/Database/MCPServer/Table.php`) — note the ABSENCE of `use BerlinDB\Database\Kern\Table;` at the top and the presence of `class Table extends \BerlinDB\Database\Kern\Table` (leading-`\` FQN). Any future BerlinDB Kern subclass in this plugin's subdir-per-module layout MUST follow the same pattern.

---

### DEC-VENDOR-SETTINGS-TAB-INTEGRATION — Canonical shape for consuming acrossai-co/main-menu's shared Settings page

**Status**: Active (Feature 012)
**Scope**: Any AcrossAI plugin adding a tab to the shared `?page=acrossai-settings` page owned by the `acrossai-co/main-menu` vendor package
**Tags**: vendor-integration, settings-api, main-menu, dataform-carveout, class-exists-omission

**Why this is durable**
The vendor package `acrossai-co/main-menu` exposes ONE shared Settings page that every AcrossAI plugin adds tabs to via the `acrossai_settings_tabs` filter. The vendor's `PageRenderer::render()` emits ONE `settings_fields('acrossai-settings')` nonce + `options.php` handoff for the entire form — so every plugin's `register_setting()` call MUST target the shared `'acrossai-settings'` option group, NOT the per-tab page slug. Getting this wrong makes Save appear to work but silently discard the tab's values with no operator-visible error. Feature 012 is the first AcrossAI plugin outside `acrossai-abilities-manager` to consume this contract — codifying the shape here prevents every future consumer plugin from rediscovering the trap.

**Decision**
When adding a tab to the shared AcrossAI Settings page, follow these four rules verbatim:

1. **Filter hook**: hook `register_tab( $tabs ): array` onto the `acrossai_settings_tabs` filter. Normalize non-array input with `if ( ! is_array( $tabs ) ) { $tabs = array(); }`. Append `array( 'slug' => TAB_SLUG, 'label' => __( ..., 'text-domain' ), 'priority' => <int> )`.
2. **Per-tab page slug**: inside `register_settings()`, derive the page slug via `$page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );` — this returns `'acrossai-settings-<tab>'`. Pass `$page_slug` as the 4th arg to `add_settings_section()` and the 4th arg to `add_settings_field()`.
3. **Shared option group**: pass literal `'acrossai-settings'` (NOT `$page_slug`) as the 1st arg to `register_setting()`. This is the load-bearing invariant — the vendor's `settings_fields('acrossai-settings')` call inside `PageRenderer::render()` produces a nonce that covers ONLY option keys registered under this group; keys registered under any other group would be silently discarded by `options.php`.
4. **Class member ordering**: match the sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` verbatim — `protected static $instance = null;` → `public static function instance(): self` → `private function __construct() {}` → `public const TAB_SLUG = '...';` (declared AFTER the singleton scaffolding, not before) → `register_tab()` → `register_settings()` → render methods. Do NOT declare the class `final`. Do NOT add a `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard around the `tab_page_slug()` call — the vendor package is a hard-require in composer.json (D15 / DEV4), so the class is guaranteed present at admin_init.

Also — this pattern is a first-party §IV DataForm carve-out: the shared Settings page's `PageRenderer` is a WordPress Settings API surface owned by the vendor package, not a DataForm module. Per Constitution §IV, admin surfaces prefer DataForm — this vendor page is the accepted exception because the vendor contract mandates the Settings API and the shared-page architecture makes DataForm coordination across plugins intractable.

**Tradeoffs**
- Gained: cross-plugin Save round-trip works correctly on the first attempt; every operator-facing toggle persists via ONE nonce + ONE options.php submission. Consumer plugins can add tabs without touching vendor code.
- Made harder: `register_setting( 'acrossai-settings', ..., ... )` looks WRONG to a WordPress dev unfamiliar with the vendor contract — they may "fix" it by changing the option group to the per-tab page slug, silently breaking Save. The docblock above `register_settings()` MUST explain the invariant so reviewers catch the pattern.
- Reconsider: if `acrossai-co/main-menu` is ever demoted from hard-require to optional integration, the omitted `class_exists()` guard becomes a fatal-error trap on any site that has the plugin installed without the vendor package. Re-evaluate this decision (specifically the "no guard" rule in item 4) if the composer.json dependency shape ever changes.

**Evidence**
- `admin/Partials/SettingsMenu.php` (Feature 012 T003) — canonical shape for this plugin
- `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` (Feature 038 sibling) — reference shape
- `vendor/acrossai-co/main-menu/README.md` sections 133-207 — filter contract + `tab_page_slug()` helper documentation
- `vendor/acrossai-co/main-menu/src/SettingsPage.php:30-32` — `tab_page_slug()` signature returning `SETTINGS_SLUG . '-' . sanitize_key($tab_slug)`
- Feature 012 spec.md FR-002..FR-013 + CONSTRAINTS block

**Where to look next**
Any future AcrossAI plugin that adds a tab to the shared Settings page: read `admin/Partials/SettingsMenu.php` in this plugin and the sibling `acrossai-abilities-manager` first. Verify the 4 rules above are satisfied verbatim. Do NOT invent alternative shapes — the vendor contract is a fixed target, not a suggestion.

---

### DEC-UNINSTALL-OPT-IN-GATE — uninstall.php MUST preserve all data by default; destructive teardown gated on explicit opt-in

**Status**: Active (Feature 012)
**Scope**: `uninstall.php` and any future destructive teardown code in this plugin
**Tags**: uninstall, safety-invariant, wp-org-guideline-5, opt-in, behavior-change

**Why this is durable**
Pre-Feature-012 `uninstall.php` unconditionally dropped `acrossai_mcp_oauth_tokens` and `acrossai_mcp_oauth_audit` on plugin uninstall — meaning any operator who briefly uninstalled the plugin (say, to troubleshoot an activation error, or to try a different version) irreversibly lost their OAuth token store, silently invalidating every issued Claude auth token with no visible warning. WordPress.org plugin guideline #5 explicitly requires preserve-by-default on uninstall: "Uninstall procedures must not affect any user setting or plugin data by default." Feature 012 fixed the pre-Feature-012 violation by inverting the default and wiring an opt-in checkbox on the new MCP settings tab.

**Decision**
`uninstall.php` MUST short-circuit at the TOP (immediately after the `WP_UNINSTALL_PLUGIN` check) with:

```php
if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {
    return;
}
```

Only when the operator has explicitly ticked the "Delete all data on uninstall" checkbox on the MCP settings tab (which sets the option to `1`) does destructive teardown run. Every line of destructive SQL MUST live AFTER this gate. The default value passed to `get_option()` MUST be `0` — a missing option means preserve-by-default. Do NOT invert the default to `1`; the value the operator saved is the SOLE source of truth.

Future features that need destructive teardown MUST reuse this gate (not add a second one). Adding a second gate that bypasses this one — even for a "safer" cleanup — is prohibited by this decision; consolidate any new teardown into the existing branch below the gate.

**Tradeoffs**
- Gained: satisfies WordPress.org guideline #5; matches sibling `acrossai-abilities-manager` verbatim; operators who uninstall briefly do not lose data.
- Made harder: operators who INTENDED the pre-Feature-012 behavior (uninstall = wipe OAuth tables) must now tick the checkbox first. Documented in README.txt Unreleased changelog as BEHAVIOR CHANGE.
- Reconsider: never. Preserving user data on uninstall is a WordPress.org contract, not a policy choice — even if a future feature justifies a "helpful cleanup," it belongs BELOW the gate, not around it.

**Evidence**
- `uninstall.php` (Feature 012 T009) — canonical shape with gate at top
- Feature 012 spec.md FR-019..FR-023 + spec CONSTRAINTS block
- Feature 012 security review (`docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md`) SEC-012-001
- Sibling `acrossai-abilities-manager/uninstall.php` — identical pattern (predates this decision but validates it)

**Where to look next**
Read `uninstall.php` and count how many `if`, `return`, or `wp_die` statements precede the first `$wpdb->query()` call. If there is exactly ONE gate (the `acrossai_mcp_uninstall_delete_data` check) and it lives BEFORE `global $wpdb;`, the invariant is intact. If someone later adds another gate above the `WP_UNINSTALL_PLUGIN` check that bypasses the delete-data gate, that is a regression.

---

### DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG — Standalone admin submenus for read-only DB-inspection views SHOULD be pruned when a lighter inspection path exists

**Status**: Active (Feature 012)
**Scope**: Plugin admin surface (submenu inventory under the shared `acrossai` parent menu)
**Tags**: admin-surface, pruning, a9-subtractive-precedent, dev1-scope-narrowing, list-table

**Why this is durable**
Feature 011 codified A9 (canonical whitelist additive-only) after multiple regressions where subtractive edits to `AdminPageSlugs::plugin_screen_ids()` broke asset enqueuing on legacy screens. Feature 012 removes the CLI Auth Log admin submenu (a pure WP_List_Table view over `wp_acrossai_mcp_cli_auth_logs`) — the FIRST justified subtractive edit under A9. Without this precedent, every future subtractive edit would either be blocked or would land without the accompanying submenu removal, corrupting the "screen IDs correspond to registered submenus" invariant A9 protects.

**Decision**
Two related rules, applied together:

1. **Prune rule (surface-side)**: standalone admin submenus that render a read-only WP_List_Table view SHOULD be removed when the same inspection is available via a lighter path (WP-CLI, per-server tab on an existing page, dashboard widget). The underlying DB layer + Query/Row classes MUST be preserved when runtime consumers (e.g., OAuth flow) still depend on them.
2. **A9 subtractive precedent (whitelist-side)**: subtractive edits to `AdminPageSlugs::plugin_screen_ids()` are allowed ONLY when the corresponding submenu page is removed in the SAME feature. The removed screen-ID entries and the removed `add_submenu_page()` call MUST land in the same commit (or same feature branch) so the "screen IDs correspond to registered submenus" invariant is never violated at HEAD.

This also narrows the scope of DEV1 (WP_List_Table exception to §IV DataForm mandate): the exception PERMITS WP_List_Table for legitimate read-only inspection UI on a shared page or dashboard widget; it does NOT permit adding a dedicated top-level submenu for pure read-only inspection when a lighter path is available.

**Tradeoffs**
- Gained: shrinks the plugin admin surface footprint; matches the "one submenu per interactive/mutating capability" heuristic; codifies the first A9 subtractive precedent so future prunes can proceed cleanly.
- Made harder: operators who liked the standalone CLI Auth Log page must now use WP-CLI (`wp db query "SELECT ... FROM wp_acrossai_mcp_cli_auth_logs"`) or wait for a future per-server tab. Documented in README.txt Unreleased changelog. No REST or CLI command surface is deleted — only the admin submenu.
- Reconsider: if a future feature adds interactive/mutating capability to the CLI Auth Log (bulk-delete stale rows, mark-as-completed manual override, resend approval email), THAT is a legitimate reason to re-add a submenu. The prune is not permanent — it applies only while the view is purely read-only.

**Evidence**
- `admin/Partials/Menu.php` (Feature 012 T014) — position-3 `add_submenu_page` removed
- `admin/Partials/CliAuthLogListTable.php` — deleted (Feature 012 T013)
- `includes/Utilities/AdminPageSlugs.php` (Feature 012 T015) — CLI_AUTH_LOG const + 2 whitelist entries removed
- `admin/Partials/Settings.php` (Feature 012 T016) — `render_cli_auth_log_page()` removed
- Preserved: every file under `includes/Database/CliAuthLog/**` (Table, Schema, Query, Row, Recorder) — OAuth flow consumes these at runtime
- Feature 012 spec.md FR-024..FR-028 + spec User Story 4
- Feature 012 security review SEC-012-006

**Where to look next**
Before removing any admin submenu in a future feature: (1) verify the target view is purely read-only (no forms, no actions, no toggles); (2) verify a lighter inspection path exists (WP-CLI query, existing tab, dashboard widget); (3) verify the DB layer + Query/Row classes have runtime consumers OUTSIDE the admin submenu — if yes, preserve them; if no, the DB layer can be removed too. Land the submenu removal + `plugin_screen_ids()` entry removal + render-method removal in the SAME commit to preserve the A9 invariant at HEAD.

---

### DEC-SERVER-TAB-CLASS-HIERARCHY — Template-method + Registry pattern for multi-tab admin surfaces

**Status**: Active (Feature 013)
**Scope**: Any admin surface with 3+ tabs on a per-record edit page.
**Tags**: template-method, registry, singleton, admin-partials, dry

**Why this is durable**
F013 refactored the per-server-edit page from a monolithic `render_*_tab` switch (~1,200 LOC target) into a per-tab class hierarchy under `admin/Partials/ServerTabs/`. The pattern proved at 11 concrete tab classes + Registry singleton + AbstractServerTab base with shared helpers. Any future multi-tab admin surface should default to this pattern rather than switch statements.

**Decision**
Multi-tab admin surfaces MUST follow this shape:
1. **Base class** with `slug()`, `label()`, `render_body()` abstract methods + `visible_for()` default + `final render()` template method + shared HTML helpers (`open_form`, `nonce_field`, etc.).
2. **Registry singleton** — F012 SettingsMenu member ordering. Public methods: `all_tabs()`, `visible_tabs()`, `render()`.
3. **Concrete tabs**: `final class`, single-responsibility, `visible_for()` opt-in override.
4. **Dispatch**: enclosing screen calls `Registry::instance()->render( $slug, $context )` after nav emit.

**Tradeoffs**
- Gained: DRY enforcement, isolated unit tests, trivial to add tabs.
- Made harder: legacy slug back-compat is on the caller (see F013 Settings.php `$legacy_slug_map`).
- Reconsider: never for admin tabs.

**Evidence**
`admin/Partials/ServerTabs/AbstractServerTab.php` + `Registry.php` + 11 concrete tab classes. F012 `SettingsMenu.php` is the singleton precedent.

**Where to look next**
Any future feature adding 3+ tabs to a per-record admin page: read `AbstractServerTab.php` + `Registry.php` first; do NOT reinvent a switch statement.

---

### DEC-CLIENT-RENDERER-PUBLIC-API — Public Renderer layer for cross-context (admin + third-party) reuse

**Status**: Active (Feature 013; annotated F016 2026-07-07) — API is `@experimental` until 1.0.0.
**Scope**: MCP client-config UI rendered from admin AND external contexts (BuddyBoss, WooCommerce, other AcrossAI plugins).
**Tags**: public-api, renderer, cross-context, experimental, shortcode, security-critical

**Post-F016 (2026-07-07)**: Renderer count shrinks from 3 to 2. Retired: `ClaudeConnectorBlock`. Surviving: `NpmClientBlock`, `MCPClientsBlock`. Dispatch map (in `ClientRendererController::dispatch_render_action`) shrinks from 3 entries to 2 (`npm`, `clients`). Shortcodes shrink from 3 to 2 (`acrossai_mcp_npm_block`, `acrossai_mcp_clients_block`). Base class (`AbstractClientRenderer`), REST endpoint (`POST /generate-app-password`), and all 4 sanctioned entry points (static call, action hook, context filter, shortcodes) remain intact — the surface merely reduces. The `@experimental` allowance until 1.0.0 covers this reduction; third-party consumers that hardcoded `'claude-connector'` see silent no-op per the dispatcher's unknown-slug guard.

**Why this is durable**
F013 introduces `public/Renderers/` — a new plugin subsystem exposing client-configuration Blocks to third-party plugins with **zero code duplication** vs. admin rendering. The Renderer layer is the ONLY sanctioned integration surface — third parties never reach into `admin/Partials/`. Canonical pattern for future MCP-adjacent third-party integrations.

**Decision**
Any admin surface displaying MCP client config (JSON blocks, "Generate App Password" button, config file path) MUST render via `public/Renderers/` using ONE of four entry points:
1. **Static method**: `<Block>::instance()->render( $server_id, $context )`.
2. **Action hook**: `do_action( 'acrossai_mcp_render_client_block', $slug, $server_id, $context )` — unknown slugs silently no-op.
3. **Context filter**: `apply_filters( 'acrossai_mcp_client_block_context', $context, $slug, $server_id )` — ONLY sanctioned defaults customization point; `(array)`-cast at boundary (SEC-013-003).
4. **Shortcodes**: `[acrossai_mcp_npm_block server="X"]` + 2 others.

Plus `apply_filters( 'acrossai_mcp_client_classes', $default_fqns )` for MCPClientsBlock sub-nav extension; invalid FQNs silently skipped via `class_exists() + is_subclass_of()` (SEC-013-008).

**Security invariants** (bound permanently):
- **Cap check via context.cap** — never hardcoded `manage_options`. BuddyBoss passes `cap='read'`.
- **App Password locked to `get_current_user_id()`** — enforced at UI (button disabled) AND REST (403). Defense against admin-impersonation.
- **Cross-context nonce binding** — action format: `'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug`. Cross-context replay returns 403.
- **F012 toggle enforcement inside Renderer** — NpmClientBlock + ClaudeConnectorBlock check their gate options inside `render_body()`, not caller. MCPClientsBlock NOT gated.

**§IV DataForm carve-out** — Renderer displays JSON + emits WordPress-core App Password generation. NOT a data-entry form. Same precedent as F012 DEC-VENDOR-SETTINGS-TAB-INTEGRATION.

**Stability**: `@since 0.0.6 @experimental May change without notice before 1.0.0`. Promotion to semver at future 1.0 tag.

**Tradeoffs**
- Gained: third parties embed MCP UI in ONE line; consistent security across contexts.
- Made harder: 0.x signature changes are breaking for integrators (mitigated by @experimental disclosure).
- Reconsider: at 1.0 tag, promote to semver-stable with deprecation cycle.

**Evidence**
`public/Renderers/{AbstractClientRenderer,NpmClientBlock,MCPClientsBlock,ClaudeConnectorBlock}.php`; `includes/REST/ClientRendererController.php`; `docs/integrations/{buddyboss,woocommerce}-example.md`; `tests/phpunit/Public/Renderers/{AbstractClientRendererTest,PublicApiTest}.php`.

**Where to look next**
Third-party integrators: `docs/integrations/*-example.md`. Future MCP-adjacent features: use this Renderer pattern — do NOT create parallel `admin/Partials/`-only surfaces.

---

### 2026-07-03 — D16 — Template-method helpers pre-plan an optional override for the value most likely to vary

**Status**
Active

**Why this is durable**
Directly prevents recurrence of a real F013 hole: `AbstractServerTab::nonce_field()`
hard-coded the `'acrossai_mcp_manager_server_' . $id` action name. UpdateServerTab
and DangerZoneTab both need distinct actions (`acrossai_mcp_update_<id>`,
`acrossai_mcp_delete_<id>`) — so they bypassed the helper and emitted raw
`wp_nonce_field()` calls, tripping the FR-026 grep gate. The architecture review
caught it post-implementation; the fix was to add an optional
`$custom_action_override` param. Had this been designed in from T004, the two
tabs would have used the helper from day one and the grep gate would have stayed
green throughout the port.

**Decision**
When designing a shared/template-method helper (open_form, nonce_field, config
block, etc.), ask upfront: "what value in this helper is most likely to vary
across ~10% of consumers?" — expose that as an optional-null / empty-string
parameter with a sensible default. If two values are candidates (e.g. nonce
action AND form target URL), expose both. Do not wait for the first override
request; the helper's DRY guarantee depends on the escape hatch existing before
consumers reach for the raw call.

**Tradeoffs**
- Gained: helper stays authoritative even for edge-case consumers; grep gates
  banning raw call sites remain green; consumers don't need to bypass DRY.
- Made harder: helper signatures grow marginally; adds one extra param to
  document. Trivial vs. the audit cost of a bypassed helper.
- Reconsider: when the number of override params exceeds ~3, the helper is
  probably wrong-shaped; split into two helpers or convert to a config-object
  parameter.

**How to apply**
- New helper: enumerate hard-coded values → expose each as optional with sensible
  default → test the default path AND the override path in the same PHPUnit case.
- Existing helper flagged for raw-call bypass: add the override param, refactor
  the bypassing consumer, re-run the grep gate — do NOT add doc carve-outs to
  the grep gate to grandfather the bypass.

**Evidence**
`admin/Partials/ServerTabs/AbstractServerTab.php::open_form()` +
`::nonce_field()` (F013 architecture review R1); `UpdateServerTab.php` +
`DangerZoneTab.php` (refactored consumers); spec.md FR-026 (the grep gate that
surfaced the hole).

**Where to look next**
Any future feature adding a shared HTML helper: apply the "vary-first" checklist
in the Decision block above. See DEC-SERVER-TAB-CLASS-HIERARCHY for the
containing template-method pattern.

---

### 2026-07-03 — D17 — A1 hook-registration by transitivity: init-time bootstrap methods satisfy A1 at the outer Loader boundary

**Status**
Active

**Why this is durable**
F013's `ClientRendererController::register_shortcodes_and_actions()` calls
`add_shortcode()` × 3 + `add_action('acrossai_mcp_render_client_block', ...)`
directly inside the method — not via the Loader. A strict reading of A1 ("all
hook registration lives exclusively in `includes/Main.php`") would flag this as
a violation. But the METHOD is Loader-wired at Main.php via
`$this->loader->add_action('init', $client_renderer_rest, 'register_shortcodes_and_actions')`.
The outer entry point IS Main.php-owned; the inner calls are a bootstrap detail.
Forcing every shortcode + inner action registration through the Loader would
require closures (which Loader can't wire without wrapper classes) or one class
per shortcode — explosion of surface area for zero security benefit. Codifying
the transitivity rule prevents recurring false-positive A1 violations in future
architecture reviews.

**Decision**
A1 is satisfied when the OUTER entry point that eventually causes hook
registration is Main.php-Loader-wired. Inner `add_shortcode()` / `add_action()`
/ `add_filter()` calls made from within a Loader-wired method are permitted —
they inherit A1 conformance by transitivity. This applies to init-time bootstrap
methods (typically wired on `init`, `admin_init`, or `rest_api_init`) that
register a bounded set of related hooks as a unit.

**Tradeoffs**
- Gained: Practical middle ground — code organization stays clean (related
  shortcodes grouped in one method) without exploding the Main.php surface. No
  fake wrapper classes just to satisfy Loader signature requirements.
- Made harder: Grep gates for `add_shortcode(` / `add_action(` / `add_filter(`
  outside Main.php now need to whitelist Loader-wired bootstrap methods.
  Enforcement shifts to code review + naming convention (`register_*_hooks()`
  suffix signals intent).
- Reconsider: If a bootstrap method grows beyond ~10 hook registrations, it's
  probably wrong-shaped — split into per-concern bootstrap methods or promote to
  its own Main.php-wired sub-controller.

**How to apply**
- Reviewer flags `add_shortcode()` / `add_action()` inside a non-Main.php method:
  trace up. If the containing method is Loader-wired on an appropriate WP
  action, mark A1 satisfied.
- New bootstrap method: name it `register_*_hooks()` or
  `register_*_and_actions()` so intent is legible.
- Do NOT rewrite existing Loader-wired bootstrap methods just for style.

**Evidence**
`includes/REST/ClientRendererController.php::register_shortcodes_and_actions()`
(F013 shipping example); `includes/Main.php:499`
(`$this->loader->add_action('init', $client_renderer_rest, 'register_shortcodes_and_actions')`
outer wiring); A1 in `docs/memory/ARCHITECTURE.md` + memory-synthesis (the base
rule this clarifies).

**Where to look next**
DEC-CLIENT-RENDERER-PUBLIC-API — the public API surface these hooks implement.
A1 in memory-synthesis — the base constraint this decision refines.

---

### 2026-07-04 — DEC-ACCESS-CONTROL-V2-ADOPTION — canonical v2 wrapper pattern for AcrossAI-family plugin consumption

**Status**
Active (F015 — supersedes D8's `^1.0` version pin in-place; amends A8 version reference)

**Why this durable**
Feature 015 discovered that the plugin shipped `wpb-access-control ^2.0.0` in
composer.json but every consumer (AccessControlTab.php:65, CliController.php:333,
Main.php:432 comment) targeted v1's `::instance()` singleton API — which does
not exist in v2. Three fatal call sites. The sibling plugin
`acrossai-abilities-manager` had already solved this with the
`AcrossAI_Abilities_Access_Control` wrapper class shape. F015 copy-adapted that
pattern verbatim. Codifying the pattern here prevents future features (or a
third sibling plugin adopting v2) from re-inventing the wrapper.

**Decision**
Any AcrossAI-family plugin consuming `wpboilerplate/wpb-access-control ^2.0.0`
MUST wrap the v2 `AccessControlManager` in a plugin-scoped singleton wrapper
class with:
- `PROVIDERS_FILTER` class constant (plugin-specific hook tag)
- `TABLE_SLUG` class constant (drives DB table name, cache group, REST route
  prefix — MUST match `^[a-z0-9_]{1,32}$` per v2 `Slug::PATTERN`)
- `is_available()` guard (fail-open when package class absent — matches sibling
  DEC-PERM-CB)
- `boot_manager()` lazy-init with `new AccessControlManager( PROVIDERS_FILTER,
  TABLE_SLUG )` — NEVER v1's `::instance()`
- `get_manager(): ?AccessControlManager` accessor
- `register_rest_api()` REST route registration delegate
- `maybe_show_library_notice()` admin notice on package absence
- Activator MUST call `(new RuleTable(TABLE_SLUG))->maybe_upgrade()` at plugin
  activation, gated by `class_exists()` defense-in-depth (SEC-015-001)
- `uninstall.php` MUST purge the namespace + drop the table + delete the version
  option — but ONLY when the plugin-specific opt-in gate fires (preserve-by-default
  per DEC-UNINSTALL-OPT-IN-GATE)
- The 3 built-in providers (`WpRoleProvider`, `WpUserProvider`, `WpCapabilityProvider`)
  MUST be registered via `add_filter( PROVIDERS_FILTER, ..., 'register_default_providers' )`
- The AccessControlBlock UI uses a single-provider `<select>` picker
  (`everyone` / `wp_role` / `wp_user` / `wp_capability`) with a conditional row
  showing the values for the chosen provider — matches sibling plugin's User
  Access UX (Clarifications Q4). Rule storage: one `set_rule($ns, $key,
  $provider_type, $values_array)` call per save, or `clear_rule($ns, $key)` when
  picker = everyone. Capability picker exposes the FULL WP capability set via
  `get_available_capabilities()` (SAFE_CAPABILITIES allow-list from initial
  F015 draft is SUPERSEDED per Q4 — admins bypass every rule per v2
  access-hierarchy step 2, so exposing high-privilege capabilities is not a
  privilege-escalation vector).

**Tradeoffs**
- Gained: consistent v2 consumption pattern across the AcrossAI plugin family;
  the wrapper contains the fail-open decision + observability contract at ONE
  boundary; the version pin can advance without touching consumers.
- Made harder: 158+ LOC of "just wrapper" code per plugin. Acceptable because
  the alternative is scattered `class_exists` guards + inline v2 API calls
  across 3+ files per plugin.
- Reconsider: if the vendor package releases a 3.x with a breaking constructor
  change, amend this DEC (not the consumers).

**Evidence**
`includes/AccessControl/AcrossAI_MCP_Access_Control.php` (F015 shipping class);
sibling plugin `acrossai-abilities-manager` at
`includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php` (same shape).

**Where to look next**
D18 (mcp_adapter_pre_tool_call filter) — the MCP-boundary enforcement hook the
wrapper's `gate_mcp_tool_call()` implements. D19 (fail-open observability
pattern) — the general pattern this wrapper's action hooks realize.
DEC-UNINSTALL-OPT-IN-GATE — the opt-in gate the uninstall block MUST honor.

**Amendment 2026-07-04 (post-implementation drift audit)**
Two decisions were made after the initial DEC entry was captured:

1. **AccessControlBlock defers to vendor's React component** (not a hand-rolled
   PHP form). The initial F015 draft had `render_body()` emit `<form
   method="post">` + three provider pickers + `submit_button()`. During
   implementation, the user directed adoption of the vendor's React
   `<AccessControl>` component (shipped at `vendor/wpboilerplate/wpb-access-control/js/AccessControl.js`)
   via a `webpack.config.js` alias `'@wpb/access-control' → …/js/AccessControl.js`
   and a new entry `src/js/access-control.js` that mounts it. `render_body()`
   now emits only `<div id="acrossai-mcp-ac-root" data-server-slug="…">Loading…</div>`.
   Persistence: vendor REST (`PUT/DELETE /wpb-ac/v1/mcp/rules/{ns}/{key}`) —
   the previously-scoped `save_access_control` action + `handle_access_control_update()`
   handler in `admin/Partials/Settings.php` are dead code, marked for removal
   in T030 (see tasks.md Phase 10).

2. **TABLE_SLUG value: `'mcp'`, not `'mcp_manager'`**. During implementation
   the user requested the DB table be named `wp_mcp_access_control` (not
   `wp_mcp_manager_access_control`) — TABLE_SLUG constant value is `'mcp'`.
   Consequences: table `{prefix}mcp_access_control`, cache group `wpb_ac_mcp`,
   version option `wpb_ac_mcp_db_version`, vendor REST namespace
   `/wpb-ac/v1/mcp/…`. The rule per this DEC — that TABLE_SLUG matches
   `^[a-z0-9_]{1,32}$` — is preserved; the specific value changed.

Both amendments are compatible with the underlying DEC (v2 wrapper pattern,
fail-open, opt-in uninstall). The vendor React adoption is a §IV DataForm
carve-out variant of the same DEC-CLIENT-RENDERER-PUBLIC-API precedent that
authorized the initial hand-rolled form — the block is still a Renderer,
just deferring rendering to a vendor-shipped component instead of emitting
PHP form HTML directly.

---

### 2026-07-04 — D18 — `mcp_adapter_pre_tool_call` is the canonical MCP-boundary enforcement hook for the AcrossAI plugin family

**Status**
Active (F015)

**Why this durable**
When Feature 015 needed to gate MCP tool invocations by `(user_id, server_id)`,
the alternatives were (a) fork the mcp-adapter package to add a new hook,
(b) hook ability-level `permission_callback` on every ability, or (c) hook
`rest_pre_dispatch` broadly. Options (a) and (b) don't compose cleanly; option
(c) is too broad. Exploration surfaced that mcp-adapter ships this exact filter
at `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`
— returning `WP_Error` short-circuits with a denied MCP response. Codifying
this as the canonical hook prevents future features from re-inventing the
enforcement path.

**Decision**
Any AcrossAI-family plugin wanting to gate MCP tool invocations based on
`(user_id, server_id)` MUST hook the `mcp_adapter_pre_tool_call` filter fired
by `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`.
Signature: `apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name,
$mcp_tool, $server )`. Return `WP_Error` with `array('status' => 403)` to
short-circuit execution with a denied MCP response. Do NOT fork mcp-adapter
to add a new hook. Do NOT hook ability-level `permission_callback` (ability-scoped,
doesn't compose across cross-cutting concerns). Loader-wire via
`Main::define_public_hooks()` per A1.

**Tradeoffs**
- Gained: single-line filter registration; no vendor fork; §V Extensibility
  Without Core Modification preserved.
- Made harder: consumer must handle the `$server` argument's late-binding —
  `$server->get_server_id()` returns the mcp-adapter's server-id string, which
  IS the F011 `server_slug` in our plugin (by convention).
- Reconsider: if mcp-adapter deprecates the filter in a future release.

**Evidence**
`includes/AccessControl/AcrossAI_MCP_Access_Control.php::gate_mcp_tool_call()`
(F015 shipping callback); `includes/Main.php::define_public_hooks()` (Loader
wiring); `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`
(vendor filter site).

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the wrapper class this filter callback lives on.
D19 — the observability pattern the deny branch realizes.

---

### 2026-07-04 — D19 — Fail-open observability pattern for security-adjacent enforcement

**Status**
Active (F015)

**Why this durable**
Feature 015's Clarifications Q2 (missing-server race) + Q3 (denial observability)
both surfaced the same shape: on defensive fail-open paths, fire a scoped
`do_action()` so operators can log the anomaly via any observability tool
(Query Monitor, custom logger, remote SIEM) without a hard dependency. The
vendor package's low-level `wpb_access_control_denied` hook fires at a
namespace-agnostic scope; F015's plugin-scoped hooks add the MCP-specific
payload (`server_slug` + `tool_name` + call-site context) the vendor hook lacks.
Codifying the pattern generalizes to any future security-adjacent enforcement
in the AcrossAI plugin family.

**Decision**
On defensive fail-open paths (missing server, unavailable vendor, invalid
provider, unknown auth context), fire a scoped `do_action()` so operators can
log the anomaly without a hard dependency. Fire-and-forget; return value
ignored. Payload MUST include enough context for operators to correlate the
event with other logs (user_id, resource_id, action_name, call-site slug).
Layered pattern: plugin-scoped hooks sit ALONGSIDE (not replacing) any
vendor-provided lower-level hooks — the plugin scope adds domain-specific
payload the vendor scope lacks.

**Tradeoffs**
- Gained: operator visibility into security-adjacent anomalies without adding
  a persistent audit table; no hard dependency on any specific logging plugin;
  zero cost when no listener is registered.
- Made harder: silent-by-default — operators upgrading without adding a
  listener won't know denials are happening. Mitigated by documenting the
  hooks in README.txt with a minimal listener snippet.
- Reconsider: if a specific compliance requirement (SOC 2, HIPAA, PCI) demands
  persistent DB audit — capture a new DEC for the persistent-audit pattern.

**Evidence**
F015's `acrossai_mcp_access_control_missing_server` (fires on race with
concurrent DELETE) + `acrossai_mcp_access_control_denied` (fires BEFORE
WP_Error/empty-list return at both enforcement sites). Documented in
`includes/AccessControl/AcrossAI_MCP_Access_Control.php::gate_mcp_tool_call()`
+ `includes/REST/CliController.php:333` (F015 shipping).

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the wrapper that carries the missing-server
hook. D18 — the MCP-boundary filter the denial hook fires alongside.

---

### 2026-07-04 — D20 — Verify vendor's default set before wiring a consumer-side default-registration filter

**Status**
Active (F015)

**Why this is durable**
Consumer plugins that hook a vendor's "register default providers/handlers/tools/etc." filter can silently double-register when the vendor's own default set grows across releases. This is a recurring risk for any AcrossAI plugin that consumes a shared vendor package (wpb-access-control, mcp-adapter, main-menu, mcp-servers-list). The trap is that the consumer-side wiring is a one-line `add_filter` that reads as harmless boilerplate — until a vendor bump doubles the registered set.

**Decision**
BEFORE wiring a consumer-side default-provider (or default-handler, default-tool, etc.) registration filter for ANY vendor package, `grep` the vendor's source for its own default set. If the vendor already covers everything you'd register, SKIP the wire and document inline. Reserve the consumer filter for genuine ADDITIONS (third-party extension points), not duplicates of the vendor's defaults. When the vendor's default set grows in a future release, the omission stays correct — no maintenance drift.

**Tradeoffs**
- Gained: no double-registration; no maintenance drift when vendor defaults grow; consumer surface stays minimal; predictable provider set even after vendor upgrades.
- Made harder: readers unfamiliar with the vendor's defaults must read the inline comment to understand the missing wire. Mitigate by keeping the comment concise + linking to the vendor source file where defaults are registered.
- Reconsider: if a vendor releases with a broken default set (bug in its own `load_providers()`), the consumer may need to temporarily wire the fallback. Guard with `class_exists()` or version check if that ever happens.

**Evidence**
F015: spec (FR-014) called for wiring 5 hooks including `add_filter( PROVIDERS_FILTER, [ ClassName, 'register_default_providers' ] )` to register 3 built-in providers (WpRoleProvider, WpUserProvider, WpCapabilityProvider). But `wpb-access-control ^2.0.0` internally registers 5 default providers (WpRole + WpUser + WpCapability + BuddyBossProfileType + MemberPressMembership) via `AccessControlManager::load_providers()`. Shipping code intentionally omits the consumer wire, documented inline at `includes/Main.php:372-376`:

```php
// NB: `register_default_providers` filter is intentionally NOT wired —
// the vendor's AccessControlManager::load_providers() already registers
// WpRoleProvider + WpUserProvider + WpCapabilityProvider + BuddyBoss +
// MemberPress as defaults. Third-party plugins can still hook the
// `acrossai_mcp_access_control_providers` filter to append their own.
```

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the vendor consumption pattern this DEC refines. Anytime a future feature adds a `add_filter( '<vendor>_default_<thing>', … )` wire, cross-reference D20 in the plan's Constitution Check. Applies to any Composer-installed vendor package that manages its own "defaults" collection via a filter — verify the vendor's own registration site before wiring.

---

### 2026-07-07 - D21 — Fresh-install-only retirement pattern

**Status**
Active (F016)

**Decision**
Retirement/teardown features MUST NOT default to shipping in-plugin schema-migration code. The default posture is:

1. **Delete** the retired classes, tests, tables, columns, hooks, options, cron events from the codebase.
2. **DO NOT** add idempotent `DROP TABLE IF EXISTS` or `delete_option()` cleanup to `Activator::activate()`.
3. **DO NOT** bump the retiring module's BerlinDB `$version` on schema-shape changes when the operator handles the physical drop manually.
4. **Publish** the manual retirement recipe in `README.txt` §Unreleased with concrete SQL + `wp cron event unschedule` steps + operator advisory (revoke-first for tokens, behavior-change notes for retired auth surfaces).
5. **KEEP** defunct table names in `uninstall.php` DROP list AS AN IDEMPOTENT SAFETY NET, AFTER the `DEC-UNINSTALL-OPT-IN-GATE` short-circuit. `DROP TABLE IF EXISTS` costs nothing when the operator has already dropped the tables manually; catches installs that skip the reactivation path.

**Escape hatch — when to REJECT this pattern**
If the plugin ships to sites with live retired-feature data AND you cannot get operator attestation (public wp.org release with unknown install base, plugin family with enterprise consumers, etc.), self-healing migration IS required. Precedent for the "self-healing" alternative: F011 (2026-07-02) shipped the phantom-version guard because the migration WAS a runtime operation, not an operator recipe.

**Rationale**
- **Smaller diff** — F016 removed ~80 LOC of migration helpers relative to the initial plan (no `ConnectorColumnMigration.php`, no idempotent DROP in Activator, no `column_exists`-gated ALTER fallback).
- **Zero test surface for migration edge cases** — no need for parametrized "does BerlinDB drop columns on version bump?" tests.
- **Eliminates Activator destructive-SQL risk** — a maintainer misreading the plan cannot add "belt and suspenders" cleanup code that runs on every reactivation.
- **Operator retains explicit control over destructive timing** — the SQL runs when the operator is ready, on their timeline, with their backup posture, not on plugin update.

**Reconsider**
- If operator attestation cannot be obtained (public plugin ship, wp.org install base).
- If the retired data has legal-retention constraints that make operator-driven deletion insufficient (GDPR right-to-be-forgotten, HIPAA disposal windows).
- If the retirement is a phased evolution where downstream consumers need a compatibility window (opposite scope from F016 — see any future "deprecation window" DEC).

**Applied**
F016 (2026-07-07) — Claude Connectors retirement.
- Operator recipe: `README.txt` §Unreleased.
- Attestation: `specs/016-remove-claude-connectors/spec.md` §Assumptions "Attestation of no live connector data" (raftaar1191@gmail.com, 2026-07-06).
- Reference implementation of the retention side (uninstall.php safety net): `uninstall.php:57-58` — DROP entries live AFTER `line 33`'s opt-in gate. Verified via F016 T038 awk assertion.

**Where to look next**
Any future retirement feature: cite this DEC in the plan's Constitution Check with the operator-attestation status. If attestation exists → apply this pattern. If not → apply F011's self-healing pattern (phantom-version guard + Activator `maybe_upgrade` calls). WORKLOG.md 2026-07-07 has the milestone narrative.
