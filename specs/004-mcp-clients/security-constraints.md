# Security Constraints — Phase 4: MCP Client Classes

**Date**: 2026-06-17 | **Branch**: `004-mcp-clients`
**Authoritative review of**: `plan.md`, `spec.md`, `research.md`, `quickstart.md`

This phase ships a **pure service layer** with deliberately small attack
surface. The security posture is dominated by *what this module is NOT* —
it has no SQL surface (no DB), no SSRF surface (no HTTP), no XSS surface
of its own (no rendering), no path-traversal surface (no filesystem
writes), no privilege boundary (no admin endpoints), no deserialization
surface (no `unserialize`), no session/cookie writes.

---

## Trust Boundaries

| Boundary | Crossing | Server-side Gate |
|---|---|---|
| **Caller → MCPClient instance** | Phase 2's `ApplicationPasswords::render_for_server` (or any future consumer) invokes `new ClientName()` then `get_config_snippet($url, $token)` | None **inside this module**. The caller is responsible for: (a) ensuring the current user has `manage_options` to see snippets, (b) sanitizing `$server_url` (already done by Phase 2's DB layer), (c) ensuring `$auth_token` came from `WP_Application_Passwords::create_new_application_password()` and is the legitimate user's own token. |
| **MCPClient instance → output** | Snippet returned (string or array) | None inside this module. Output escaping is the **consumer's** responsibility (spec security checklist + B8 in memory): array snippets MUST be `wp_json_encode()`'d at serialisation; string snippets MUST be `esc_html()`'d before being placed inside HTML. |
| **Snippet → end user's filesystem** | User copies snippet, pastes into AI tool config file | Outside the plugin's trust boundary entirely. The token in the snippet is the user's own Application Password — they have legitimate access to it. |

---

## Data Isolation

| Data | Scope | Isolation Mechanism |
|---|---|---|
| `$server_url` (input) | Per-call argument | Function-scoped; never written to a class property or persisted |
| `$auth_token` (input) | Per-call argument | Function-scoped; **never logged**, never written to a class property, never persisted. Used only as a string substitution in the returned snippet. |
| Snippet output | Returned to caller; caller renders to current user | Returned by value, not by reference. No global state. |

---

## Async Security Context

This phase has **no async code paths**:
- No REST endpoints
- No admin-ajax handlers
- No cron jobs
- No event loops, promises, or callbacks beyond `is_array(get_config_snippet(...))` dispatch in the consumer

---

## Findings

### F1. **[Advisory — Centralized in plan]** Token appears in plaintext inside the returned snippet

- **Where**: `get_config_snippet()` return value in every concrete client (spec FR-006).
- **What**: The Application Password is embedded as plaintext in the snippet (e.g. `'WP_API_PASSWORD' => $auth_token`). The user copies this string and pastes it into their AI tool's config file. This is the **intended behavior** — Application Passwords are the credential the user grants their AI tool to authenticate as the user.
- **Risk**: The plaintext password sits in the user's AI-tool config file at rest. WP core's password storage is hashed (Constitution §III bullet 7); this snippet is the **issuance side** of the credential, by design plaintext for the one trip from generation → paste.
- **Decision**: This is not a vulnerability — it is the documented Application Password user flow. Constitution §III bullet 7 applies to **server-side storage**, which is hashed by WP core. Snippet output is transit material, not storage.
- **Mitigation in module**: `redact_token()` helper exists for log-safe representation — `safe_token()` (the snippet-side helper) is the only path that emits plaintext. Code review should reject any new log statement that interpolates `$auth_token` directly without calling `redact_token()`.

### F2. **[Advisory]** Consumer must escape snippet at render boundary

- **Where**: Phase 2's `ApplicationPasswords::render_for_server` (separate amendment, not this phase) and any future consumer.
- **What**: Array snippets emitted into HTML MUST be `wp_json_encode()`'d (which produces JSON-encoded, JS-safe output); string snippets MUST be `esc_html()`'d. If the consumer interpolates raw `$snippet` into `<pre><?php echo $snippet ?></pre>`, the auth token (or any user-controlled URL substring) becomes a stored XSS vector via `<script>` injection in the URL.
- **Risk**: Stored XSS via the URL field if the consumer skips escaping.
- **Decision**: Documented in spec.md Security Checklist. The amendment task should explicitly include a grep gate against `echo $snippet` patterns. Memory entry **B8** (`// esc_url'd above` pattern fragility, 2026-06-17) is the closest existing rule.

### F3. **[Informational]** Placeholder string is English-only

- **Where**: `safe_token()` returns the literal `'(paste generated password here)'` regardless of locale.
- **What**: WordPress i18n functions (`__()`) are unavailable in test runs (which deliberately don't bootstrap WP per SC-003). Internationalizing the placeholder would force a WP bootstrap into the test harness, defeating the purity test that proves FR-008.
- **Risk**: Negligible. The placeholder is a developer hint inside a config snippet, not UI text. Non-English admins copy the snippet, see the placeholder, replace it. No user-facing localization regression.
- **Decision**: Acknowledged trade-off. If i18n becomes required, do it at the consumer boundary in Phase 2 amendment via `str_replace()`.

### F4. **[Informational]** `get_all_clients()` instantiates without exception handling

- **Where**: `AbstractMCPClient::get_all_clients()` per research.md R4.
- **What**: A malformed class under `includes/MCPClients/` (extends `AbstractMCPClient` but doesn't implement an abstract method) would trigger a PHP fatal at autoload, crashing the admin page that called `get_all_clients()`.
- **Risk**: Self-inflicted — only triggers when a developer adds a malformed class. Spec Edge Cases explicitly accept this ("PHP raises a fatal at autoload time. This is acceptable — the developer sees the error immediately").
- **Decision**: Accept. Wrapping in try/catch would mask developer errors that should fail fast.

---

## Summary

| Finding | Severity | Action |
|---|---|---|
| F1 — Token plaintext in snippet | Advisory (by design) | Document in `data-model.md` of consumer amendment |
| F2 — Consumer escape required | Advisory **with mandatory grep gate at amendment time** | Phase 2 RT-3 amendment task |
| F3 — Placeholder English-only | Informational | Acknowledge; revisit if i18n required |
| F4 — Malformed class fatal | Informational | Accept per spec Edge Cases |

**No Critical / High / Medium findings. Overall risk: LOW.**

Phase 4 has the smallest security surface of any phase so far (000–004).
The bounded surface is intentional — pure service classes are easy to
reason about and to defend.

**No carry-forward burden from prior phases.** SEC-001 (Claude Connector
Secret plaintext at rest, Phase 2) is unrelated to this module. SEC-002
(`admin_url esc_url`) is N/A (no `admin_url()` calls here). SEC-003
(slug sanitiser) is N/A (no DB writes here).
