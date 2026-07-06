# Memory Synthesis

## Current Scope

Feature 016 retires the Claude Connectors integration entirely: 4 connector-specific classes, 6 shared `includes/OAuth/**` support classes, 2 BerlinDB modules (`OAuthToken` + `OAuthAudit`), 2 DB tables (`wp_acrossai_mcp_oauth_tokens` + `wp_acrossai_mcp_oauth_audit`), 3 MCPServer columns (`claude_connector_*`), 1 daily cron event (`acrossai_mcp_oauth_cleanup`), 3 rewrite rules, 1 REST route (`POST /wp-json/acrossai-mcp/v1/token`), 1 shortcode, 1 CSS bundle (`frontend-oauth.scss`), the `acrossai_mcp_claude_connectors_enabled` option, the per-server Claude Connector tab, and the Settings → MCP toggle. Affected modules: `includes/OAuth/**`, `includes/Database/OAuth**/`, `includes/Database/MCPServer/{Schema,Row,Table,DefaultServerSeeder}.php`, `includes/{Main,Activator,Deactivator}.php`, `admin/Partials/{Settings,SettingsMenu,ServerTabs/Registry}.php`, `public/{Main,Renderers/*}.php`, `includes/REST/ClientRendererController.php`, `src/scss/`, `webpack.config.js`, `uninstall.php`, `tests/phpunit/OAuth/`. Untouched: CLI auth stack (`FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, `includes/Database/CliAuthLog/`), `NpmClientBlock`, `MCPClientsBlock`, `AbstractClientRenderer`.

## Relevant Decisions

- **DEC-UNINSTALL-OPT-IN-GATE (F012, Active)** — `uninstall.php` MUST short-circuit at top when `acrossai_mcp_uninstall_delete_data !== 1`; every destructive statement (including our OAuth `DROP TABLE`s and option deletes) MUST live AFTER the gate. *(Source: INDEX.md — highest-impact; FR-011 must preserve gate placement.)*
- **DEC-BERLINDB-TABLE-REQUEST-BOOT (F011, Active)** — BerlinDB Table subclasses require request-time `Table::instance()` in `Main::bootstrap_database_tables()`. Feature 016 REMOVES the `OAuthToken\Table::instance()` and `OAuthAudit\Table::instance()` calls; the decision remains active for the surviving `MCPServer` + `CliAuthLog` tables. *(Reason: DIRECT — governs one of the exact edits.)*
- **DEC-CLIENT-RENDERER-PUBLIC-API (F013, Active)** — 4 sanctioned entry points + `acrossai_mcp_render_client_block` action map + 3 shortcodes. Feature 016 shrinks the map to 2 entries and 2 shortcodes; the decision body needs annotation (not supersession) post-implementation. *(Reason: DIRECT — governs the `ClientRendererController` edits.)*
- **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG (F012, Active)** — canonical A9 subtractive-edit precedent: admin surface pruning MUST land in the same PR. Feature 016 follows the same shape (tab + shortcode + settings section + REST route all removed together, no half-migration). *(Reason: DIRECT PATTERN MATCH.)*
- **D6 (Active)** — `Activator.php` MUST use `use` imports for DB/OAuth class references; bare relative names silently fail (see B1). Feature 016 must fully purge stale `use OAuth\ClaudeConnectors;`, `use ...\OAuthToken\Table;`, `use ...\OAuthAudit\Table;` when removing their consumers. *(Reason: GATE — hostile grep-audit path.)*

## Active Architecture Constraints

- **A1** — All hook registration exclusively in `includes/Main.php`. Feature 016 EDITS this file to remove the ClaudeConnectors + TokenController + BearerAuth blocks; no hooks leak into class ctors. *(Source: ARCHITECTURE.md.)*
- **A2** — Singleton `instance()` pattern. All classes being deleted follow it; nothing new introduced. *(Reason: sanity guard for any optional `ConnectorColumnMigration` helper.)*
- **A6** — `Includes` classes MUST use `use` or leading-`\` FQN. Reinforces D6 during edits to `Activator.php`, `Main.php`, `ClientRendererController.php`. *(Source: ARCHITECTURE.md.)*
- **A13** — RFC-prescribed OAuth consent form exempted from A4 DataForm mandate. Feature 016 REMOVES the sole consumer; the constraint stays active for future RFC forms but has zero consumers post-016. *(Reason: annotation candidate.)*

## Accepted Deviations

- **DEV1** — MCP Manager parent-menu `WP_List_Table` exception. Preserved by Feature 016 (server list is untouched).
- **DEV3** — Bidirectional `FrontendAuth ↔ CliController` import. Untouched (CLI stack out of scope per FR-013).

## Relevant Security Constraints

- **S2** — REST routes MUST have explicit `permission_callback`. Feature 016 removes the route governed by S7's exception; S8 (CLI auth device-code) remains the only body-authenticated exception left.
- **S7** — OAuth token endpoint `__return_true` exception ("exactly one match permitted across `includes/OAuth/`"). Post-016 the match count is ZERO; `PROJECT_CONTEXT.md` must be updated to reflect the exception being retired.
- **S9** — Consent-surface displayed-state MUST come from server-side authoritative store. Applied to the retired Connectors consent surface; post-016 no consumer remains (annotation candidate; the pattern still guards CLI auth surfaces).

## Related Historical Lessons

- **B1 (Namespace relative-path bug in `Includes`)** — When we delete `use ClaudeConnectors;` etc., a lingering bare `ClaudeConnectors::instance()` becomes `AcrossAI_MCP_Manager\Includes\Includes\OAuth\ClaudeConnectors` and silently returns false. FR-015's grep audit is the safety net; run it after EVERY task.
- **B15 (Regex verification gates miss FQN forms)** — Our grep audit MUST match BOTH the bare `use OAuth\Storage;` form AND the leading-`\` `\AcrossAI_MCP_Manager\Includes\OAuth\Storage` form. FR-015's regex uses ERE `\\?` — verify it matches both by seeding a fake FQN reference before running.
- **F011 WORKLOG (BerlinDB phantom-version guard)** — MCPServer's `Table::maybe_upgrade()` override (`if (! $this->exists()) delete_option($this->db_version_key); parent::maybe_upgrade();`) must be PRESERVED when we bump `$version` from `0.0.1` → `0.0.2`. Accidentally dropping the override during the version bump reopens the F011 activation bug.

## Conflict Warnings

- **SOFT — S7 exception count invariant**: `PROJECT_CONTEXT.md` states "exactly one match permitted across `includes/OAuth/`". After Feature 016 the match count is zero. Update the constraint body (not the row) post-implementation via `/speckit-memory-md-capture-from-diff`; annotate as "no consumers post-F016".
- **SOFT — DEC-CLIENT-RENDERER-PUBLIC-API surface shrink**: decision names 3 shortcodes; Feature 016 leaves 2. Annotate row body with a "post-F016: 2 shortcodes, 2 map entries" note (do NOT supersede — the public API contract itself is unchanged).
- **SOFT — A13 orphaning**: architecture constraint stays valid for future RFC forms but has zero consumers after F016. Annotate row with "no active consumers as of F016" so the next reader knows why the exemption exists in the abstract.
- **NO HARD CONFLICTS** — spec is compatible with every still-valid decision; nothing blocks planning.

## Retrieval Notes

- Index entries considered: 20 (full Active Decisions table + all 15 Architecture Constraints + 19 Bug Patterns + Accepted Deviations + Worklog).
- Source sections read: INDEX.md in full (compact routing map, ~112 lines).
- Skipped (per budget + `full_memory_read_allowed: false`): full body of `DECISIONS.md`, `ARCHITECTURE.md`, `BUGS.md`, `PROJECT_CONTEXT.md`, `WORKLOG.md`. Index entries provide sufficient context.
- Budget status: 5 decisions / 4 architecture constraints / 3 security constraints / 3 bug patterns / 2 accepted deviations / 3 historical lessons — WITHIN limits. Synthesis ≈ 810 words, WITHIN `max_synthesis_words: 900`.
