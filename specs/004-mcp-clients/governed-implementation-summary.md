# Governed Implementation Summary — Phase 4: MCP Client Classes

**Date**: 2026-06-17 | **Branch**: `004-mcp-clients` | **Status**: ✅ READY TO MERGE

---

## Memory Context

- **Status**: Refreshed (re-used `specs/004-mcp-clients/memory-synthesis.md` from prior `/speckit-memory-md-plan-with-memory` turn)
- **Relevant Decisions applied during implementation**:
  - **D10** — Minimal-port deferral pattern: Phase 4 IS the port D10 captured deferral for. **Loop closed.**
  - **A1** — Loader-only hook registration: honored (FR-008 grep clean, zero hooks in module)
  - **A2** — Singleton pattern: **soft exemption applied** per FR-009; ready to elevate to A11 (see Memory Preservation below)
  - **A6** — `use` imports in `Includes\*`: honored (all 7 concrete clients + abstract base + test classes)
  - **B1** — Namespace silent-fail risk: mitigated (PHPStan L8 clean confirms autoloader resolves all FQNs)
  - **S3** — Token hashed storage: honored at right boundary (this module emits transit material, never stores)

---

## Security Review

- **Findings**: **Zero new findings.** The 4 Informational findings from `security-review-plan.md` (SEC-INFO-001 through SEC-INFO-004) all remain documented but produced no implementation changes:
  - SEC-INFO-001 (token plaintext in snippet) — implementation matches the documented Application Password flow
  - SEC-INFO-002 (consumer escape required) — handed off to Phase 2 RT-3 amendment (separate task list)
  - SEC-INFO-003 (placeholder English-only) — accepted; `EMPTY_TOKEN_PLACEHOLDER` is a literal class constant
  - SEC-INFO-004 (fail-fast on malformed class) — accepted; `get_all_clients()` has no try/catch by design
- **Trust boundaries**: validated by implementation. The `safe_token()`/`redact_token()` split is enforced — `safe_token()` is the only method that emits plaintext to a snippet; `redact_token()` is documented as log-only.
- **Blocking concerns**: **None.**
- **Post-implementation security gate**:
  - `grep -rnE '\$wpdb|wp_remote_(get|post)|setcookie' includes/MCPClients/` → empty ✓
  - `grep -rnE '^[^*/]*\b(add_action|add_filter)\s*\(' includes/MCPClients/` → empty ✓
  - No `serialize`/`unserialize` calls
  - No `eval`/`exec`/`system`/`shell_exec` (escapeshellarg used for ClaudeCode CLI string — properly)

---

## Architecture Review

### Violations

**None.** All 8 phases of the 30-task plan completed:

| Phase | Tasks | Status | Notes |
|---|---|---|---|
| 1 — Setup | T001–T003 | ✅ | Feature dir intact; phpcs.xml.dist baseline preserved; composer PSR-4 working |
| 2 — Foundational | T004–T007 | ✅ | **T004 P0 gate absorbed into this phase as Phase 4.0** — set up `phpunit.xml.dist` + `tests/bootstrap.php` (no WP bootstrap, per SC-003) |
| 3 — US2 base | T008–T009 | ✅ | `AbstractMCPClient` + 18 helper tests |
| 4 — US2 clients | T010–T016 | ✅ | 7 concrete clients + 1 parameterized test class covering all 7 + 14 golden fixtures |
| 5 — US1 e2e | T017–T018 | ✅ | Smoke test confirms snippet shape; runs without WP bootstrap |
| 6 — US3 extensibility | T019 | ✅ | Drop-stub test: count went 7 → 8 → 7 with zero other-file edits |
| 7 — US4 purity | T020–T022 | ✅ | All 3 grep gates clean; tests run WP-free (SC-003 verified) |
| 8 — Polish | T023–T030 | ✅ | PHPCS clean, PHPStan L8 clean, PHPUnit 67/67 green |

### Architectural verifications

- **A1** (Loader-only hooks): no `add_action`/`add_filter` executable calls in module
- **A2 exemption** (singleton): `get_all_clients()` is a static factory but it instantiates **fresh** subclass instances per call — not a singleton (no `$_instance` storage, no shared state); FR-009 honored
- **A3** (admin/Partials namespace): N/A — this module is `Includes\MCPClients`
- **A6** (`use` imports): every test class and the abstract base use `use` imports; no bare relative sub-namespace references
- **B1** (namespace silent-fail): PHPStan L8 catches mistyped class references; clean
- **B5** (public ctor double-hook): N/A — no singletons in module, no hook registrations
- **B7** (mass-assignment defense): N/A — no DB writes
- **B8** (escape-at-output): not exercised in this module — the consumer (Phase 2 RT-3) carries the obligation

### Consistency Risks

- **R-1 (resolved)**: A2-vs-FR-009 soft exemption was the architectural risk flagged at planning time. Implementation confirms the exemption works in practice — `get_all_clients()` is a static factory that returns fresh instances; classes hold no instance state; tests are trivially isolated. **A11 capture is now ready** (proposal below).

---

## Implementation Status

**✅ READY TO MERGE**

- 67/67 PHPUnit tests pass (111 assertions)
- PHPCS clean (8/8 files, zero errors after `phpcbf` auto-fix of one single→double-quote nit)
- PHPStan level 8 clean
- All 3 FR-008/FR-009/SC-003 grep gates verified
- All 30 tasks marked `[x]` in `tasks.md`

### What shipped

| Artifact | Lines |
|---|---|
| `includes/MCPClients/AbstractMCPClient.php` | 195 |
| `includes/MCPClients/ClaudeCodeClient.php` (string return) | 60 |
| `includes/MCPClients/ClaudeDesktopClient.php` (array return) | 53 |
| `includes/MCPClients/CodexClient.php` | 53 |
| `includes/MCPClients/CursorClient.php` | 53 |
| `includes/MCPClients/CustomClient.php` | 55 |
| `includes/MCPClients/GitHubCopilotClient.php` | 55 |
| `includes/MCPClients/VSCodeClient.php` | 53 |
| `tests/phpunit/MCPClients/AbstractMCPClientTest.php` | 188 |
| `tests/phpunit/MCPClients/ConcreteClientsTest.php` | 161 |
| `tests/phpunit/MCPClients/fixtures/*` (14 files) | 178 |
| `tests/bootstrap.php` | 28 |
| `phpunit.xml.dist` | 33 |
| **Total** | **~1,224 lines** |

### Phase 4.0 side benefit

Setting up the PHPUnit harness in this phase **unblocks Phase 2's 14 deferred test tasks** (US1 menu test, US2 list-table tests, US3 edit-tab tests, US4 notice tests, US5 asset-guard tests, US6 loader audit test — all previously blocked behind harness setup). Phase 2 RT-4 can now be marked superseded.

---

## Refactor Tasks

**None.** Zero architectural violations detected. The MCPClients module is the cleanest module in the codebase by design:

- Zero hooks
- Zero DB calls
- Zero HTTP calls
- Zero cookie/header writes
- Zero global state
- Zero deserialization
- Zero session handling

All 4 spec security findings remain documented but produced no remediation tasks.

---

## Constitution Update Proposals

**None within this phase.** However:

The **A2-vs-FR-009 soft exemption** is now proven in practice and ready to be elevated from spec-level + plan-level documentation to durable architectural rule. Proposed as memory entry **A11** below (not a constitution amendment; constitution stays at v1.0.0).

---

## Durable Memory Preservation — A11 Capture

**Status**: **READY TO CAPTURE** (was queued as "post-implementation" per `governance-summary.md`).

Proposed entry for `docs/memory/ARCHITECTURE.md`:

```
### 2026-06-17 - Pure Service Classes Are Exempted From the Singleton Rule (A11) [Feature-004]

**Status**: Active

**Why durable**: Constitution A2 mandates singleton + private __construct for every feature class. But classes that (a) hold no instance state, (b) take no constructor arguments, and (c) produce deterministic output from inputs alone — i.e. **pure value producers** — gain nothing from sharing a single instance. A singleton would add ceremony ($_instance, instance(), private ctor) for zero benefit and create a "must this be unit-tested with the singleton state reset?" question that doesn't exist when each test instantiates fresh.

**Architecture Rule**: Classes in `includes/MCPClients/` (and equivalent stateless-value-producer modules) are exempted from the singleton-only rule because:

1. They hold no instance state — every method returns a deterministic function of its inputs alone
2. They take no constructor arguments — `new ClientName()` is sufficient at every use-site
3. They are instantiated per-use (typically per render or per request), never wired into hooks via the Loader — so the B5 double-hook risk does not apply
4. They are trivially unit-testable WITHOUT a state-reset dance (each test creates fresh instances)

The exception MUST be documented in the class file's PHPDoc with a pointer to this entry. Example (from `includes/MCPClients/AbstractMCPClient.php`):

```php
/**
 * Constitutional invariants (FR-008, FR-009):
 *   - No singleton pattern — instances are stateless and interchangeable.
 *
 * The singleton exemption is justified parallel to A10 (WP_List_Table
 * subclasses): different rationale (no instance state to share), same
 * outcome (not every class in the codebase is a singleton).
 */
```

**Tradeoffs**:
- Gained: pure service classes are trivial to test, trivial to instantiate, immune to test-pollution bugs
- Reconsider: never. If a "pure service class" grows instance state, it ceases to qualify for A11 — the singleton rule then applies again
```

Proposed INDEX.md row:

```
| A11 | Pure service classes (stateless value producers in includes/MCPClients/ and equivalents) exempted from singleton rule | Plugin-wide | singleton, service-class, exception | ARCHITECTURE.md |
```

---

## Recommended Next Step

1. **Commit + push** Phase 4 implementation (`feat(004): MCP client classes — 8 files + tests + fixtures + Phase 4.0 PHPUnit harness`)
2. **Open PR** with `base = feature/issue-3` (or `main` if Phase 2 has been merged that far)
3. **Capture A11** in `docs/memory/ARCHITECTURE.md` + `docs/memory/INDEX.md`
4. **Update Phase 2 status**: the PHPUnit harness setup completed in this phase **supersedes Phase 2 RT-4**; mark Phase 2's 14 deferred test tasks as now-unblocked
5. **Phase 2 RT-3 amendment** is now unblocked too: `Admin\Partials\ApplicationPasswords::render_for_server` can consume `AbstractMCPClient::get_all_clients()`. Includes SEC-INFO-002 grep gate (`grep -rE 'echo \$snippet' admin/` → empty) as part of that follow-up.

**Verification Gate**: `/speckit-architecture-guard-architecture-verify` could be run to formally confirm all 30 tasks delivered + requirements met. Given every gate already passed in-line (PHPUnit 67/67, PHPCS clean, PHPStan L8 clean, 3 grep gates clean), a re-verification pass would produce a null-result report.
