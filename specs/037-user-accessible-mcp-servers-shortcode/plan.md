# Implementation Plan: User-Accessible MCP Servers Shortcode + Reusable Base Class

**Branch**: `037-user-accessible-mcp-servers-shortcode` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/037-user-accessible-mcp-servers-shortcode/spec.md`

## Summary

Ship the frontend shortcode `[acrossai_mcp_servers]` that, for the current logged-in user, enumerates every MCP server they can access whose F037 Embeds tab has the master toggle ON and at least one enabled connection method — and renders the enabled DTOs per server. Ships alongside a data-only abstract base class `AbstractUserServersRenderer` under `public/Renderers/UserServers/` so companion plugins (planned BuddyBoss add-on, WooCommerce My Account extension, WPUM, MemberPress) can consume the same enumeration primitive without re-implementing the F015 access-control + F037 embeds gate cascade.

**Technical approach**: pure composition. F038 introduces no new DB tables, no new REST endpoints, no new admin surface, no schema drift. Every gate call delegates to a shipped upstream helper: `AcrossAI_MCP_Access_Control::user_has_server_access` (F015 / F032), `AbstractEmbedTransport::is_enabled_for_server` (F037, includes R2 per-request memoization), `AbstractEmbedTransport::get_all_registered_transports` (F037, canonical enumeration), `MCPServerQuery::instance()->query()` (F011). DTO data comes from each transport's `get_dtos()` which routes through F035's `ConnectionMethodRegistry`. Two new filters extend the composition (`acrossai_mcp_user_accessible_servers`, `acrossai_mcp_servers_shortcode_html`). One hook — `add_action('init', …, 'register_shortcode')` — wired inside `Main::define_public_hooks()`.

## Technical Context

**Language/Version**: PHP 8.0+ (plugin supports PHP 7.4 minimum per AGENTS.md; constitution target is PHP 8.1+)
**Primary Dependencies**: WordPress 6.9+; no new composer dependencies. Consumes existing plugin code (F011 `MCPServerQuery`, F015 `AcrossAI_MCP_Access_Control`, F035 `ConnectionMethodRegistry`, F037 `AbstractEmbedTransport`). Optional: `wpboilerplate/wpb-access-control` v2 (F015 wrapper fails open when absent — F038 inherits transitively).
**Storage**: N/A — F038 introduces zero persistent storage. Reads flow through F037's `wp_acrossai_mcp_servers_meta` reader (`_embeds_enabled` + `_embeds_clients` meta keys) via the memoized helper.
**Testing**: PHPUnit (new `user-servers` suite registered in `phpunit.xml.dist` pointing at `tests/phpunit/Public/Renderers/UserServers/`, bootstrapped by `tests/bootstrap-wp.php` — needs WP option store + BerlinDB tables). No JS tests (F038 ships zero JS).
**Target Platform**: WordPress single-site install (matches plugin-wide policy). PHP 8.0+ runtime.
**Project Type**: WordPress plugin — single-project layout under repo root.
**Performance Goals**: `get_accessible_servers()` runs in O(S × T × D) where S = enabled servers, T = registered transports (typical 3), D = DTOs per transport (typical 1–8). Each per-DTO gate call is R2-memoized inside F037. Real-world cost bounded at low milliseconds even for admin views listing 20+ servers. No caching layer introduced.
**Constraints**: Zero new REST endpoints, zero admin UI, zero JS, zero external CSS files. Inline `<style>` block emitted at most once per request. All output escape-at-boundary. Public → includes one-way dependency preserved (grep-enforced).
**Scale/Scope**: Two new PHP files (~150–200 LOC each). One-line insertion in `includes/Main.php`. Two file edits (`phpunit.xml.dist`, `.github/workflows/phpunit.yml`). New PHPUnit suite directory + three test classes (~200–300 LOC total).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Evaluated against **constitution v1.1.0**. See [memory-synthesis.md](./memory-synthesis.md) for the pre-loaded constraint map.

| Principle | Status | Notes |
|-----------|--------|-------|
| **I. Modular Architecture** | ✅ PASS | New self-contained module `public/Renderers/UserServers/`. Zero code duplication — every gate call delegates to a shipped upstream helper (F015 + F035 + F037). No sibling-module coupling. |
| **II. WordPress Standards Compliance** | ✅ PASS | PHPCS strict + PHPStan level 8 + ESLint N/A (no JS). Text domain `'acrossai-mcp-manager'` for every `__()` call. Multisite scope: single-site per plugin-wide policy (documented in spec.md Assumptions). |
| **III. Security First (NON-NEGOTIABLE)** | ✅ PASS | No forms / AJAX / REST → S1 + S2 vacuously satisfied. Escape-at-render mandated at every boundary (FR-014, FR-015). Zero `$wpdb` calls (all reads via shipped helpers). Consent-surface exception NOT invoked — F038 is a data-display surface, not a credential-issuance surface. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | ✅ PASS (vacuous) | F038 introduces zero admin UI. DataForm / DataViews mandate applies only to admin data-input / data-display surfaces. Frontend shortcode is outside its scope. **D37 (React-first for admin UI) also vacuous** — no admin JS surface introduced. |
| **V. Extensibility Without Core Modification** | ✅ PASS | New module; extension via WordPress filters (`acrossai_mcp_user_accessible_servers`, `acrossai_mcp_servers_shortcode_html`) and subclass extension of the abstract base. Optional wpb-access-control integration degrades gracefully (F015 wrapper fail-open contract, inherited transitively — Edge Case 1). |
| **VI. Reusability & DRY Principle** | ✅ PASS | Every shared utility delegates to shipped upstream helpers (F011 `MCPServerQuery`, F015 `AcrossAI_MCP_Access_Control`, F037 `AbstractEmbedTransport`). Grep-gates FR-023, FR-024 enforce delegation at review time. No new utility in `includes/Utilities/` needed. |
| **VII. Definition of Done** | ✅ PASS | DoD applies. Spec.md §Definition of Done Gates enumerates all 10 constitution DoD items plus 5 F038-specific grep-gates. `npm run validate-packages` still passes — F038 ships zero JS/npm dependencies. |

### D36 (`@experimental` public classes MUST be `final`) — Precedent-based deviation

`AbstractUserServersRenderer` lives under `public/Renderers/UserServers/` AND is `@experimental` per DEC-CLIENT-RENDERER-PUBLIC-API. Per strict reading of D36, this would require `final`. Justification:

- **AbstractClientRenderer precedent** — `public/Renderers/AbstractClientRenderer.php` is the shipped precedent: an `abstract` renderer base under `public/Renderers/` extended by `MCPClientsBlock` + `NpmClientBlock` + others. D36 was ratified for F035's `ConnectionMethodRegistry` — a `final` singleton meant to be consumed as-is. It targets **renderer classes meant to be consumed**, not **abstract bases meant to be extended**.
- **The base IS the extension surface** — companion plugins (BuddyBoss add-on, WooCommerce My Account extension) subclass `AbstractUserServersRenderer` to build their own contexts. This is F038's User Story 2 — the whole reason the base exists. `final abstract` is a language contradiction; requiring it would kill the reuse contract.
- **`UserServersBlock` IS `final`** — the concrete shortcode child, which IS meant to be consumed (not extended), is declared `final class` per D36. Companion plugins extending markup use the `acrossai_mcp_servers_shortcode_html` filter (D36-compliant filter-only path) or subclass the base to render however they want (documented separate path).

Recorded as a Complexity Tracking entry below. No new decision-record entry needed — this is the same design pattern as `AbstractClientRenderer`, which predates D36.

## Project Structure

### Documentation (this feature)

```text
specs/037-user-accessible-mcp-servers-shortcode/
├── plan.md                    # This file (/speckit.plan output)
├── spec.md                    # Feature spec (/speckit.specify output)
├── memory-synthesis.md        # Memory synthesis (/speckit.memory-md.plan-with-memory output)
├── research.md                # Phase 0 output (this command)
├── data-model.md              # Phase 1 output (this command)
├── quickstart.md              # Phase 1 output (this command)
├── contracts/                 # Phase 1 output (this command)
│   ├── AbstractUserServersRenderer.contract.md
│   └── UserServersBlock.contract.md
├── checklists/
│   └── requirements.md        # Spec quality checklist
└── tasks.md                   # Phase 2 output (/speckit.tasks — NOT created by this command)
```

### Source Code (repository root)

```text
acrossai-mcp-manager/
├── includes/
│   ├── Main.php                       # EDIT — one insertion inside define_public_hooks() (TASK-3)
│   ├── AccessControl/                 # CONSUMED (F015) — no changes
│   ├── Database/MCPServer/            # CONSUMED (F011) — no changes
│   ├── Database/MCPServerMeta/        # CONSUMED via F037 helper — no direct reads
│   ├── Embeds/                        # CONSUMED (F037) — no changes
│   └── MCPClients/                    # CONSUMED via F035 registry — no changes
├── public/
│   ├── Discovery/                     # CONSUMED (F035) — no changes
│   └── Renderers/
│       ├── EmbedBlock/                # SIBLING (F037) — reference for shape only
│       └── UserServers/               # NEW directory (TASK-1 + TASK-2)
│           ├── AbstractUserServersRenderer.php   # NEW — abstract data-only base
│           └── UserServersBlock.php              # NEW — concrete final singleton shortcode child
├── tests/
│   ├── bootstrap-wp.php               # CONSUMED — provides WP + BerlinDB test env
│   └── phpunit/
│       └── Public/
│           └── Renderers/
│               └── UserServers/       # NEW directory (TASK-1, TASK-2, TASK-5)
│                   ├── AbstractUserServersRendererTest.php
│                   ├── UserServersBlockTest.php
│                   └── ThirdPartyExtensibilityTest.php
├── phpunit.xml.dist                   # EDIT — new <testsuite name="user-servers"> entry (TASK-4)
└── .github/workflows/
    └── phpunit.yml                    # EDIT — new step running user-servers suite (TASK-4)
```

**Structure Decision**: Standard WordPress plugin single-project layout (matches every prior feature 010..037). Namespace `AcrossAI_MCP_Manager\Public\Renderers\UserServers` derives directly from the directory path per constitution §Architecture & UI Standards. Tests under `tests/phpunit/Public/Renderers/UserServers/` mirror the source tree exactly (matches `discovery` + `embeds` suite precedents).

## Complexity Tracking

| Violation / Deviation | Why Needed | Simpler Alternative Rejected Because |
|-----------------------|------------|--------------------------------------|
| `AbstractUserServersRenderer` is `abstract` (not `final`) under `public/Renderers/` despite `@experimental` D36 rule | The base IS the third-party extension surface for BuddyBoss add-on, WooCommerce My Account extension, WPUM, MemberPress. `final abstract` is a language contradiction; the whole reuse contract dies without subclass extension. | Moving the base to `includes/UserServers/` (paralleling `AbstractMCPClient` at `includes/MCPClients/`) — rejected because (a) the file logically belongs alongside `UserServersBlock` (same DTO shape, same namespace segment); (b) `AbstractClientRenderer` at `public/Renderers/AbstractClientRenderer.php` (shipped in **0.1.3** per README.txt, predates D36 which was ratified F035 / 0.1.8) is the shipped precedent for an abstract renderer base under `public/`. D36's target failure mode (delegation-invariant defeat) does not apply because F038 IS the gate-application layer, not a gate-enforcer other features must hit. Keeping F038 consistent with the pre-existing precedent minimizes cognitive load for future maintainers. |

**No Constitution violations.** The single D36 deviation is precedent-based and self-contained (no ripple effects on other principles).
