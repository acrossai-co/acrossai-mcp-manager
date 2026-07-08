---
document_type: security-review
review_type: plan
assessment_date: 2026-07-07
codebase_analyzed: acrossai-mcp-manager (Feature 017 planning artifacts)
total_files_analyzed: 7
total_findings: 6
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 1
low_count: 2
informational_count: 2
owasp_categories: [A01, A03, A05, A09]
cwe_ids: [CWE-863, CWE-79, CWE-807, CWE-778, CWE-807]
field_summaries:
  document_type: "Always 'security-review'. Allows indexers to skip non-review documents."
  review_type: "Which command generated this document: audit, branch, staged, plan, tasks, or followup."
  assessment_date: "ISO 8601 date the review was performed (YYYY-MM-DD)."
  overall_risk: "Highest severity tier with active findings (CRITICAL, HIGH, MODERATE, LOW, INFORMATIONAL)."
  critical_count: "Number of Critical findings (CVSS 9.0-10.0)."
  high_count: "Number of High findings (CVSS 7.0-8.9)."
  medium_count: "Number of Medium findings (CVSS 4.0-6.9)."
  low_count: "Number of Low findings (CVSS 0.1-3.9)."
  informational_count: "Number of Informational findings."
  owasp_categories: "OWASP Top 10 2025 categories (A01-A10) that have at least one finding."
  cwe_ids: "CWE identifiers referenced in this document."
  finding_id: "Unique finding identifier (SEC-NNN) for cross-referencing and task linkage."
  location: "File path and line number of the vulnerable code (path/to/file.ext:line)."
  owasp_category: "OWASP Top 10 2025 category for this finding (AXX:2025-Name)."
  cwe: "Common Weakness Enumeration identifier with short name (CWE-NNN: Name)."
  cvss_score: "CVSS v3.1 base score (0.0-10.0). 9.0+=Critical, 7.0-8.9=High, 4.0-6.9=Medium, 0.1-3.9=Low."
  spec_kit_task: "Spec-Kit task ID for backlog tracking and remediation follow-up (TASK-SEC-NNN)."
---

# Security Review — Feature 017 Plan (Per-server Ability Selection)

## Executive Summary

Feature 017 adds a new BerlinDB module (`MCPServerAbility`) for per-`(server_id, ability_slug)` exposure overrides, a REST controller under `acrossai-mcp-manager/v1`, a `@wordpress/dataviews`-driven React app on the Abilities tab, and a `@wordpress/hooks` + PHP `apply_filters()` extensibility surface for companion plugins. The plan follows established F011 (BerlinDB), F013 (public API `@experimental`), F015 (React tab mount) precedents cleanly and passes every constitution principle in the Phase 1 re-check.

**Overall risk: HIGH — driven by a single enforcement-boundary gap.** The plan correctly stores per-server exposure overrides in a BerlinDB table and surfaces them via a well-audited REST controller with `manage_options` gating — but **nothing in the plan wires those stored overrides into the MCP tool-call boundary**. The current codebase (`admin/Partials/ServerTabs/AbilitiesTab.php:124-136`) partitions abilities via `\AcrossAI_Abilities_Manager\Runtime\Override_Applier::should_expose_to_mcp_server()` from the sibling plugin — a call site F017's TASK-5 removes. F015 (`AcrossAI_MCP_Access_Control::gate_mcp_tool_call`) enforces at the `mcp_adapter_pre_tool_call` filter but does **not** consult F017's new table. Result: operators toggle exposure in the UI, see the effective value flip in the REST response, and the `acrossai_mcp_ability_exposure_changed` observability action fires — **while AI clients continue to see and call every ability whose `meta[mcp][public]` is truthy** (SEC-001, HIGH, CWE-863).

The remaining findings are lower-severity refinements: (SEC-002) third-party filter return values reach the REST response and React render without a documented sanitization contract; (SEC-003) `array_merge` argument order is a maintainer trap the plan should lock down with a PHPUnit invariant test; (SEC-004) effective-change detection has a benign concurrency window that could log stale `$was` values; (SEC-005 INFO) `acrossai_mcp_ability_row` callbacks can perform side effects during READ requests; (SEC-006 INFO) filter-callback error messages surface in the browser console.

Beyond SEC-001, the plan's security posture matches or strengthens the pre-feature baseline: `manage_options` on both routes, `absint()` + `sanitize_text_field()` at the REST boundary, BerlinDB prepared-statement layer, silent phantom-version guard preserving data on ops mistakes, F012 uninstall opt-in gate honored for the new DROP TABLE, no secrets stored, no credential handling.

## Plan Artifacts Reviewed

| File | Purpose | Read scope |
|---|---|---|
| `specs/017-per-server-ability-selection/spec.md` | Feature specification (~360 lines, 3 Session-2026-07-07 clarifications, 29 FRs, 11 SCs, 6 user stories) | Full read |
| `specs/017-per-server-ability-selection/plan.md` | Implementation plan (constitution check + project structure + phase notes) | Full read |
| `specs/017-per-server-ability-selection/research.md` | Phase 0 research (8 decisions, alternatives considered) | Full read |
| `specs/017-per-server-ability-selection/data-model.md` | Phase 1 data model (single entity, indexes, DDL preview, lifecycle diagram) | Full read |
| `specs/017-per-server-ability-selection/contracts/rest-api.md` | Phase 1 REST contract (both routes, auth, error taxonomy, frozen names) | Full read |
| `specs/017-per-server-ability-selection/contracts/js-hooks.md` | Phase 1 JS hooks contract (3 filter points, safeApplyFilters boundary, frozen names) | Full read |
| `specs/017-per-server-ability-selection/memory-synthesis.md` | Memory synthesis (~880 words, refreshed after Q3 scope-add) | Full read |
| `specs/017-per-server-ability-selection/quickstart.md` | Phase 1 quickstart (build + smoke + audit greps) | Full read |
| `docs/planings-tasks/017-per-server-ability-selection.md` | Long-form planning doc (TASK-1..9 with inline code snippets) | Full read |
| `.specify/memory/constitution.md` | Constitution v1.0.0 | Full read |
| `docs/memory/INDEX.md` | Memory routing map | Full read |
| `admin/Partials/ServerTabs/AbilitiesTab.php` | Existing tab body (pre-F017) — confirms current enforcement path via sibling plugin | Lines 84-147 |
| `includes/Main.php` | Existing hook wiring — confirms `mcp_adapter_pre_tool_call` is the only MCP-boundary gate | Lines 362-373, 405-406 |

## Vulnerability Findings

### SEC-001 — HIGH — Missing enforcement of stored per-server overrides at the MCP tool boundary

- **Finding ID**: SEC-001
- **Location**: `specs/017-per-server-ability-selection/plan.md` §Summary + §Constitution Check — no task or FR wires `ExposureResolver` into either (a) the vendor MCP adapter's per-server ability enumeration or (b) the `mcp_adapter_pre_tool_call` filter (D18); `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-1..9 — same gap
- **OWASP Category**: A01:2025 — Broken Access Control
- **CWE**: CWE-863 — Incorrect Authorization
- **CVSS Score**: 7.5 (High) — AV:N / AC:L / PR:N / UI:N / S:U / C:H / I:N / A:N (a connected AI client can call abilities the operator explicitly hid on the server; no auth escalation needed beyond the OAuth token the client already has)

**Description**:
The plan stores per-`(server_id, ability_slug)` exposure decisions in `wp_acrossai_mcp_server_abilities`, surfaces them through the REST controller, and computes an "effective exposure" value via `ExposureResolver::resolve()`. However, no code path in F017 consults that value at either enforcement gate:

1. **List-time enforcement gap** — the vendor `wordpress/mcp-adapter` package builds the `mcp/tools/list`, `mcp/prompts/list`, and `mcp/resources/list` responses from `wp_get_abilities()` filtered by `meta[mcp][public]`. F017's stored `is_exposed=0` rows are **not consulted** — hidden abilities still appear in listings.

2. **Call-time enforcement gap** — F015 wires `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` into `mcp_adapter_pre_tool_call` (`includes/Main.php:373`). That callback runs `AccessControlManager` role/user rules but does **not** consult `MCPServerAbility\ExposureResolver` — a connected AI client can still invoke an ability the operator toggled off.

3. **Current-code contradiction** — the existing `AbilitiesTab::partition_abilities()` (lines 123-147) consults `\AcrossAI_Abilities_Manager\Runtime\Override_Applier::should_expose_to_mcp_server( $slug, $server_slug )` — a sibling-plugin surface. F017's TASK-5 deletes that partition helper. Whatever list-time enforcement path the sibling plugin currently provides via `Override_Applier` becomes disconnected from F017's storage, and the UI's "hide" toggle silently does nothing at the MCP boundary.

**Impact**: The tab, the REST response, and the audit event `acrossai_mcp_ability_exposure_changed` all report the operator's intent as effective — but the intent is not enforced. Operators may believe they've contained an ability's access surface and continue to expose it to every connected AI client. This is a classic confused-deputy / trust-boundary-mismatch pattern.

**Recommendation**: Add a TASK-10 (or fold into TASK-4) that wires `ExposureResolver` into one of the following:

- **Preferred**: Add a second `mcp_adapter_pre_tool_call` filter callback on the AcrossAI_MCP_Manager side that returns `WP_Error( ..., array( 'status' => 403 ) )` when `ExposureResolver::resolve()` returns `false` for the tool's server + slug. Priority MUST be LATER than F015's callback so its "denied" response supersedes any AccessControl rule allow. This closes the call-time gap.

- **Additionally (list-time cleanup)**: Add a filter callback on whichever per-server ability-collection hook the vendor `mcp-adapter` exposes (or on `wp_get_abilities` if the adapter uses it directly), and filter out abilities where `ExposureResolver::resolve()` returns `false` for the current server. Prevents the ability from appearing in `mcp/tools/list` in the first place. If the vendor package does not expose a suitable hook, this becomes a follow-up ticket and a documented known limitation in the spec's §Assumptions.

- **Alternative** — if the intent is to defer enforcement to a follow-up feature, the spec MUST be updated: FR-007's "existing installs upgrade with zero visible behavior change" claim is currently true only because enforcement is unwired; that claim must be softened to "the tab UI and REST response reflect the operator's choice, but at-boundary enforcement lands in Feature NNN," and User Story 1's Independent Test claim ("connected AI client calling the MCP endpoint must reflect the same exposure") must be removed or deferred. Without this softening, the spec ships a promise the plan cannot keep.

**References**:
- `includes/Main.php:373` — F015 `mcp_adapter_pre_tool_call` wiring (D18 precedent)
- `admin/Partials/ServerTabs/AbilitiesTab.php:124-136` — current sibling-plugin partition being removed by TASK-5
- Constitution §I — modular architecture requires the storage module NOT reach into F015; use a filter-level integration
- Memory: `[[D18]]` — `mcp_adapter_pre_tool_call` is the canonical MCP-boundary enforcement hook

**Task-linked follow-up**: TASK-SEC-001

---

### SEC-002 — MODERATE — Third-party filter return values reach REST response and React render without a documented sanitization contract

- **Finding ID**: SEC-002
- **Location**: `specs/017-per-server-ability-selection/contracts/rest-api.md` §PHP Row Filter; `contracts/js-hooks.md` §`acrossaiMcpManager.abilities.row`; `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-4 snippet — `array_merge( $filtered, $row )` merges extension-added keys into the response body without imposing a type or escape contract on those additions
- **OWASP Category**: A03:2025 — Injection (potential XSS via extension-added values)
- **CWE**: CWE-79 — Improper Neutralization of Input During Web Page Generation
- **CVSS Score**: 5.4 (Medium) — AV:N / AC:H / PR:H / UI:R / S:C / C:L / I:L / A:N (requires a companion plugin to add unescaped values AND a companion plugin's `render` callback to render them unsafely; both are companion-plugin faults but the core plugin can pre-empt via contract)

**Description**:
The plan documents that extensions cannot overwrite the seven built-in row keys, but says nothing about the shape or escaping expected for keys the extension *does* add. Downstream:

1. **PHP → JSON path** — `AbilitiesController::get_abilities()` serializes the merged array via WP core's JSON encoder. HTML/JS payloads in extension-added string values survive intact.

2. **JSON → React render path** — extension-authored `render` callbacks in `acrossaiMcpManager.abilities.fields` receive these values via `item.ext_key`. Idiomatic `@wordpress/element` `createElement( 'span', {}, item.ext_key )` uses React's default child escaping and is safe. But `createElement( 'span', { dangerouslySetInnerHTML: { __html: item.ext_key } } )` — a common shortcut in companion plugins — bypasses escaping.

3. **Companion-plugin misuse pattern** — an extension that populates `$row['ext_html'] = get_post_meta( $id, 'unsanitized_key', true );` and renders it via `dangerouslySetInnerHTML` creates a stored XSS surface. The vulnerability is in the extension, but the core plugin can materially reduce the risk by documenting the trust contract.

**Recommendation**: Update `docs/extending-abilities-tab.md` (planned in TASK-9) and both contract files to state explicitly:

> **Trust contract for extension-added row data**: Values added via `acrossai_mcp_ability_row` or `acrossaiMcpManager.abilities.row` are treated as untrusted at render time. Extensions MUST escape their own values before use and MUST NOT rely on the core plugin to sanitize them. Prefer `createElement( 'span', {}, value )` (React's default child escaping) over `dangerouslySetInnerHTML`. On the PHP side, additions containing HTML MUST be run through `wp_kses_post()` or a more restrictive `wp_kses` allowlist BEFORE being added to `$row`.

Also add a linter or PHPStan/ESLint rule note in TASK-9 recommending extensions avoid `dangerouslySetInnerHTML` in their `render` callbacks unless the value provenance is verified.

**Task-linked follow-up**: TASK-SEC-002

---

### SEC-003 — LOW — Maintainer trap: `array_merge( $filtered, $row )` argument order is the sole guarantee against extension overwrite of built-in keys

- **Finding ID**: SEC-003
- **Location**: `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-4 snippet — `$data[] = array_merge( $filtered, $row );`; `contracts/rest-api.md` §PHP Row Filter §Invariants
- **OWASP Category**: A05:2025 — Security Misconfiguration (invariant enforced only by argument ordering, not by test)
- **CWE**: CWE-807 — Reliance on Untrusted Inputs in a Security Decision
- **CVSS Score**: 3.7 (Low) — AV:N / AC:H / PR:H / UI:N / S:U / C:L / I:L / A:N

**Description**:
The FR-027 invariant "extensions cannot overwrite the seven built-in row keys" depends entirely on the argument order of `array_merge( $filtered, $row )` — later arrays' keys clobber earlier ones. A refactor that swaps to `array_merge( $row, $filtered )` (or a defensive-looking maintainer edit that reads "put the raw row first for clarity") silently inverts the invariant: an extension can now inject `is_exposed => false` for any ability and hide it from the UI without persisting a row, or set `has_override => true` to mislead the UI's "override" indicator.

**Recommendation**:

1. Add a PHPUnit test to TASK-4 (or the new SEC-003 fold-in) that asserts an extension callback returning `array( 'slug' => 'attacker-controlled', 'is_exposed' => true, 'has_override' => false )` for a hidden ability CANNOT change those built-in keys in the response. Test both the row filter and the "built-in re-assertion" invariant.

2. Add a one-line PHPCS rule (or a code comment marker) that pins the arg order. The docblock comment ABOVE the `array_merge` line should read `// SEC-003 invariant: built-in keys ($row) MUST be the LATER arg — DO NOT reorder without updating tests.`

3. Consider replacing `array_merge` with an explicit loop that only allows keys not already in `$row`:
   ```php
   foreach ( $filtered as $key => $value ) {
       if ( ! array_key_exists( $key, $row ) ) {
           $row[ $key ] = $value;
       }
   }
   $data[] = $row;
   ```
   Argument-order-independent and self-documenting.

**Task-linked follow-up**: TASK-SEC-003

---

### SEC-004 — LOW — Concurrent effective-change detection may fire `acrossai_mcp_ability_exposure_changed` with stale `$was`

- **Finding ID**: SEC-004
- **Location**: `specs/017-per-server-ability-selection/contracts/rest-api.md` §Side effects; `spec.md` §FR-024 — the action semantics require reading the resolver BEFORE upsert to determine `$was`, then reading after upsert to determine `$now`
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (observability accuracy under concurrency)
- **CWE**: CWE-778 — Insufficient Logging (in the specific sense of "logged data can be inaccurate")
- **CVSS Score**: 2.8 (Low) — AV:N / AC:H / PR:H / UI:N / S:U / C:N / I:L / A:N

**Description**:
Two concurrent POST requests targeting the same `(server_id, ability_slug)` pair will both call `ExposureResolver::resolve()` before their respective upserts. If Admin A flips exposed→hidden and Admin B flips exposed→hidden at the same instant, both callbacks read `$was = true`, both compute `$now = false`, both consider it an effective change, and both fire the action. An external log subscriber sees two "true → false" transitions when only one actually happened. A stricter concurrency window (Admin A flips true→false while Admin B flips false→true, interleaved) can log the `$was` from BEFORE A's write as B's `$was`, missing the intermediate state.

Not a security vulnerability per se — no data integrity is compromised (the DB row's `is_exposed` is authoritative and correct). But operators using the action to build an audit trail (the entire reason FR-024 exists) will see inconsistent trails under load.

**Recommendation**:

1. Document the caveat in the `acrossai_mcp_ability_exposure_changed` docblock: *"Under concurrent writes to the same (server, ability) pair, the `$was` value reflects the value the resolver returned at the beginning of THIS request — it may not match the actual pre-write DB state if another writer commits between our resolver read and our upsert. Subscribers building strict audit trails should consult the DB's `updated_at` column for authoritative ordering."*

2. If strict-ordering is required in a future feature, compute `$was` from the row itself as returned by the upsert's `SELECT ... FOR UPDATE` (BerlinDB does not expose this natively; would need a subclass Query method). Deferred; not required for F017.

**Task-linked follow-up**: TASK-SEC-004

---

### SEC-005 — INFORMATIONAL — Third-party `acrossai_mcp_ability_row` callbacks execute inside every READ request

- **Finding ID**: SEC-005
- **Location**: `specs/017-per-server-ability-selection/contracts/rest-api.md` §PHP Row Filter — callbacks fire once per ability on every GET
- **OWASP Category**: A05:2025 — Security Misconfiguration (extension-side DoS potential)
- **CWE**: CWE-807 — Reliance on Untrusted Inputs (extension-controllable side effects during READ)
- **CVSS Score**: 2.6 (Low) — AV:N / AC:H / PR:H / UI:N / S:U / C:N / I:N / A:L

**Description**:
Standard WP `apply_filters()` semantics apply: an extension callback can perform arbitrary I/O — external HTTP calls, additional DB writes, filesystem access. For every ability × every GET request. A well-intentioned but poorly-implemented extension (e.g. one that calls `wp_remote_get()` per ability to fetch metadata) will multiply the request cost by the ability count. An adversarial extension author who already has plugin-installation authority can weaponize this into a targeted DoS surface. However, this is the standard WP filter contract — same risk applies to every plugin that ships an `apply_filters()` call.

**Recommendation**: Document in `docs/extending-abilities-tab.md` (TASK-9):

> **Performance contract**: `acrossai_mcp_ability_row` fires once per registered ability on every GET. Callbacks MUST be O(1) with respect to network / disk / DB work. If your extension needs external data, prefetch it once outside the callback (e.g., in `admin_init` or via a cached transient) and read from cache inside the callback.

Also consider adding an explicit hint in the TASK-4 controller code comment that operator-facing timing metrics should include the total time spent inside filter callbacks — surfaces slow extensions without blaming the core plugin.

**Task-linked follow-up**: None required — advisory documentation only.

---

### SEC-006 — INFORMATIONAL — Filter-callback error messages surface unredacted in the browser console

- **Finding ID**: SEC-006
- **Location**: `specs/017-per-server-ability-selection/contracts/js-hooks.md` §Failure Modes; `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-6 snippet — `console.error( \`[acrossai-mcp-manager] filter "${ name }" threw:\`, err );`
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (information disclosure to non-admin viewers via console shoulder-surf)
- **CWE**: CWE-778 — Insufficient Logging (in the sense of over-inclusive log messages)
- **CVSS Score**: 2.4 (Low) — AV:L / AC:H / PR:H / UI:R / S:U / C:L / I:N / A:N

**Description**:
The defensive boundary `safeApplyFilters()` logs the raw `err` object from any throwing filter callback. If a companion plugin's `render` accidentally includes a secret in its error message (e.g., `throw new Error( 'API key ' + apiKey + ' rejected' )`), that secret appears in the browser console. The admin who triggered the render sees it, but so does anyone shoulder-surfing, screen-sharing, or looking at a screenshot — including support staff without `manage_options`.

**Recommendation**: Document in `docs/extending-abilities-tab.md`:

> Extension error messages surface in the browser console via `safeApplyFilters()`. Do NOT include secrets, tokens, PII, or internal implementation details in errors your `render` / filter callbacks throw. Prefer opaque error identifiers.

No core-plugin code change required — the alternative (redacting the error object) removes valuable diagnostic information for legitimate use.

**Task-linked follow-up**: None required — advisory documentation only.

---

## Confirmed Secure Patterns

The following patterns are correctly reflected in the plan and were verified against the security constitution + memory:

- **REST permission gating** — both routes require `current_user_can( 'manage_options' )` (S2 / FR-012 / Constitution §III). No `__return_true`.
- **Boundary sanitization** — `absint()` for `server_id`, `sanitize_text_field()` for `ability_slug`, `(bool)` cast for `is_exposed` (Constitution §III).
- **Prepared queries via BerlinDB** — all persistence flows through the BerlinDB Kern layer; no raw `$wpdb` interpolation added (S4 / Constitution §III).
- **Silent phantom-version guard** — `Table::maybe_upgrade()` copies the F011 pattern verbatim: `if ( ! $this->exists() ) delete_option( $this->db_version_key );` — preserves data on ops mistakes without noisy admin notices.
- **F012 uninstall opt-in gate honored** — new DROP TABLE lands AFTER the `acrossai_mcp_uninstall_delete_data` short-circuit (`DEC-UNINSTALL-OPT-IN-GATE`).
- **Slug allowlist against `wp_get_abilities()`** — POST rejects unknown slugs with 400, zero rows written (FR-011). Prevents unbounded row growth.
- **No secrets stored** — feature does not persist credentials, tokens, or PII.
- **Nonce via `X-WP-Nonce` + `apiFetch.createNonceMiddleware`** — standard WP-REST CSRF defense (S1).
- **Singleton private ctors** — `AbilitiesController` follows F011 shape (S6).
- **Trailing-slash `rest_url()` defense** — `untrailingslashit( rest_url() )` in localize call (B17 precedent).
- **B18 TINYINT-as-string defense** — `is_exposed` cast to `(bool)` at every boundary; MemorySynthesis explicitly flags this.
- **`@experimental` stability marker** — DEC-CLIENT-RENDERER-PUBLIC-API precedent applied to all new filter/action names.
- **Defensive JS filter boundary** — `safeApplyFilters()` catches / logs / falls back; FR-029 documented.
- **Additive-only merge invariant** — built-in fields / actions / row keys re-asserted after every filter; documented in both contract files.

## Action Plan & Next Steps

### Remediation Priority

| ID | Severity | Fold into | Blocking? |
|---|---|---|---|
| SEC-001 | HIGH | New TASK-10 (Enforcement wiring) OR spec-softening for deferred enforcement | **YES — spec/plan gap must be resolved before implementation** |
| SEC-002 | MODERATE | TASK-9 (`docs/extending-abilities-tab.md` — trust contract section) + contracts/rest-api.md + contracts/js-hooks.md | Yes — trust contract must be written before companion plugins consume it |
| SEC-003 | LOW | TASK-4 (add PHPUnit invariant test) + code comment | Recommended before merge |
| SEC-004 | LOW | Docblock on `acrossai_mcp_ability_exposure_changed` | Recommended before merge |
| SEC-005 | INFO | TASK-9 (performance contract section) | Advisory |
| SEC-006 | INFO | TASK-9 (error-message contract section) | Advisory |

### Recommended Follow-up

**SEC-001 is blocking.** Recommend running `/speckit-security-review-followup` to convert the finding into an explicit TASK-10 (Enforcement wiring) OR to trigger a `/speckit-clarify` round that softens FR-007 + User Story 1's enforcement claim and defers enforcement to a follow-up feature. Either resolution is acceptable — the current state (spec promises enforcement, plan does not deliver it) is not.

### Durable Memory Preservation Check

Two patterns surfaced by this review are candidates for durable capture, but per `PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION` I recommend deferring the capture until implementation lands and a second consumer validates them:

1. **"Storage-vs-enforcement decoupling" pattern** — F017 stores decisions in a BerlinDB table while F015 enforces at `mcp_adapter_pre_tool_call`. The two features must be explicitly bridged by a filter callback on the storage-plugin side. Capture as a new DEC-* only after SEC-001 is resolved and the bridge pattern is confirmed.

2. **"Additive-only merge invariant for public-API filters"** — the `array_merge( $filtered, $row )` order-dependent invariant plus its PHPUnit test is a reusable shape for any future filter that lets extensions extend a fixed-key payload. Capture after F017 ships and a second consumer (e.g. a future admin tab that adds column extensibility) reuses the pattern.

Neither warrants an immediate `/speckit-memory-md-capture` invocation at plan-review time.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md | plan | 2026-07-07 | HIGH | C:0 H:1 M:1 L:2 | A01,A03,A05,A09 |
```
