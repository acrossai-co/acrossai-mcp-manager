# Architecture Violation Detection (Feature 013)

**Reviewed plan**: `specs/013-per-server-tabs-refactor/plan.md`
**Constitution**: `.specify/memory/constitution.md` §I-§VII
**Memory synthesis**: `specs/013-per-server-tabs-refactor/memory-synthesis.md`
**Date**: 2026-07-03
**Reviewer**: governed-plan orchestrator (inline architecture-guard violation detection)

---

## Scope

F013 is a NET-ADDITIVE architectural change:
- 13 new admin-side classes (AbstractServerTab, Registry, 11 tab subclasses)
- 4 new public-side classes (AbstractClientRenderer, 3 Block subclasses)
- 1 new REST controller (ClientRendererController)
- 2 ported WP_List_Table classes (from reference plugin)
- 4 new PHPUnit test files
- 2 new markdown integration examples

The refactor also DELETES the 4 per-tab render methods from `admin/Partials/Settings.php`.

Scope of this review: verify each new class + hook wiring against §I-§VII of the constitution + all 15 A-* architecture constraints from INDEX.md + F011/F012 DECs.

## Violations

| ID | Category | Severity | Location(s) | Summary | Evidence/Rationale |
|:---|:---|:---|:---|:---|:---|
| — | — | — | — | **Zero HARD violations detected.** | See boundary verification below. |

## Boundary Verification

| Boundary | Expected (per Constitution + Memory) | Delivered by F013 plan | Verdict |
|:---|:---|:---|:---|
| **§I Modular Architecture** | Each feature module self-contained, independently testable, shared logic in `includes/Utilities/` | 11 tab subclasses + 3 Renderer classes + Registry, each independently testable via PHPUnit at TASK-1 + TASK-4; shared helpers on Abstract base classes; no cross-tab imports (Registry is the ONLY dispatch point) | ✅ |
| **§II WPCS + PHPStan L8** | Zero errors plugin-wide | Plan explicit gates at TASK-1..TASK-9 DoD | ✅ (gate) |
| **§III Security** | Nonce + cap + prepare() + escape at rendering | FR-021..024 lock down every risk vector; SEC-013-002..004 mitigations at TASK-4 | ✅ |
| **§IV DataForm / DataViews mandate** | New admin forms use DataForm | Renderer layer is NOT a new admin form — it's a config-display + WordPress-core Application Password creation flow. §IV DataForm carve-out precedent from DEC-VENDOR-SETTINGS-TAB-INTEGRATION (F012) applies. To be reaffirmed at TASK-9 in DEC-CLIENT-RENDERER-PUBLIC-API. | ⚠ SOFT (carve-out, precedent exists) |
| **§V Extensibility Without Core Modification** | Third-party extensibility via WP hooks | 2 filters + 1 action hook + 3 shortcodes + REST endpoint IS the extensibility API | ✅ |
| **§VI Reusability & DRY** | No code duplication | AbstractServerTab helpers + AbstractClientRenderer helpers + FR-007 thin-delegate rule for 3 client tabs + grep gates at TASK-8 + cross-context byte-identity PHPUnit at TASK-8 | ✅ (gate) |
| **§VII Definition of Done** | Per-task gates | Every TASK-N block has explicit DoD in the planning doc | ✅ |
| **A1 — Hooks in `Main.php` only** | All `add_action`/`add_filter` in `define_admin_hooks()`/`define_public_hooks()` | Plan T4: Registry wires `acrossai_mcp_client_classes` filter + `acrossai_mcp_render_client_block` action + 3 shortcodes + REST route via Loader — all in `Main.php::define_public_hooks()` | ✅ |
| **A2 — Singleton `instance()` pattern** | Every feature class is a singleton | Registry + 3 Renderer classes are singletons; AbstractServerTab subclasses are stateless render helpers per A11 exemption family | ✅ (A2 primary + A11 exemption) |
| **A6 — Leading-`\` FQN in Includes namespace** | Cross-namespace references use `use` or leading-`\` FQN | Plan T1 references `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()` verbatim; tab classes will use leading-`\` FQN per B15 lesson | ✅ |
| **A9 — Shared constants in `Utilities/`** | Constants live in final utility classes | Plan consumes `AdminPageSlugs::PARENT` for URL building; F012 established `AdminPageSlugs::SETTINGS_TAB` precedent; F013 adds no new constants that qualify | ✅ |
| **A10 — WP_List_Table singleton exemption** | Public ctor required, never Loader-wired | Plan T3 ports 2 ListTable classes with public ctor; instantiated per-render inside Renderer bodies; never Loader-wired | ✅ |
| **A11 — Pure service class exemption** | Stateless class, no hook registration, no ctor args | AbstractServerTab subclasses are stateless render classes; instantiated by Registry; no state, no hook registration in class body | ✅ |
| **DEV1 — WP_List_Table exception, narrowed by DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG** | ListTable classes permitted for per-server-tab inspectors | Plan T3 restores CliAuthLogListTable + ConnectorAuditLogListTable as per-server-tab inspectors ONLY (not standalone submenus). Cite DEC in file docblock. | ✅ (SOFT — carve-out precedent explicit) |
| **DEV4 — Vendor bootstrap in plugin entry file** | Not affected by F013 | Plan doesn't touch `acrossai-mcp-manager.php` bootstrap; vendor package present at F013 runtime | ✅ (unchanged) |
| **DEC-BERLINDB-TABLE-REQUEST-BOOT (F011)** | BerlinDB Tables booted before Query use | Plan T1-T5 all defer BerlinDB Query calls to Loader-wired admin_init callbacks (Registry->render() is called from Settings.php::render_edit_page() which runs at admin_menu / current_screen — well after F011's request-time boot). Renderer::render() consumes MCPServerQuery + CliAuthLogQuery + OAuthAuditQuery; all safe. | ✅ |
| **DEC-VENDOR-SETTINGS-TAB-INTEGRATION (F012)** | Singleton + template method + shared-helper class pattern | AbstractServerTab + Registry echo this pattern verbatim; AbstractClientRenderer inherits the same architectural family | ✅ |
| **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG (F012)** | Standalone submenu removals require accompanying whitelist entry removal; per-server-tab inspection is blessed | Plan T3 restores CliAuthLogListTable as per-server-tab inspector, EXPLICITLY permitted by this DEC. No standalone submenu is re-added. | ✅ (LOAD-BEARING — carve-out explicit) |
| **B9 — PHPUnit `#[DataProvider]` attribute** | Tests use PHP attribute, not `@dataProvider` annotation | Plan T1 + T4 + T8 tests explicitly cite B9 | ✅ |
| **B15 — Grep gates handle FQN + short-name forms** | Regex accounts for both spellings | Plan T2 grep on 4 old method names uses `render_general_tab\|render_access_control_tab\|render_claude_connector_tab\|render_tokens_tab`; T8 grep on `class .*Tab extends AbstractServerTab` covers all class-declaration spellings | ✅ |
| **B16 — Mixed placeholders forbidden** | printf uses one placeholder style consistently | Plan CONSTRAINTS + AbstractClientRenderer `render_feature_disabled_notice()` example uses ALL-numbered format string; explicit no-mix rule | ✅ (gate at TASK-4 review) |

## Cross-Cutting Analysis

### Intent Divergence
- Spec says F013 = "port + refactor + public API." Plan matches. Zero intent divergence.

### Hallucinated Abstractions
- `AbstractServerTab`, `Registry`, `AbstractClientRenderer`, `ClientRendererController`, 3 `Block` classes, 2 ported `ListTable` classes — all have concrete implementation targets in the plan. No abstraction is "mentioned but missing implementation task."

### Boundary Erosion
- The plan explicitly grep-gates against boundary erosion (client-config render logic MUST live only in `public/Renderers/`; grep at TASK-8 enforces).
- The plan explicitly ban admin tab classes from containing `<form method="post">` / `wp_nonce_field(` / `<pre>` / `<textarea>` / `Configuration JSON` — all enforced by grep gates.

### Tight Coupling
- Registry is a singleton dispatch point. Tab classes depend on Registry (for URL building via `server_edit_url()`). Registry does NOT depend on any tab class beyond the fallback `OverviewTab` — acceptable one-way coupling.
- Admin tab classes for the 3 client-tab trio (NpmTab, ClientsTab, ClaudeConnectorTab) depend on their corresponding Renderer Block. Renderer Blocks do NOT depend on the admin tab layer — good.
- Ported ListTable classes depend on the F011 Query API. F013 doesn't add new inverse dependencies from F011 into F013.

### Contract Mismatch
- Public API contracts (2 filters + action hook + shortcodes + REST endpoint) are documented as `@experimental` until 1.0.0. No stability contract mismatch since none is claimed yet.

### Constitution Breach
- **Zero HARD constitution breaches.** §IV DataForm mandate has a SOFT deviation (carve-out) documented via DEC precedent + planned reaffirmation at TASK-9.

## Refactor Tasks Generated

**None.** Zero HARD violations means the refactor generator has no drift to convert into tasks. The two SOFT items (§IV DataForm carve-out reaffirmation + DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG-narrowing citation) are already handled in-plan:
- SOFT #1 → DEC-CLIENT-RENDERER-PUBLIC-API append at TASK-9 (planning doc already specifies this).
- SOFT #2 → `CliAuthLogListTable.php` docblock citation at TASK-3 (spec FR-008 explicitly requires this).

## Task Synchronization

- **Status**: Synced. All 9 tasks in the planning doc map cleanly to Constitution + memory constraints.
- **Missing implementations**: None — every task-referenced file has a concrete plan section.
- **Pending tasks**: All 9 tasks are ahead-of-time defined; tasks.md generation at `/speckit-tasks` will convert task groups → atomic implementation tasks.

## Metrics

- **Constitution compliance**: 100% (7/7 principles; §IV carve-out precedent-authorized)
- **Boundary integrity**: **Strong** — Registry / AbstractServerTab / AbstractClientRenderer boundaries cleanly separated
- **Architectural risk**: **LOW** — largest additive surface since F011, but every new subsystem is bounded by explicit grep gates and PHPUnit invariants
- **Security-architecture conflicts detected**: **Zero**
- **A-* architecture constraints checked**: 15 (A1-A15), plus 4 DEV entries, plus 4 relevant DECs

## Recommendations

1. **Continue** to `/speckit-tasks` — the plan is architecture-clean.
2. **At TASK-4 code review**: verify the two RECOMMEND items from `security-constraints.md` (SEC-013-002 `(array)` cast at `resolve_context()`; SEC-013-005 cache-buster hint on disabled notice).
3. **At TASK-9**: verify DEC-CLIENT-RENDERER-PUBLIC-API includes the "experimental until 1.0" clause AND the §IV DataForm carve-out reaffirmation.
