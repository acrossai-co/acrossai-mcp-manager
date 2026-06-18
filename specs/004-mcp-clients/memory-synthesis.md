# Memory Synthesis

## Current Scope

Phase 4 — **MCP Client Classes (pure service layer)**. Build 8 PHP files under
`includes/MCPClients/`: 1 abstract base (`AbstractMCPClient`) + 7 concrete
clients (ClaudeCode, ClaudeDesktop, Codex, Cursor, Custom, GitHubCopilot,
VSCode). Each concrete class implements three methods:
`get_client_slug() / get_client_name() / get_config_snippet($url, $token)`.
The abstract base provides three protected helpers: `build_server_url()`,
`derive_server_key()` (Q1 2026-06-17), `redact_token()`.

Pure service layer — no WordPress hooks, no DB, no HTTP, no global state.
Consumed at use-site by Phase 2's `Admin\Partials\ApplicationPasswords::
render_for_server()` in a separate amendment (RT-3 follow-up — not this
phase's scope). Affected modules: `includes/MCPClients/*` (new), with a
documented downstream dependency on Phase 2's Tokens-tab renderer.

## Relevant Decisions

- **D5** — PHPCS baseline exceptions in `phpcs.xml.dist` (filename casing,
  `$_instance` prefix, file docblocks). (Reason Included: every new
  partial historically hits these; same will apply here. Source:
  DECISIONS.md.) Status: Active.
- **D10** — Minimal-port deferral pattern. (Reason Included: this phase
  IS the deferred port — D10 captured the agreement to ship Phase 2
  without MCPClients and add them later in a dedicated phase. Phase 4
  closes that loop. Source: DECISIONS.md.) Status: Active.

## Active Architecture Constraints

- **A1** — All hook registration in `includes/Main.php` via Loader.
  (Reason: FR-008 enforces zero `add_action`/`add_filter` in
  MCPClients; pure service classes have no hooks to wire. Source:
  ARCHITECTURE.md.) Status: Active — fully honored.
- **A6** — Inside `AcrossAI_MCP_Manager\Includes\*` files, sub-namespace
  refs MUST use `use` imports or leading-`\` FQN (bare relative names
  silently fail). (Reason: MCPClients live in `Includes\MCPClients`;
  abstract base + concrete subclasses reference each other; consumer in
  Phase 2's `Admin\Partials\` will reference MCPClient classes via FQN.
  Source: ARCHITECTURE.md.) Status: Active.
- **A2** — All feature classes use singleton pattern. **Soft conflict
  with FR-009** — see Conflict Warnings.
- **A10** — `WP_List_Table` subclasses exempted from singleton rule.
  (Reason: documents the **precedent** that not every class in the
  codebase is a singleton; FR-009 / Story 4 invoke the same precedent
  for pure service classes. Source: ARCHITECTURE.md, 2026-06-17.)
  Status: Active.

## Accepted Deviations

None directly applicable. (DEV1 covers WP_List_Table; DEV2 covers
Compat.php — neither apply to MCPClients.)

## Relevant Security Constraints

- **S3** — OAuth tokens and Application Passwords stored hashed.
  (Reason: spec FR-006 explicitly **does not store** tokens — it embeds
  the caller-provided plaintext token directly into the snippet output
  the user copy-pastes. The token is a transit credential, not a stored
  credential. This is the intended use of an Application Password;
  hashed-storage rule applies to the WP_Application_Passwords API
  side (Phase 2), not the snippet builder. Source: CONSTITUTION.md §III
  bullet 7.) Status: Honored at the right boundary.

## Related Historical Lessons

- **B1** — Bare relative sub-namespace refs inside `Includes\*` files
  silently resolve to wrong FQNs. (Reason: AbstractMCPClient + 7 concrete
  subclasses + a Phase 2 consumer will all reference each other through
  the namespace; safest pattern is `use` imports at file top with
  unaliased class names. Source: BUGS.md.)
- **B8** — `// esc_url'd above` comment pattern is fragile.
  (Reason: this feature ships no HTML, but its consumer in Phase 2 will
  render the snippet — the consumer's render code MUST `esc_html()`
  string snippets at output and `wp_json_encode()` array snippets at
  serialisation. Captured in spec security checklist; not in this
  phase's scope. Source: BUGS.md, 2026-06-17.)

## Conflict Warnings

- **Soft conflict — A2 vs FR-009 (singleton exemption for pure service
  classes)**: Constitution A2 mandates singleton pattern for every
  feature class. Spec FR-009 forbids singleton on MCPClients because
  the classes hold no instance state and are stateless value
  producers — holding a long-lived instance would serve no purpose.
  This is the same logical exemption A10 documents for `WP_List_Table`
  subclasses (different rationale: WP-core ctor signature; same
  outcome: not a singleton). **Recommendation**: planning may proceed
  under FR-009 as written. If `/speckit-architecture-guard-violation-detection`
  challenges this later, propose adding **A11 — pure service classes
  in `includes/MCPClients/` and equivalent stateless-value-producer
  directories are exempted from the singleton rule** as a follow-up
  capture (parallel to A10). The spec already documents the exemption
  in FR-009; A11 would just elevate it from spec-level to durable
  memory.

No hard conflicts. No constitution MUST is violated.

## Retrieval Notes

- Index entries considered: 14 (D5, D10, A1, A2, A6, A7, A10, B1, B5,
  B7, B8, S1, S3, S6) — within 20-entry budget.
- Source sections read: INDEX.md only (per markdown-only, budget-
  conscious flow). Full memory files NOT opened — index entries are
  self-describing for this phase's narrow scope.
- Budget status: 14/20 entries · 2/5 decisions · 4/5 architecture ·
  0/3 deviations · 1/3 security · 2/3 bugs · 0/2 worklog (worklog
  skipped — Phase 4 has no recent activity to draw from beyond Phase
  2's RT-3 deferral note, which is already in D10).
- Synthesis word count: ~620 of 900-word cap.
