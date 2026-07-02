# Security Review — Plan-Level Constraints

**Reviewed plan**: `specs/010-composer-dependencies/plan.md`
**Reviewed spec**: `specs/010-composer-dependencies/spec.md`
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE) + 2026-06-30 Consent-surface exception amendment
**Date**: 2026-07-01
**Reviewer**: governed-plan orchestrator

---

## Scope

Feature 010 is a composer-dependencies + admin-menu-migration feature. It has **no forms, no REST routes, no DB, no transient, no user input at all**. Constitution §III's normal surfaces (sanitize/escape/nonce/capability/hashed-creds/permission_callback) are structurally absent.

The security-relevant work is entirely about **supply-chain integrity** (adding 3 new production dependencies, bumping 1 major version) and **preserving existing defense-in-depth patterns** (the 4 `class_exists()` guards).

---

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Composer package registry (Packagist + private repos) → local `vendor/` | inbound at install-time | 3 new deps + 1 major-version bump introduce new supply-chain surface. Compromise of any package's namespace = plugin RCE. | (a) Composer.lock pins exact versions; (b) `composer validate --strict` at CI-gate; (c) `git diff --exit-code vendor/` at release-prep (SEC-008-001 CI gate from Phase 8) catches unauthorized vendor/ mutations; (d) `class_exists()` guards for `AccessControlManager` provide runtime fallback if the package fails to autoload |
| Plugin runtime → `\WPBoilerplate\AccessControl\AccessControlManager` (now hard require) | outbound at request-time | If package is compromised at runtime, `AccessControlManager::instance()` calls could return malicious state | Existing 4 `class_exists()` guards remain per FR-025 / CONSTRAINT 1 — defense-in-depth against corrupted vendor/. Removal deferred to future feature after 3+ months soak. |
| Plugin runtime → `\AcrossAI_Co\MainMenu\...` | outbound at admin_menu action time | Package's registration methods could exfiltrate admin capabilities if compromised | Package is authored by AcrossAI itself (in-house trust boundary — same organization as this plugin). TASK-1 research confirms scope of package's capability. Menu registration only executes in admin context; not exposed to unauthenticated requests. |
| Admin URL `/wp-admin/admin.php?page=acrossai_mcp_manager` | inbound | External Application Password callback URLs + bookmarks depend on the slug being preserved | CONSTRAINT 5 + FR-019 mandate URL preservation. Any silent change would break external integrations. Merge-gate grep verification (SC-007) catches accidental drift. |
| Plugin activation → PHP 8.1 runtime | inbound at activation-time | Plugin fails to activate silently on PHP < 8.1 (before Feature 010, header said 8.0 but composer allowed 7.4) | FR-014 atomic PHP bump eliminates the mismatch window. All 5 sync points move together in one commit. WordPress core enforces the `Requires PHP:` gate. |

---

## Authorization Assumptions

Feature 010 introduces zero new capability checks, permission callbacks, or nonces. It preserves:

- **Admin menu capability check** — currently `manage_options` (or main-menu package's mapped equivalent per TASK-1). NOT changed by Feature 010 (per CONSTRAINT / spec §Non-Goals "No capability constant changes").
- **REST route permission_callbacks** — Phase 6's `CliController` routes untouched.
- **Consent-surface exception** — Feature-007's §III amendment for browser-mediated user-on-own-behalf consent surfaces unaffected. Feature 010 does not touch consent flows.
- **`class_exists( '\WP\MCP\Plugin' )` guard** — Feature-009's MCP Controller guard preserved (this feature does NOT touch `includes/MCP/Controller.php`).
- **`class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards** — 4 documented call sites preserved per FR-025.

**No §III capability check applies** — dependency management + admin menu registration ARE admin actions but the `manage_options` gate is provided by WordPress's menu-registration API (which the main-menu package delegates to). Not our layer to check.

---

## Data Isolation & Validation

| Surface | Risk | Mitigation |
|---|---|---|
| `$_GET`, `$_POST`, `$_COOKIE` | Not consumed by Feature 010 code | N/A |
| `composer.json` parse | Composer resolver reads composer.json at install-time; not runtime | Composer handles parsing; `composer validate --strict` catches malformed edits |
| `vendor/autoload_packages.php` `include` | Jetpack autoloader shape assumption | Existing plugin bootstrap already requires this file; ^5.0 preserves the entry-point contract (verified at TASK-4 execution per §Assumptions) |
| `vendor/composer/jetpack_autoload_classmap.php` `require` | Classmap load at plugin boot | Same as above |
| Admin URL slug `?page=acrossai_mcp_manager` | Preserved per CONSTRAINT 5 + FR-019 | External integrations (Application Password callbacks, bookmarks) don't break |
| Screen ID whitelist (`AdminPageSlugs::plugin_screen_ids()`) | Additive updates only per FR-022 | Phase 8's admin asset enqueue guard behavior preserved; existing IDs never removed |

---

## Supply-Chain Concerns

### New packages added — trust assessment

- **`wpboilerplate/wpb-access-control ^2.0.0`** — same publisher (WPBoilerplate) as existing require `automattic/jetpack-autoloader`. Trust equivalent to current baseline. `class_exists()` guards (already in place) provide runtime fallback.
- **`berlindb/core ^3.0.0`** — published by BerlinDB (Sandhills Development / Pippin Williamson). Widely-adopted WordPress ORM package (Restrict Content Pro, EDD, AffiliateWP). External trust boundary. Feature 010 adds it to `vendor/` but does NOT import any namespace — supply-chain risk is bounded to "compromised autoload could inject classes into runtime". Class-load-only exposure; no execution path this feature.
- **`acrossai-co/main-menu ^0.0.8`** — in-house trust boundary (same org). TASK-1 research verifies public API surface + internal dependencies.

### Package version bump — `automattic/jetpack-autoloader ^3.0 → ^5.0`

- 2-major-version jump. Jetpack autoloader is the plugin's bootstrap layer — changes could affect namespace resolution.
- Automattic maintains this package; upstream API compatibility is generally strong.
- Verification at TASK-4: plugin activation smoke test on live WP 6.9 / PHP 8.1 confirms the shape didn't drift.

### Lock file + vendor commit convention (Phases 5–8 precedent)

- `composer.lock` and `vendor/` are committed to the repo (matches Phases 5–8).
- Phase 8's proposed CI gate (`npm ci && npm run build && git diff --exit-code build/ src/`) has an equivalent for composer: `composer install && git diff --exit-code vendor/`. Recommend adding to the release-prep infrastructure epic (out of scope for Feature 010's merge).

---

## Async / Race Conditions

| Race | Risk | Mitigation |
|---|---|---|
| `composer install` runs concurrently with plugin activation | `vendor/autoload_packages.php` half-written; PHP fatal on partial include | Composer runs offline before deploy. Not a runtime-triggered race. |
| Admin menu registered twice (if package auto-hooks AND we also Loader-wire) | Duplicate menu entries in admin sidebar | TASK-1 output prescribes the correct wiring shape; FR-021 enforces removal or update of Loader entry (not both). Manual smoke test SC-005 catches double-registration. |

---

## Compliance / Privacy

| Constraint | Status |
|---|---|
| User-identifying data rendered / stored | None — Feature 010 renders no user data |
| Cookies / sessions | None new |
| GDPR / right-to-erasure | Not triggered |
| Logging / audit trail | None new |
| Third-party asset URLs | None — all assets are plugin-local via `plugins_url()` (Feature-008 pattern preserved) |
| Third-party runtime code | 3 new packages loaded via composer autoloader — same trust posture as prior 5 phases' packages |

---

## High-Risk Issues

**None.** Feature 010 has no meaningful attack surface expansion. The one runtime concern is supply-chain integrity, addressed by:

1. Committed `composer.lock` pinning exact versions
2. `class_exists()` guards on the only production consumer (`AccessControlManager`) — defense-in-depth against corrupted vendor/
3. Manual smoke test (SC-004 activation without deprecation notices) catches any autoloader shape drift

Two **non-blocking advisories**:

### Advisory 1 — SEC-010-001: CI vendor/-diff gate is deferred to release-prep infrastructure

Feature 010 introduces a 2-major-version bump of `jetpack-autoloader` and 3 new packages. Without CI enforcement that `composer install && git diff --exit-code vendor/` stays green on every PR, committed `vendor/` state could drift from what a clean install would emit. This is the same concern Phase 8 flagged (SEC-008-001 for `build/`). Non-blocking for Feature 010's merge; MUST land before `feature/issue-3 → main` cutover. Recommend folding into the existing release-infrastructure epic that also owns Phase 8's build-artifact CI gate.

### Advisory 2 — SEC-010-002: `class_exists()` guard removal timeline is not scheduled

CONSTRAINT 1 preserves the 4 guards for `\WPBoilerplate\AccessControl\AccessControlManager` "for at least 3 months soak time". No specific removal target is scheduled. Non-blocking — the guards remain as-is; a future feature (Feature 012+?) can revisit. Documentation-level concern.

---

## Final Verdict

✅ **Plan PASSES security review.**

- Zero new attack surface. Feature 010 preserves the existing security posture (guards, capability checks, consent-surface exception).
- Constitution §III's normal surfaces are structurally absent — nothing to sanitize, escape, nonce, or capability-check.
- Load-bearing defense: composer.lock version pins + `class_exists()` guards + admin URL preservation.
- Consent-surface exception (Feature-007 §III amendment) preserved intact.
- Feature-008 admin asset enqueue guard invariant preserved via FR-022 additive whitelist updates.
- Feature-009 MCP boot guard pattern preserved (this feature does not touch `MCP/Controller.php`).

Both advisories (SEC-010-001 vendor/-diff CI + SEC-010-002 guard removal timeline) are release-prep or future-feature concerns, not Feature 010 blockers.

Proceed to architecture review.
