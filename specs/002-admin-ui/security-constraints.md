# Security Constraints — Phase 2: Admin UI

**Date**: 2026-06-17 | **Branch**: `002-admin-ui`
**Authoritative review of**: `plan.md`, `contracts/loader-wiring.md`, `contracts/notice-dismissal.md`, `research.md`

This document captures the trust boundaries, data isolation, and async
security context of the Phase 2 plan. Findings are advisory unless marked
**[P0]** (blocking).

---

## Trust Boundaries

| Boundary | Crossing | Server-side Gate |
|---|---|---|
| **Anonymous → wp-admin** | Any request to `?page=acrossai_mcp_manager` | `current_user_can('manage_options')` checked by `add_menu_page()` capability arg AND in every render callback per FR-013 |
| **Authenticated non-admin → admin actions** | Forged URL, Editor session | `manage_options` check in every handler (US2.5, US3.7, SC-003); menu not rendered to non-admins (US1.5) |
| **Cross-origin → state change** | Forged form POST | `check_admin_referer()` with per-action nonce on every state-changing handler (US2.4, US3.7); admin-ajax dismissal also nonce-gated (`acrossai_mcp_dismiss_adapter_notice`) |
| **DB query input** | User-supplied IDs, names, slugs | `absint()` on IDs, `sanitize_text_field()` / `sanitize_textarea_field()` / `esc_url_raw()` on text + URL fields; BerlinDB `MCPServer\Query` uses `$wpdb->prepare()` internally |
| **HTML output** | All admin renders | `esc_html()`, `esc_attr()`, `esc_url()` at every output point; `esc_html__()` for translated literals |
| **Plugin → vendor (AccessControl)** | Render delegation in Access Control tab | `class_exists()` guard before every vendor call; vendor controls its own internal security |
| **Plugin → vendor (MCP adapter detection)** | `class_exists('\WP\MCP\Plugin')` check | Pure presence check — no method call — no privilege change |

---

## Data Isolation

| Data | Scope | Isolation Mechanism |
|---|---|---|
| MCP Server rows | Single-site (table is per-`$wpdb->prefix`) | BerlinDB Query is per-table; no cross-site reads in single-site scope |
| Application Passwords | Per-server (synthetic user per server) | WordPress core; password hash stored, plaintext returned once and never persisted (Constitution §III bullet 7) |
| Notice dismissal flag | Per-user (`user_meta` on the dismissing user only) | `update_user_meta($current_user_id, ...)` — never bulk write |
| Claude Connector secret | Per-server row (stored in `claude_oauth_client_secret` column) | Plaintext at rest — see Finding F3 below |

---

## Async Security Context

The only async path in this phase is the admin-ajax dismissal handler (`contracts/notice-dismissal.md`):

- **Idempotent**: `update_user_meta($uid, $key, 1)` is safe under repeat fire — value already `1` is a no-op success.
- **Fire-and-forget JS**: No response handling required; failure simply leaves the dismissal pending (notice reappears next page load — acceptable degradation).
- **No race window**: Reads use `get_user_meta()` synchronously during `admin_notices`; the next page load reflects the prior dismissal. No "TOCTOU" gap that matters for a non-security-critical flag.

---

## Findings

### F1. **[Advisory]** Slug field uses `sanitize_text_field()` rather than `sanitize_key()`

- **Where**: FR-007a create handler; FR-009 General-tab save.
- **What**: The source repo (and per Q1's 1:1 port) sanitises `slug` with `sanitize_text_field()`. This permits spaces, uppercase, and some Unicode that could later cause inconsistent comparisons if a different code path normalises slugs differently (e.g., a downstream phase calls `sanitize_title()` for URL generation).
- **Risk**: Low. The DB enforces uniqueness on the literal-byte slug, so a "TestSlug" row coexists with "testslug" without conflict but produces user confusion.
- **Decision**: **Preserve source behaviour for the 1:1 port**. Flag as a follow-up clean-up task for a later phase. Not blocking.

### F2. **[Advisory]** Soft tension: A6 prefers `use` imports; `contracts/loader-wiring.md` uses leading-`\` FQN

- **Where**: `contracts/loader-wiring.md` example body.
- **What**: Inside `Includes\Main`, sub-namespace classes are referenced as `\AcrossAI_MCP_Manager\Admin\Partials\Settings::instance()`. This is safe (leading `\` → absolute FQN), but A6 prefers `use` imports for readability.
- **Risk**: None functional. Slight readability cost.
- **Decision**: Implementer may switch to `use` imports at file top; both styles are accepted. Not blocking.

### F3. **[Advisory]** Claude Connector Client Secret stored plaintext in DB

- **Where**: FR-012; `claude_oauth_client_secret` column.
- **What**: The Claude Connector secret is the credential the plugin presents to Claude (outbound OAuth client credential — analogous to an API key). It is not an inbound credential we receive from a third party, so Constitution §III bullet 7 (OAuth tokens / Application Passwords hashed) does not directly apply. The source repo stores it as a plain VARCHAR; per Q1 1:1 port, that behaviour is preserved.
- **Risk**: Medium. DB compromise → outbound OAuth impersonation. Mitigated by: (a) `manage_options` is required to read or write it, (b) the secret is masked on re-render after first save, (c) BerlinDB writes use prepared statements.
- **Decision**: **Preserve source behaviour** for the 1:1 port. **Flagged for a follow-up phase**: consider wrapping at-rest with `WP_SECURE_AUTH_KEY`-based encryption (Constitution §III could be extended to cover outbound client secrets). Not blocking Phase 2.

### F4. **[Advisory — reminder]** B6/S5: every `admin_url()` MUST be wrapped with `esc_url()`

- **Where**: FR-003 plugin-action-link "Settings" anchor; row-action "Edit" / "Toggle Status" / "Delete" anchors; redirect targets after handlers; the `?page=acrossai_mcp_manager` link emitted in admin notices.
- **What**: B6 records a historical XSS pattern (`admin_url()` is filterable, so an unfiltered injected URL becomes XSS in an HTML attribute). S5 makes wrapping mandatory.
- **Risk**: High **if** the implementation omits `esc_url()` even once.
- **Decision**: Add an explicit guardrail to the implementation tasks: every `admin_url(...)` MUST be wrapped (`esc_url(admin_url(...))` or `esc_attr(admin_url(...))` depending on context). The tasks phase MUST include a grep verification: `grep -rn 'admin_url' admin/ | grep -v 'esc_'` → expected empty.

### F5. **[P0 dependency]** Prerequisite BerlinDB Query classes absent

- **Where**: FR-022, FR-023.
- **What**: `includes/Database/MCPServer\Query` and `includes/Database/CliAuthLog\Query` **do not yet exist** in the new repo (`ls includes/Database/` returns empty). Phase 2 cannot replace `MCPServerTable::` static calls without these classes.
- **Risk**: Implementation will block at task T000 or fall back to writing static-style code that violates FR-022.
- **Decision**: **Already documented as P0 in `plan.md` Technical Context** and required as task T000 verification gate in `/speckit-tasks`. Not a Phase-2 plan defect — a cross-phase coordination requirement.

---

## Summary

| Finding | Severity | Action |
|---|---|---|
| F1 — slug sanitiser scope | Advisory | Document as follow-up; preserve in 1:1 port |
| F2 — `use` import style | Advisory | Implementer choice |
| F3 — Claude secret plaintext | Advisory | Document as follow-up; preserve in 1:1 port |
| F4 — `esc_url(admin_url())` reminder | Advisory **with mandatory verification** | Add grep gate to tasks |
| F5 — prerequisite Query classes | **P0 dependency** | Already enforced as task T000 in plan |

No **Security-Architecture Conflict** detected. No blocking security defect in the Phase 2 plan itself; F5 is a cross-phase coordination prerequisite already documented.
