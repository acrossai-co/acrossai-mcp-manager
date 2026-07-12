# Memory Synthesis

## Current Scope

Feature 021 adds a provider-agnostic **OAuth 2.1 + PKCE authorization server** to the plugin: three new BerlinDB tables (`OAuthClients`, `OAuthTokens`, `OAuthAuthCodes`), four domain-root endpoints (`/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`, `/authorize`, `/token`) via `add_rewrite_rule` + `parse_request`, one REST-namespaced DCR endpoint + one admin-only credential-generator endpoint, a `TokenValidator` bridging `determine_current_user` filter at priority 20 with RFC 8707 audience-binding enforcement (per §Clarifications Q1), a `ConnectorProfileRegistry` (public filter `acrossai_mcp_manager_connector_profiles`), a new built-in per-server tab `AIConnectorsTab` at priority 35 (built-in per DEC-OAUTH-BUILTIN-TAB-NOT-FILTER planned), and this plugin's first cron job (`acrossai_mcp_manager_oauth_cleanup`). Affected modules: new `includes/OAuth/`, `includes/Connectors/`, `includes/Database/OAuth*` (3 modules), `admin/Partials/ServerTabs/AIConnectorsTab.php`, `templates/oauth/consent.php`, plus small deltas to `Main.php`, `Activator.php`, `Deactivator.php`, `uninstall.php`. Zero modifications to `vendor/wordpress/mcp-adapter/`, `includes/AccessControl/`, `includes/REST/CliController.php`.

## Relevant Decisions

- **DEC-BERLINDB-TABLE-REQUEST-BOOT** (Reason: three new Table subclasses added; missing request-time boot produces the "Table doesn't exist" fallback bug F011 fixed. Status: Active F011. Source: DECISIONS.md:494.)
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** (Reason: all 12 new class files (3 modules × 4 files) share local names with `\BerlinDB\Database\Kern\*` parents — a `use` statement would fatal. Status: Active F011. Source: DECISIONS.md:531.)
- **DEC-UNINSTALL-OPT-IN-GATE** (Reason: FR-041 puts destructive teardown behind the existing `acrossai_mcp_uninstall_delete_data` flag. Status: Active F012. Source: DECISIONS.md:604.)
- **DEC-SERVER-TAB-CLASS-HIERARCHY** (Reason: `AIConnectorsTab` extends `AbstractServerTab`; F019 Registry filter contract untouched — new tab is a built-in, inserted directly in `Registry::all_tabs()`. Status: Active F013. Source: DECISIONS.md:678.)
- **DEC-F020-TOOL-ENFORCEMENT-PRIORITY** (Reason: F021 does NOT wire `mcp_adapter_pre_tool_call` — it uses `determine_current_user` @ 20. F020's slot map (10/20/30) stays intact. Status: Active F020. Companion note.)

## Active Architecture Constraints

- **A1 — All hook registration in `Main.php`** (Reason: rewrite rules on `init`, filter on `determine_current_user @ 20`, cron on daily hook, REST routes on `rest_api_init`, `deleted_user @ 10` cascade — every one wired via Loader in `define_admin_hooks()` / `define_public_hooks()`; zero constructor hooks. Source: ARCHITECTURE.md.)
- **A2 — Singleton `instance()` pattern** (Reason: 13 new OAuth classes + 2 Connectors classes + `AIConnectorsTab` all singletons with private constructors. Source: ARCHITECTURE.md.)
- **A6 — `use` imports or leading-`\` FQN inside `Includes\` namespace** (Reason: cross-namespace refs from `Main.php` to OAuth controllers, from OAuth controllers to Database modules, etc. — B1 double-Includes bug applies. Source: ARCHITECTURE.md.)
- **A9 — Shared constants in `includes/Utilities/`** (Reason: monitor for shared strings across DCR + admin generator + AIConnectorsTab (e.g., `token_endpoint_auth_method` values, `grant_types_supported` list) — extract if any surfaces as 2+ callers.)
- **A4 — DataForm/DataViews mandate** (⚠ **soft conflict** — see below.)

## Accepted Deviations

- None from prior features apply directly. F021 may introduce one for the consent template if `A13` RFC-prescribed-form exemption is invoked — plan phase decides.

## Relevant Security Constraints

- **S3 — OAuth tokens + App Passwords MUST be SHA-256 hashed** (Reason: FR-039 + FR-040 mandate hash-at-rest for tokens, secrets, codes. Source: CONSTITUTION.md §III.)
- **S7 — OAuth token endpoint `__return_true` allowed per RFC 6749 §2.3.1** (Reason: F021 REVIVES this exception post-F016 retirement. `/token` uses body-authenticated client_secret + PKCE, so `__return_true` on `permission_callback` is permitted with justification. Update S7 row post-implement to note new active consumer.)
- **S9 — Consent-surface displayed-state MUST be sourced from server-side authoritative store** (Reason: FR-007 + FR-010 already require re-validating every authorize param on POST from `OAuthClients` row + connector profile whitelist, not from the hidden inputs in the consent form's URL params. Prevents confused-deputy / UI-misrepresentation. Source: PROJECT_CONTEXT.md.)

## Related Historical Lessons

- **B10 — Check-then-act on one-shot credentials → atomic CAS** (Reason: FR-014 auth code single-use enforcement uses `UPDATE ... WHERE used=0` — identical shape to CliAuthLog::redeem_atomic. `AuthCodesQuery::consume_atomic` MUST match this exact pattern with `1 === (int) $wpdb->rows_affected` semantics.)
- **B20 — Plaintext OAuth secret in `varchar(255)` is S3 violation** (Reason: FR-040 pre-empts this by mandating `char(64)` for `client_secret_hash`, `token_hash`, `code_hash`. F016 retroactive-fix pattern applies if any column is ever added narrower.)
- **B21 — BerlinDB v3 uses `modified` NOT `date_updated`** (Reason: no `updated_at` columns proposed on F021 tables, but if any surfaces during plan phase, apply the flag correctly.)
- **F017 worklog — storage-vs-enforcement decoupling** (Reason: Q1 clarification (audience enforcement in `TokenValidator`) already codified this in FR-024. Same failure mode F017's SEC-001 caught mid-flow; F021 pre-empts by baking audience-binding into the spec.)
- **F020 worklog — defer UX-facing ADR capture until post-security-staged review** (Reason: don't capture `DEC-OAUTH-*` entries during `/speckit-plan-with-memory`; wait until `/speckit-memory-md-capture-from-diff` post-commit so wording stabilizes.)

## Conflict Warnings

- **SOFT — A4/Principle IV (DataForm/DataViews for admin UI)**: The `AIConnectorsTab` renders per-profile cards with Generate/Regenerate buttons — not a data-entry form and not a tabular listing in the traditional sense. Same class of deviation as F020's shuttle picker (documented in F020 `plan.md §Complexity Tracking`). Two paths in plan phase: (a) hand-rolled card layout matching F020's precedent, log new deviation with `alternatives-considered` justification; (b) use DataViews list with row actions. Recommend (a) for consistency + simplicity. **Blocking**: no — deviation model is well-established.
- **SOFT — `templates/oauth/consent.php` introduces a new top-level `templates/` directory** which no prior feature uses. Not a conflict with any active decision; ARCHITECTURE.md's directory layout doesn't enumerate `templates/`. Plan phase decides whether to formalize via a new architecture-constraint note.
- **SOFT — S7 exception reactivation**: F016 marked S7's `__return_true` exception as "no active consumers." F021's `/token` endpoint revives it. Post-implement: update S7's INDEX row to remove the "no active consumers" annotation. Non-blocking.
- **NO HARD CONFLICTS.** Spec fully aligns with F011/F012/F013/F017/F019/F020 conventions.

## Retrieval Notes

- Index entries considered: 20 (5 decisions + 5 architecture + 3 security + 3 bug patterns + 2 worklog + 2 accepted deviation checks).
- Source sections read: DECISIONS.md 494-535 (F011 pair), 604-640 (F012 uninstall gate), 678 (F013 tab hierarchy). Confirmed via targeted grep.
- Full memory read: **not** performed (config allows: false).
- Budget status: 5/5 decisions, 5/5 constraints, 3/3 security, 3/3 bug patterns, 2/2 worklog, ~870 words — within all limits.
- Optimizer: disabled (`optimizer.enabled: false`); markdown-only retrieval used.
