# Security Constraints — Feature 001: Core Boot Flow

**Source**: `specs/001-core-boot-flow/plan.md`
**Reviewer**: governed-plan orchestrator (automated)
**Date**: 2026-05-29
**Min Severity**: info

---

## Review Scope

Plan-level security review. No new REST routes, forms, AJAX handlers, or admin
UI are introduced in this phase. Attack surface changes are limited to:

- A new static utility class (`includes/Compat.php`) — no user input
- Extended plugin activation hook (`includes/Activator.php`) — WordPress-gated
- Hook registration expansion in `includes/Main.php` — wiring only

---

## Trust Boundary Assessment

| Boundary | Risk | Notes |
|---|---|---|
| Plugin activation (`register_activation_hook`) | Low | WordPress already enforces `activate_plugins` capability before calling the hook |
| Rewrite rule registration in Activator | Low | `add_rewrite_rule()` is safe; the rules resolve to 404 until Phase 3 handler classes exist |
| `class_exists()` guards in Activator | Low | Class names are string literals from the codebase — no user input |
| `flush_rewrite_rules()` in Activator | Info | No security concern; performance impact only at activation time |
| TODO-stub comments in Main.php | Info | Comments are inert; no new trust boundary |

---

## Findings

### FINDING-001 · HIGH · Namespace Resolution Bug in Activator Phase C

**Location**: `specs/001-core-boot-flow/plan.md` § Phase C DB table bootstrapping

**Description**: The plan shows:
```php
if ( class_exists( Includes\Database\MCPServer\Query::class ) ) {
    Includes\Database\MCPServer\Query::maybe_create_table();
}
```
`Activator.php` is in namespace `AcrossAI_MCP_Manager\Includes`. PHP resolves
bare relative names against the current namespace, producing:
`AcrossAI_MCP_Manager\Includes\Includes\Database\MCPServer\Query`
— a double-`Includes` path that will never resolve to any class.

**Impact**: `class_exists()` always returns false → DB tables are never created
at activation → activation appears to succeed silently (no fatal error) but
leaves the plugin in a broken state that is hard to diagnose.

**Required Fix (implementation tasks)**:
Use fully-qualified class names with a leading backslash, e.g.:
```php
if ( class_exists( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::class ) ) {
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::maybe_create_table();
}
```
Or, equivalently, add `use` imports at the top of `Activator.php`.

**Security-Architecture Conflict**: Yes — the bug neutralises the ABSPATH /
security-context-agnostic guard pattern in FR-009. A silent "success" during
activation that leaves DB tables absent creates downstream undefined-behaviour
risk when Phase 4 classes try to query those tables.

---

### FINDING-002 · MEDIUM · S4 Deferral Creates Activation-Time DB Risk

**Location**: `specs/001-core-boot-flow/plan.md` § Phase C, Constitution Check row S4

**Description**: The plan defers `$wpdb->prepare()` enforcement to Phase 4.
`Activator::activate()` may call `maybe_create_table()` and
`insert_default_server()` on Query classes. If those classes ever use raw
interpolated `$wpdb->query()` calls internally (pre-Phase 4), unescaped SQL
could reach the database at activation time.

**Impact**: SQL injection risk if Query class internals use raw SQL before
Phase 4 hardens them.

**Required Mitigation**: When implementing Phase 4 Query classes, ALL
`$wpdb->query()` / `$wpdb->get_results()` calls MUST use `$wpdb->prepare()`.
Add an explicit task note to Phase 4 spec to inherit this constraint.

**Severity Rationale**: Medium (not High) because the Query classes don't
exist yet — risk is in future implementation, not current code.

---

### FINDING-003 · LOW · Existing `new` Instantiation Bypasses Singleton Contract

**Location**: `includes/Main.php`, `define_admin_hooks()` and `define_public_hooks()`

**Description**: `Admin\Main`, `Admin\Partials\Menu`, and `Public\Main` are
instantiated with `new ClassName(...)` rather than `ClassName::instance()`.
The Constitution mandates the singleton `instance()` pattern for ALL feature
classes. The plan preserves this pattern without flagging it as a known
deviation.

**Impact**: Multiple instances of these classes could be created if callers
call `new` from other locations, leading to duplicate hook registrations.

**Required Mitigation**: Either (a) convert `Admin\Main`, `Admin\Partials\Menu`,
and `Public\Main` to use `::instance()` in this phase, or (b) add an explicit
"known deviation — pre-singleton boilerplate" note to the plan and track it as
technical debt in `docs/memory/BUGS.md`.

---

### FINDING-004 · INFO · `Compat.php` Placement vs. Module Boundary Principle

**Location**: `specs/001-core-boot-flow/plan.md` § Phase B

**Description**: The plan places `Compat.php` directly in `includes/` rather
than `includes/Utilities/`. Constitution Principle I states "Shared logic MUST
be extracted to `includes/Utilities/`." Spec FR-008 explicitly names
`includes/Compat.php` as the target path.

**Impact**: Minor structural inconsistency. No security impact.

**Required Mitigation**: Either (a) move to `includes/Utilities/Compat.php`
and update the spec, or (b) add a justified deviation note. Since FR-008 is
already clarified and locked, Option B is recommended — add a note that
`Compat` is a boot-time compatibility shim needed before `Utilities/` classes
load and therefore cannot be placed inside `Utilities/`.

---

## Constraints Written for Architecture Guard

1. **C-SR-001** (HIGH): All class references inside `Activator.php` MUST use
   fully-qualified class names (leading `\`) or `use` imports — never bare
   relative names that resolve incorrectly within the current namespace.

2. **C-SR-002** (MEDIUM): Phase 4 Query class implementation MUST use
   `$wpdb->prepare()` for all SQL statements. This constraint must be written
   into the Phase 4 spec.

3. **C-SR-003** (LOW): The `new ClassName()` instantiation pattern in
   `define_admin_hooks()` / `define_public_hooks()` is a tracked deviation.
   Singleton conversion is technical debt to be resolved before Phase 3.

4. **C-SR-004** (INFO): `includes/Compat.php` placement is a justified
   exception to Principle I — document in plan.md with rationale.

5. **C-SR-005** (INFO): Phase 4 SHOULD add a `WP_DEBUG`-gated `error_log()`
   call inside `Activator::activate()` when a DB class is absent (silently
   skipped), to aid incident response on misconfigured deployments.

6. **C-SR-006** (LOW): Phase 7 access-control hook registration MUST use a
   dedicated filter guard (e.g., `acrossai_mcp_manager_load_access_control`)
   that is independent from the broad `acrossai_mcp_manager_load` gate, so
   that third-party plugins cannot silently disable REST security enforcement
   by returning false from the top-level load filter.

---

### FINDING-005 · INFO · Silent DB Bootstrap Skip Leaves No Diagnostic Trace

**Location**: `specs/001-core-boot-flow/spec.md` § Clarifications + `includes/Activator.php`

**Description**: The spec clarification mandates: "Silently skip — pure no-op
per class. No log, no notice, no flag in wp_options." When DB classes are absent
(e.g., Composer install incomplete, autoloader missing), `class_exists()` returns
`false` and `activate()` completes without any observable evidence of the skip.
This creates a monitoring blind spot: the plugin reports successful activation
while DB tables are absent, leaving the site in a broken-but-silent state.

**Impact**: Operational risk only — no direct security impact in Phase 1 (no DB
classes yet). Risk escalates in Phase 4+ when DB-dependent features activate over
the same activation path. A misconfigured production deployment has no detection
path short of manual database inspection.

**Classification**: Informational — design decision acknowledged (spec mandates
no-log behavior); logged here to ensure Phase 4 addresses it.

**Required Mitigation**: Phase 4 spec SHOULD add `WP_DEBUG`-gated `error_log()`
in `Activator::activate()` when a DB class guard evaluates to false. Silent
behavior to the end user is preserved; debug environments get a trace.

**Status**: Open — tracked as C-SR-005.

---

### FINDING-006 · LOW · `acrossai_mcp_manager_load` Filter Is a Single Bypass Point for All Future Security Hooks

**Location**: `includes/Main.php` → `load_hooks()` + plan.md Phase A stubs

**Description**: `Main::load_hooks()` gates the entire hook-registration block
(both `define_admin_hooks()` and `define_public_hooks()`) behind a single filter:
```php
if ( apply_filters( 'acrossai_mcp_manager_load', true ) ) {
```
Any third-party plugin can return `false` from this filter to silently disable
ALL hook registrations — including future security-critical hooks that are
stubbed for later phases:
- **Phase 6**: `determine_current_user` Bearer token authentication
  (`ClaudeConnectors::determine_current_user_from_bearer`)
- **Phase 7**: `rest_pre_dispatch` access-control enforcement
  (`WPBoilerplate\AccessControl\AccessControlManager::enforce_access`)

If a malicious or misconfigured plugin triggers this filter, REST endpoints
would be reachable without access-control enforcement. All security hooks from
Phases 3–7 are bypassed in a single filter call with no error or log.

**Exploit Scenario**: A compromised or buggy plugin adds:
`add_filter('acrossai_mcp_manager_load', '__return_false', 5);`
Result: all MCP REST routes accept unauthenticated requests; OAuth token
validation and Bearer auth are silently disabled.

**Impact**: Indirect — requires installed plugin (admin capability already
compromised). Exploitation post-Phase 7 creates full REST access-control bypass.

**Required Mitigation**: Phase 7 spec MUST add a dedicated, independently-gated
filter for access-control hook registration (C-SR-006). The broad
`acrossai_mcp_manager_load` filter MUST NOT be the sole gate for security hooks.

**Status**: Open — tracked as C-SR-006. No current code impact (security hooks
not yet wired). Action required before Phase 7 merges.

---

## Full Security Review Sign-off

**Date**: 2026-05-29
**Reviewer**: security-review.full (automated — mode: speckit.security-review.audit)
**Branch**: `feature/issue-3`
**Spec**: `specs/001-core-boot-flow/spec.md`
**Plan**: `specs/001-core-boot-flow/plan.md`

---

### Final Verdict: APPROVED_WITH_CONDITIONS

Conditions (must be satisfied before final merge or addressed in the specified phase):

1. **[Phase 1 — before merge]** FINDING-001 (HIGH): Resolved by plan's `use`
   import approach in Phase C. Implementation MUST use `use` aliases, not bare
   relative names. PHPStan level 8 MUST pass (catches unresolved class names).
2. **[Phase 4 — inherited constraint]** FINDING-002 (MEDIUM): `$wpdb->prepare()`
   MUST be enforced in all Phase 4 Query class implementations. This constraint
   MUST be written into the Phase 4 spec as C-SR-002.
3. **[Phase 1 — before merge]** FINDING-003 (LOW): Singleton conversion MUST be
   applied to `Admin\Main`, `Admin\Partials\Menu`, and `Public\Main` in this phase.
4. **[Phase 4 — informational]** FINDING-005 (INFO): Phase 4 SHOULD add
   `WP_DEBUG`-gated diagnostic logging for silent DB class skips (C-SR-005).
5. **[Phase 7 — inherited constraint]** FINDING-006 (LOW): Phase 7 MUST add a
   dedicated filter guard for access-control hook registration independent of
   the broad `acrossai_mcp_manager_load` gate (C-SR-006).

---

### OWASP Top 10 Applicability Assessment

| OWASP Category | Applicability | Assessment |
|---|---|---|
| A01:2025 Broken Access Control | LOW | WP enforces `activate_plugins` cap on activation hook; no new endpoints; `apply_filters` load gate noted as FINDING-006 |
| A02:2025 Cryptographic Failures | N/A | No cryptographic operations in this phase |
| A03:2025 Injection | LOW | No user input processed; all `add_rewrite_rule()` / `class_exists()` args are string literals; `$wpdb` SQL risk deferred to Phase 4 (FINDING-002) |
| A04:2025 Insecure Design | LOW | Single filter bypass point for future security hooks is a design risk (FINDING-006); rewrite rules registered before handlers exist is intentional and safe (404 until Phases 3/6) |
| A05:2025 Security Misconfiguration | LOW | ABSPATH guards required on all PHP files (plan mandated); `Compat.php` must include guard; no debug exposure in this phase |
| A06:2025 Vulnerable Components | INFO | `automattic/jetpack-autoloader ^5.0` semver range; no known CVEs; no new external dependencies introduced in this phase |
| A07:2025 Authentication Failures | N/A | No authentication endpoints or session management in this phase |
| A08:2025 Software/Data Integrity | INFO | No integrity checks on `vendor/autoload_packages.php`; standard WP plugin practice; supply-chain risk is environment-level |
| A09:2025 Logging Failures | INFO | Silent DB bootstrap skip creates operational blind spot (FINDING-005); no security event logging in this phase (boot wiring only) |
| A10:2025 Mishandling Exceptional Conditions | LOW | `file_exists()` guard on Composer autoloader (graceful fail); `class_exists()` guard on DB classes (silent skip per spec); no exception handlers that fail-open |

---

### Classification Confirmation of FINDING-001 through FINDING-004

| Finding | Severity | Status | Classification Verdict |
|---|---|---|---|
| FINDING-001 | HIGH | RESOLVED in plan (use imports in Phase C) | **CORRECTLY CLASSIFIED** — namespace double-Includes is a high-impact silent failure; HIGH is appropriate |
| FINDING-002 | MEDIUM | WARN — tracked for Phase 4 | **CORRECTLY CLASSIFIED** — risk is in future code, not current; MEDIUM is appropriate |
| FINDING-003 | LOW | RESOLVED in plan (singleton conversion in Phase A) | **CORRECTLY CLASSIFIED** — duplicate hook registrations are an architecture integrity risk; LOW is appropriate |
| FINDING-004 | INFO | Justified deviation, documented in plan.md | **CORRECTLY CLASSIFIED** — structural inconsistency with no security impact; INFO is appropriate |

---

### New Findings Added

| Finding | Severity | Status |
|---|---|---|
| FINDING-005 | INFO | Open — tracked as C-SR-005; action in Phase 4 |
| FINDING-006 | LOW | Open — tracked as C-SR-006; action required before Phase 7 merges |

**Total new findings**: 2

---

Full security review complete. Proceed to /speckit.tasks.
