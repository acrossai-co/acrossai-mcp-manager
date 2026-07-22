# Memory Synthesis

## Current Scope

F032 — OAuth Per-Server Scoping. Adds `server_id BIGINT UNSIGNED NOT NULL` to three BerlinDB tables (`wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_auth_codes`) via D28 3-part contract applied three times; changes standalone `UNIQUE(client_id)` → composite `UNIQUE(client_id, server_id)`; propagates `server_id` through OAuth authorize→token→refresh flow; requires + validates `server_id` on every mutating REST endpoint in `ConnectorAdminController` (returns 403 `acrossai_mcp_oauth_cross_server` on mismatch + fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', ... )` per D19); adds `server_id` capture on the DCR endpoint via RFC 8707 `resource` param resolution; auto-purges legacy pre-F032 DCR rows during upgrade + fires `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', ... )`. Affected modules: `includes/Database/OAuth{Clients,Tokens,AuthCodes}/*`, `includes/OAuth/{AuthorizationController,TokenController,ClientRegistrationController,ConnectorAdminController,Repositories/*}`, `admin/Partials/ServerTabs/AIConnectorsTab.php`, F024 nested-tabs JS bundle.

## Relevant Decisions

- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** (Reason: F032 is the canonical 3rd application of this contract — every OAuth table needs bumped `$version` + registered `$upgrades` callback + idempotent `INFORMATION_SCHEMA` gate. Status: Active. Source: DECISIONS.md)
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason: BerlinDB Table subclasses MUST be re-instantiated at request time via `Main::load_hooks()` — activation-only `Table::instance()` leaves DB interface empty. Status: Active F011. Source: DECISIONS.md)
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** (Reason: BerlinDB parent-name collisions in `use` imports produce "Cannot redeclare class" fatals; F032's three new upgrade callbacks live in existing subclasses so this may not bite, but code review MUST verify. Status: Active F011. Source: DECISIONS.md)
- **D27 / DEC-CONFIDENTIAL-CLIENT-SOFT-AUTH-VIA-PKCE** (Reason: F032's TokenController changes must preserve F029's PKCE-only soft-auth fallback path for both `authorization_code` and `refresh_token` grants — don't regress. Status: Active F029. Source: DECISIONS.md)
- **D19 — Fail-open observability pattern** (Reason: F032's two new signals — `acrossai_mcp_oauth_cross_server_attempted` on every 403 and `acrossai_mcp_oauth_legacy_dcr_purged` once per upgrade — MUST follow this fire-and-forget shape with no hard listener dependency. Status: Active F015. Source: DECISIONS.md)

## Active Architecture Constraints

- **A1 — Hook registration exclusively in `Main.php`** (Reason: F032 wires new observability actions + upgrade callbacks; every hook addition MUST land in `define_admin_hooks()` / `define_public_hooks()`. Source: ARCHITECTURE.md)
- **A2 — Singleton `instance()` pattern** (Reason: All F032-touched classes are existing singletons; new helper class `find_by_client_id_and_server_id` lives inside `ClientsQuery` singleton. Source: ARCHITECTURE.md)
- **A6 — Namespace: `use` imports or FQN with leading `\`** (Reason: F032's cross-namespace refs to `OAuthTokens\Table` from `OAuthClients\Table` (for `get_last_purge_count()` aggregate signal) MUST use one or the other; bare relative paths silently fail (B1). Source: ARCHITECTURE.md)
- **A3 — Admin UI classes in `admin/Partials/`** (Reason: `AIConnectorsTab` changes stay in `admin/Partials/ServerTabs/`. Source: ARCHITECTURE.md)
- **A17 — Request-scoped WP REST context capture pattern** (Reason: `CurrentServerHolder` from F030 already implements this; F032's REST validation may reference it for cross-checking request-time server context vs body `server_id`. Source: ARCHITECTURE.md)

## Accepted Deviations

- **DEV5 — Per-server-edit tab hand-rolled admin form** (Reason: F032's AIConnectorsTab UI changes are minimal — add `data-acrossai-server-id` attributes + swap the authorized-users listing call; no new form fields. Status: Accepted-Deviation. Source: CONSTITUTION.md via D13 escalation)

## Relevant Security Constraints

- **S2 — All REST routes MUST have explicit `permission_callback`** (Reason: F032 modifies 3 admin endpoints — all retain `manage_options`. DCR endpoint retains S8 body-auth exception. Source: security-constraints.md)
- **S4 — All DB queries MUST use `$wpdb->prepare()`** (Reason: F032's new upgrade callbacks use `$wpdb->query()` for ALTER/UPDATE/DELETE with hardcoded literals + prepared `INFORMATION_SCHEMA` gates. Source: security-constraints.md)
- **S1 — Nonce verification on forms/AJAX** (Reason: F024 nested-tabs JS uses existing `acrossai_mcp_manager_connector` nonce; unchanged. Source: security-constraints.md)

## Related Historical Lessons

- **B34 — Silent write-loss on schema drift** (Reason: F032 IS the schema migration that avoids this exact failure mode by applying D28 to all three OAuth tables. Grep gate on PRs that change any OAuth Schema.php WITHOUT touching the paired Table.php is a hard MUST.)
- **B26 — Governance grep gates that hard-code a directory allow-list silently skip newly-added layers** (Reason: F032's Final full-repo audit MUST enumerate `includes/ admin/ public/ tests/` and not just `includes/OAuth/` — otherwise stale signatures elsewhere silently pass. F032's audit grep already includes these paths.)
- **B15 — Regex verification gates matching only bare-name form silently pass FQN + aliased forms** (Reason: F032's grep audits use ERE alternation and MUST include leading-`\` FQN variants for method-signature scans on `revoke_by_client_id`, `get_active_user_ids_by_client_id`, `find_by_client_id`.)

## Conflict Warnings

None. F032's 4 clarify-phase decisions (auto-purge, NOT NULL, observability signals, no feature flag) introduce NEW patterns that neither reverse nor supersede any active durable memory entry. All prior "legacy DCR preservation" language existed only in the planning doc, which has been reconciled. Two new decisions expected post-implement per capture-from-diff (DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS + B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY).

## Retrieval Notes

- Config: `.specify/extensions/memory-md/config.yml` — optimizer disabled → markdown-only flow.
- Index entries considered: 40+ (all D*, DEC-*, A*, S*, B*, DEV* rows scanned); selected 5 decisions + 5 architecture + 1 deviation + 3 security + 3 bugs + 2 worklog per budget.
- Source sections read: `docs/memory/INDEX.md` in full (171 lines across 2 pages); NO source files (DECISIONS.md, ARCHITECTURE.md, BUGS.md, WORKLOG.md) opened — INDEX rows carry sufficient context per policy (`full_memory_read_allowed: false`).
- Worklog most relevant: F029 (2026-07-18 — codified D28) + F030 (2026-07-20 — 2nd D28 application on `MCPServer\Table::upgrade_to_1_1_2`); both are direct reference implementations for F032's three new upgrade callbacks.
- Budget: 5/5 decisions, 5/5 architecture, 1/3 deviations, 3/3 security, 3/3 bugs, 2/2 worklog, ~870/900 words.
