# Security Review — Plan-Level Constraints (Feature 013)

**Reviewed plan**: `specs/013-per-server-tabs-refactor/plan.md`
**Reviewed spec**: `specs/013-per-server-tabs-refactor/spec.md` (incl. 5 Clarifications)
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-07-03
**Reviewer**: governed-plan orchestrator (inline `speckit-security-review-plan` fallback)

---

## Scope

Feature 013 is a refactor + porting + new-public-API feature. Security-relevant surfaces:

1. **New public API extension points** — 2 filters (`acrossai_mcp_client_classes`, `acrossai_mcp_client_block_context`) + 1 action hook (`acrossai_mcp_render_client_block`) + 3 shortcodes + 1 REST endpoint (`/generate-app-password`). Third parties are expected to consume these; the plugin's threat model MUST assume the third party is trusted-but-fallible.
2. **Application Password generation flow** — the REST endpoint invokes `WP_Application_Passwords::create_new_application_password()`, which grants persistent WordPress access. Misconfiguration = authentication bypass.
3. **Cross-context nonce reuse defense** — nonces are bound to `$server_id` + context slug; a nonce minted for admin MUST NOT validate against a BuddyBoss-context POST.
4. **F012 settings-toggle gating** — `NpmClientBlock` + `ClaudeConnectorBlock` respect `acrossai_mcp_npm_login_enabled` / `acrossai_mcp_claude_connectors_enabled` uniformly across contexts (defense-in-depth against shortcode/embed bypass).
5. **Renderer capability check** — every `Renderer::render()` calls `current_user_can( $context['cap'] )` with a context-provided cap (default `manage_options`), NEVER hardcodes.
6. **Preserved F011 invariants** — SEC-001 atomic-CAS (via CliAuthLog Query in F013's ported `CliAuthLogListTable` — read-only path, preserves atomicity).
7. **Preserved F012 invariants** — MCP settings tab, uninstall opt-in gate, standalone CLI Auth Log admin surface removal. F013 does NOT touch any F012 code path.

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Admin browser → per-server-edit form POST | inbound at HTTP POST | Standard WP admin form: nonce forge, cap escalation, CSRF replay | `AbstractServerTab::nonce_field()` binds nonce action `'acrossai_mcp_manager_server_' . (int) $server['id']`. Vendor `SettingsRenderer` cap-checks upstream. |
| Third-party plugin → `do_action('acrossai_mcp_render_client_block', ...)` | inbound at PHP call | Third party could pass an invalid `$client_slug`, an inflated `$context['user_id']`, or a `$cap` that a low-privilege user has (e.g., `'read'`) | Registry silently no-ops on unknown `client_slug`. `Renderer::render()` re-runs `current_user_can( $context['cap'] )` — the cap check is the third party's responsibility to set correctly; the Renderer honors it. `user_id` mismatch is caught downstream at the "Generate App Password" button + REST endpoint (see below). |
| BuddyBoss profile view → third-party PHP consuming Renderer with `user_id = displayed_user_id` | at page render | Malicious BuddyBoss admin views another user's profile with `user_id` set to that other user + clicks "Generate Application Password" | `passwords_generate_button()` renders as **disabled** when `$context['user_id'] !== get_current_user_id()`. UI communicates the constraint. |
| BuddyBoss frontend → REST POST `/generate-app-password` with malicious `user_id` | inbound at HTTP POST | Editor with `edit_users` cap crafts a POST setting `user_id` to admin's ID to mint a persistent admin credential | `permission_callback` returns 403 unless `absint($body['user_id']) === get_current_user_id()`. Locked in at FR-023. |
| Third-party frontend → REST POST with nonce minted for `context='admin'` | inbound at HTTP POST | Attacker captures an admin's nonce from admin context, replays it against a BuddyBoss-context POST | Nonce action name binds `context` slug: `'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug`. Nonce minted with `context='admin'` fails `wp_verify_nonce()` against `context='buddyboss-profile'`. FR-022. |
| Third-party filter callback → `acrossai_mcp_client_classes` filter | at filter apply time | Third party appends a non-existent FQN or a class that doesn't extend `AbstractMCPClient` | FR-016b: `MCPClientsBlock` silently skips invalid FQNs (no fatal, no admin notice). Preserves robustness under third-party misuse. |
| Third-party filter callback → `acrossai_mcp_client_block_context` filter | at filter apply time | Third party returns a non-array, or sets `user_id` to a random int, or nukes `cap` | Best-effort: Renderer treats `$context['cap']` as the source of truth; if missing, falls back to `'manage_options'`. `$context['user_id']` mismatch → disabled Generate button (FR-024). Non-array return would need to be caught at `resolve_context()` — reviewer note: cast to array + `wp_parse_args()` at boundary. |
| F012 settings-toggle bypass attempt via shortcode | at render | Third-party embed uses `[acrossai_mcp_npm_block]` on a public page hoping the shortcode bypasses `acrossai_mcp_npm_login_enabled` | FR-017: gate lives INSIDE `NpmClientBlock::render_body()`. Shortcode consumers get the same disabled notice. FR-020 verified via grep gate at TASK-8. |
| REST route `/generate-app-password` payload | inbound POST body | Overflowing user_id, negative server_id, unicode client_slug injection | `absint($body['user_id'])` + `absint($body['server_id'])` + `sanitize_key($body['client_slug'])` + `sanitize_key($body['context'])` at REST boundary. |

## Authorization Assumptions

F013 introduces:

- **New REST route `/generate-app-password`** with explicit `permission_callback` per S2. NOT `__return_true`. Rejects on any of: not logged in, `user_id` mismatch, nonce mismatch (see FR-023). No S7/S8 exception applies — this is neither an OAuth token endpoint nor a CLI device-code flow.
- **New capability check pattern on Renderer** — cap check via `$context['cap']` (default `manage_options`) is the extension point BuddyBoss/WooCommerce need to embed the block in non-admin contexts. Admin tabs pass `manage_options` unconditionally.
- **NO new capabilities introduced** — the plugin does not register any custom capabilities via `add_cap`.

F013 preserves:

- **Vendor `PageRenderer` cap check** (F012 DEC-VENDOR-SETTINGS-TAB-INTEGRATION) — not affected; F013 doesn't touch the shared Settings page.
- **F009 MCP Controller's `class_exists('\WP\MCP\Plugin')` guard** — not touched.
- **F010 pre-activation vendor autoload guard** (DEV4) — not touched.
- **SEC-001 atomic-CAS** on CliAuthLog `redeem_atomic()` (F011 FR-006) — F013 only READS the log via `CliAuthLogListTable`; no writes, no impact on atomicity.

## Data Isolation & Validation Risks

- **Per-server data isolation** — every form/nonce/URL binds `(int) $server['id']`. A form submitted from server 1's edit page CANNOT modify server 2. Preserved by AbstractServerTab helpers.
- **User-level data isolation** — Application Password generation is locked to `get_current_user_id()` at both UI + REST layers (FR-023, FR-024). Even a `manage_options`-holding admin cannot mint a password for another user via F013's REST endpoint.
- **F012 settings-toggle gating isolation** — the gate is enforced INSIDE the Renderer, not the caller. External embeds cannot bypass by omitting the gate check.
- **Third-party filter callback risk** — `acrossai_mcp_client_block_context` could return a `$context` with a low cap or with `user_id` != current — that is by design (BuddyBoss legitimately wants `cap='read'`). But the `user_id` mismatch defense at UI + REST layers ensures no Application Password can be minted for a different user regardless of what the filter returns.
- **JSON config injection** — the `Configuration JSON` `<pre>` block renders per-server data from `MCPServerQuery`. All values escape via `esc_html()` before insertion into the `<pre>` element. No user-supplied data reaches the JSON output beyond what the admin has already saved for that server (which is nonce+cap+prepare()-protected on save).

## Async / Concurrency Security Context

- **REST POST `/generate-app-password`** is synchronous; `WP_Application_Passwords::create_new_application_password()` is atomic per WordPress core. No concurrency risk introduced by F013.
- **Nonce lifetime** — WordPress nonces last 24h; not customized by F013. Third-party consumers should be aware nonce expiry is not visible in the block UI. Nice-to-have follow-up: expose nonce timeout via `wp_nonce_tick()` in a future feature.
- **Shortcode caching** — third-party pages caching a rendered shortcode could freeze the F012 toggle state. Recommendation: the disabled notice includes a `<meta name="expires">` or similar cache-buster hint; deferred to plan review at TASK-4 code review.

## Missing Gates / Recommendations

- **RECOMMEND — Type-check `acrossai_mcp_client_block_context` filter return**: Per Trust Boundaries table, a third-party filter callback returning a non-array would fatal at `wp_parse_args()`. Recommend `AbstractClientRenderer::resolve_context()` explicitly cast to `(array)` before `wp_parse_args()` — TASK-4 implementer note.
- **RECOMMEND — Explicit test for filter returning non-array**: PublicApiTest should include a data provider case where the filter returns `null` / `false` / `'string'` — assert Renderer proceeds with defaults rather than fatal.
- **RECOMMEND — Rate-limit `/generate-app-password`**: The REST endpoint could be rate-limited to prevent abuse. Not strictly required (Application Passwords are user-generated; WordPress core doesn't rate-limit its own Application Passwords admin flow). Deferred.
- **RECOMMEND — `docs/integrations/*` examples MUST show correct `cap` value**: A wrong example steering integrators toward `cap='read'` on a mutating BuddyBoss admin action would create real-world holes. TASK-9 doc reviewer should verify the examples use context-appropriate capabilities.

## Status

**PASS** — no HARD security-architecture conflicts identified. Three RECOMMEND items surface as advisory gates for `/speckit-tasks` to fold into TASK-4 and TASK-9 DoD lines.

Overall risk: **LOW**. F013's security posture matches or slightly strengthens the pre-feature baseline:
- **New**: `/generate-app-password` REST endpoint with user_id lockdown + context-bound nonce (defense-in-depth against admin-impersonation via BuddyBoss embed).
- **New**: cross-context nonce replay defense (context slug in nonce action) — a novel security pattern.
- **New**: F012 toggle gating enforced in Renderer (defense-in-depth against shortcode-bypass).
- **Preserved**: SEC-001 atomic-CAS (F011 FR-006).
- **Preserved**: nonce validation via `AbstractServerTab::nonce_field()` binding to `$server['id']`.
- **Preserved**: `manage_options` capability gate on admin surfaces.
- **Preserved**: F012 uninstall opt-in gate, MCP settings tab, standalone CLI Auth Log admin removal.

## Findings

| ID | Severity | OWASP | CWE | CVSS | Description | Related task |
|:---|:---|:---|:---|:---|:---|:---|
| SEC-013-001 | INFO | A05 | CWE-1104 | 0.0 | Public API is `@experimental` until 1.0.0. Third parties must accept signature drift during the 0.x line. Documented in DEC-CLIENT-RENDERER-PUBLIC-API + docblocks. Not a vulnerability. | TASK-9 |
| SEC-013-002 | LOW | A05 | CWE-20 | 3.7 | Third-party filter callback returning a non-array to `acrossai_mcp_client_block_context` would fatal at `wp_parse_args()`. Mitigation: `(array)` cast at `resolve_context()` boundary. | TASK-4 |
| SEC-013-003 | LOW | A03 | CWE-352 | 3.1 | Cross-context nonce replay is defended by binding context slug to nonce action. If a future feature relaxes the binding (drops the context slug), the defense evaporates. Regression risk. Mitigation: PublicApiTest asserts 403 on cross-context replay; CI-caught if regressed. | TASK-4 |
| SEC-013-004 | LOW | A01 | CWE-863 | 3.7 | Application Password generation could be minted for another user if REST `permission_callback` is regressed. Mitigation: PublicApiTest asserts 403 on `user_id` mismatch; CI-caught. | TASK-4 |
| SEC-013-005 | INFO | A05 | CWE-778 | 0.0 | Shortcode-rendered blocks on pages cached by third-party page-cache plugins could freeze the F012 toggle state. Not a vulnerability, but operator-visible drift. Recommend cache-buster hint at TASK-4 code review. | TASK-4 |
| SEC-013-006 | INFO | A09 | CWE-778 | 0.0 | `docs/integrations/*-example.md` MUST use context-appropriate capability values; wrong examples could steer integrators toward permission-permissive misconfigurations. Reviewer to verify at TASK-9. | TASK-9 |

Overall risk: **LOW** (2 LOW + 4 INFO; zero HIGH/CRITICAL).
