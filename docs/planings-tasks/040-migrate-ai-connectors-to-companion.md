# Planning: Migrate AI Connectors + OAuth Stack to Companion Plugin (Feature 040)

Remove the AI Connectors + OAuth 2.1 stack from `acrossai-mcp-manager` after `acrossai-ai-connectors` v0.5.0 has taken ownership. mcp-manager stops shipping the OAuth server (`/authorize`, `/token`, DCR `/oauth/register`, `.well-known/*`, PKCE, consent template, `TokenValidator`, `Cleanup` cron), the AI Connectors admin tab, its JS/CSS bundle, the connector-profile registry, the connector-settings storage class, and the 4 BerlinDB modules for the OAuth tables. The companion re-registers everything under the same REST namespace and cron hook name, so existing bearer tokens continue to authenticate without re-authorization.

**Pure code-removal migration.** Zero new files. Zero new hooks. Zero new UI. Zero data migration. All four OAuth tables, the `acrossai_mcp_connector_%` options, and the cron event are preserved byte-identically under companion ownership. The atomic ownership handoff happens when this feature deletes `includes/OAuth/AuthorizationController.php` — the companion's `class_exists()` self-disable probe flips and it takes over on the next request.

## Authoritative sources

- Spec: [`specs/040-migrate-ai-connectors-to-companion/spec.md`](../../specs/040-migrate-ai-connectors-to-companion/spec.md)
- Plan: [`specs/040-migrate-ai-connectors-to-companion/plan.md`](../../specs/040-migrate-ai-connectors-to-companion/plan.md)
- Tasks: [`specs/040-migrate-ai-connectors-to-companion/tasks.md`](../../specs/040-migrate-ai-connectors-to-companion/tasks.md)
- Research: [`specs/040-migrate-ai-connectors-to-companion/research.md`](../../specs/040-migrate-ai-connectors-to-companion/research.md) — captures 6 clarifications + 2 companion audits (44/44 PASS combined) + pre-flight callers grep result
- Data-model reference: [`specs/040-migrate-ai-connectors-to-companion/data-model.md`](../../specs/040-migrate-ai-connectors-to-companion/data-model.md)
- Removed REST routes catalog: [`specs/040-migrate-ai-connectors-to-companion/contracts/removed-rest-routes.md`](../../specs/040-migrate-ai-connectors-to-companion/contracts/removed-rest-routes.md)
- Verification recipes: [`specs/040-migrate-ai-connectors-to-companion/quickstart.md`](../../specs/040-migrate-ai-connectors-to-companion/quickstart.md)

## Final scope (after all 6 clarifications)

Rescinded during the Clarify session:
- **No `class_alias` compat shim** (Q4 — no third-party ecosystem to preserve BC for).
- **No `Requires Plugins: acrossai-ai-connectors` header** (Q5 — the add-on is a premium upsell, not a hard dependency; free users on npm/clients tabs must stay standalone-activatable).
- **No admin-notice safety net** (Q6 — plugin has a single known operator; defense-in-depth against a nonexistent user population is dead code).

Retained:
- All source-file deletions (OAuth stack, Connectors framework, 4 BerlinDB modules, admin tab, JS/CSS, consent template).
- All wiring modifications (Activator, Deactivator [KEEP the cron-clear per FR-004], Main.php, admin/Main.php, Registry.php, uninstall.php, webpack.config.js).
- `public/Discovery/ConnectionMethodRegistry.php` FR-019 modification (swap ConnectorProfileRegistry FQN, guard with `class_exists()` so `get_ai_connectors()` returns `[]` when companion is absent).
- Plugin `Version:` bump `0.1.9` → `0.2.0`.
- Delete abandoned `specs/039-migrate-ai-connectors-to-companion/` working-tree-only directory.

## Durable lesson

**When a subsystem gets its own plugin, prefer code-only migration** (identical table names, identical version keys, byte-identical BerlinDB Table subclass declarations) **over data-migration**. Cross-plugin ownership handoff via `class_exists()` self-disable probe is atomic and requires zero data movement. Also: interrogate assumed hard dependencies during clarification — Q5 caught that adding `Requires Plugins:` to mcp-manager would break every free-tier install, because the OAuth path is one of three parallel connection methods, not the only one.
