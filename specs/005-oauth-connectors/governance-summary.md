# Governed Planning Summary — Phase 5: OAuth / Claude Connectors

**Date**: 2026-06-18 | **Branch**: `005-oauth-connectors`

---

## Memory Context

- **Status**: Synthesized (`memory-synthesis.md` already produced by prior `/speckit-memory-md-plan-with-memory` turn)
- **Key Constraints applied**:
  - **A1** (Loader-only hooks) — fully honored (FR-021, FR-022)
  - **A2** (Singleton + private ctor) — honored for all 5 stateful OAuth classes; **A11 exemption** applied to `Includes\OAuth\PKCE` (pure utility)
  - **A4** (DataForm) — **soft exemption** documented (RFC 6749 §4.1.1 prescribed form shape); A13 capture queued
  - **A6** (`use` imports inside `Includes\*`) — required; 6 OAuth files + 12 Database files all reference siblings via `use`
  - **S1** (Nonces on all forms) — FR-009 + FR-010 consent form
  - **S2** (`permission_callback` never `__return_true` on mutating routes) — **documented exemption**: RFC 6749 §2.3.1 prescribes auth via POST body; S7 capture queued
  - **S3** (OAuth tokens hashed at storage) — FR-020 directly enforces SHA-256
  - **B1** (namespace silent-fail) — mitigated by `use` imports throughout
  - **B4** (unescaped dot in rewrite rule) — load-bearing memory hit; R1 specifies `'^\.well-known/oauth-…'` escape verbatim
  - **B7** (mass-assignment defense) — all 3 new BerlinDB Query layers follow the `Schema::columns()` filter pattern
  - **D9** (BerlinDB hand-rolled) — 3 new Query layers (OAuthToken, OAuthAudit, CliAuthLog extension)
  - **D11** (Phase X.0 absorption) — **Phase 5.0** absorbs WP-PHPUnit harness setup (different from Phase 4.0's WP-free bootstrap)

---

## Security Review

- **Status**: Reviewed inline (`specs/005-oauth-connectors/security-constraints.md`)
- **Constraints document**: trust-boundary table + data-isolation table + async-context analysis + 5 advisory findings + 12 confirmed secure patterns
- **Findings**: 5 Advisory, all by-design or RFC-mandated trade-offs (F1 S2 exemption, F2 A4 exemption, F3 X-Forwarded-For not trusted, F4 HTTPS warning-not-block, F5 form-encoded only positive hardening)
- **Blocking concerns**: **None.** Zero CRITICAL/HIGH/MEDIUM findings
- **Confirmed secure patterns** (12): hashed storage · constant-time comparisons · PKCE S256 required · anti-replay · cross-server defense · no discovery oracle · rate-limit-before-validation · CSRF defense · `.well-known` dot-escape · append-only audit log · `X-Forwarded-For` not trusted by default · `Cache-Control: no-store` on token endpoint

---

## Architecture Review

Performed inline against `.specify/memory/constitution.md` v1.0.0 + selected memory entries.

### Violations

**None blocking.** Two **documented soft exemptions** (both RFC-mandated):

| ID | Memory rule | Spec position | Rationale |
|---|---|---|---|
| V1 | A4 — All new admin forms use DataForm | FR-008 consent page is a plain `<form>` | RFC 6749 §4.1.1 prescribes specific consent UX; DataForm doesn't model it cleanly. 3 reasons documented in spec §Admin UI Requirements. |
| V2 | S2 — REST routes never use `__return_true` on mutating routes | FR-011 token endpoint `permission_callback: __return_true` | RFC 6749 §2.3.1 specifies authentication via POST body. Splitting validation across `permission_callback` + `callback` would be actively worse. |

Both exemptions are framed in the spec, plan, and security review with
explicit RFC citations. Both queued for A13/S7 memory capture
post-implementation.

### Constitution alignment table

| Principle / Constraint | Status |
|---|---|
| I. Modular Architecture | ✅ 6 OAuth classes + 3 Database modules; sibling-decoupled |
| II. WordPress Standards | ✅ PHPCS WPCS strict + PHPStan L8 mandated |
| III. Security First | ✅ SHA-256 storage, nonces, prepared statements, no plaintext tokens |
| IV. User-Centric Design (DataForm) | ⚠ Documented A4 exemption (V1) |
| V. Extensibility Without Core Modification | ✅ All hooks via Loader; `class_exists()` guards on optional integrations |
| VI. Reusability & DRY | ✅ Storage facade for code+token+rate-limit ops; PKCE math centralized |
| VII. Definition of Done | ✅ DoD gates listed; Phase 5.0 WP-PHPUnit prereq absorbed |
| A1 (Loader-only hooks) | ✅ FR-021, FR-022 |
| A2 (Singleton) | ✅ 5 stateful classes; **A11 exemption** applied to PKCE |
| A6 (`use` imports) | ✅ Required throughout new modules |
| **S2** (no `__return_true` mutating) | ⚠ Documented S2 exemption (V2) |
| B1 (namespace silent-fail) | ✅ Mitigated by `use` imports |
| **B4** (dot escape in rewrite) | ✅ R1 specifies `\.well-known` verbatim |
| B7 (mass-assignment) | ✅ Schema-column filter in 3 new Query layers |

### Consistency Risks

- **R-1 (acknowledged)**: A4 + S2 exemptions each rely on the same precedent
  pattern (A10/A11 carve-outs). After implementation confirms both work as
  designed, propose A13 + S7 memory captures to elevate the exemptions to
  durable architectural rules.

- **R-2 (P0 dependency)**: WP-PHPUnit harness must exist before any FR-008
  consent-page test or FR-015 Bearer-auth test can run. Phase 5.0
  absorbs the harness setup; tasks.md T000-T007 (when generated) will
  include this.

---

## Recommended Actions

1. **Continue to `/speckit-tasks`** — generate the implementation task
   list. Expected scope: ~50 tasks (the largest yet) covering:
   - Phase 5.0 WP-PHPUnit harness setup (T000-T007)
   - 3 BerlinDB Query layers (~12 files)
   - 6 OAuth classes (~600+ lines)
   - Activator + Main + Deactivator + CliAuthLog Schema extensions
   - PHPUnit suite per RFC section
   - Polish: PHPCS/PHPStan/PHPUnit/grep gates

2. **Tasks phase MUST include**:
   - **T000 P0 gate**: verify Phase 2's BerlinDB Query layer + `claude_connector_*` columns (already on `feature/issue-3`)
   - **Phase 5.0**: set up `tests/bootstrap-wp.php` + second testsuite `oauth` in `phpunit.xml.dist`
   - Per-RFC-section conformance tests
   - **R1 mitigation test** (rewrite-rule dot escape)
   - Manual quickstart walk task

3. **No `/speckit-architecture-guard-refactor-generator` needed** — zero architectural violations after A4 + S2 exemptions documented.

4. **Memory captures**: A13 (RFC form exemption) + S7 (token endpoint S2 exemption) **queued for post-implementation**. Not auto-triggered at planning stage.

---

## Status

**Phase 5 plan is governance-cleared for `/speckit-tasks`.**

Cross-phase notes:
- **Phase 2** (`002-admin-ui`, merged to `feature/issue-3`): provides `claude_connector_*` columns + BerlinDB Query layer + CliAuthLog table. Phase 5 extends CliAuthLog schema by 4 columns (handled in dbDelta via DB_VERSION bump).
- **Phase 4** (`004-mcp-clients`, PR #6 open): provides PHPUnit harness (WP-free bootstrap). Phase 5.0 adds a parallel WP-PHPUnit bootstrap; both harnesses coexist via separate testsuites.
- **Phase 2 RT-3** (Tokens-tab amendment) — independent of Phase 5; can land before or after.
