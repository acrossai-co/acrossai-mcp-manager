# Worklog

Use concise high-value entries only.
This is not a changelog. Do not record routine releases, version bumps, or implementation summaries.

## Template

### YYYY-MM-DD - Summary

- why this is durable
- what future mistake it prevents
- evidence
- where future contributors should look

## Example

### 2026-03-15 - Pagination cursor must be opaque to clients

- **Why durable**: three features so far have tried to expose raw database offsets as pagination cursors, each time creating breaking changes when the underlying query changes
- **Future mistake prevented**: next time a feature adds pagination, the implementer will know to use opaque cursors from the start
- **Evidence**: specs 018, 024, and 031 all required pagination rework; see DECISIONS.md entry on API pagination
- **Where to look**: `src/api/pagination.ts`, `docs/memory/DECISIONS.md`

## Counter-Example (do not write entries like this)

> ### 2026-03-15 - Updated pagination
>
> - Changed pagination to use cursors
> - Deployed to staging

This is a changelog entry, not a durable lesson. It records what happened, not what was learned.

### 2026-07-02 - BerlinDB Table subclasses must override maybe_upgrade() with a phantom-version guard

- **Why durable**: The phantom-version guard on every BerlinDB-backed Table subclass is a canonical safety belt against the "version option stamped but physical table missing" edge case. Costs one method override; prevents an entire class of hard-to-diagnose "table doesn't exist" activation bugs.
- **Future mistake prevented**: A future BerlinDB-backed table shipped without the guard could silently short-circuit `maybe_upgrade()` on any install where a prior activation failed mid-DDL. The bug is invisible until users complain about missing rows/features.
- **Evidence**: The observed `wp_acrossai_mcp_servers doesn't exist` symptom that originally motivated Feature 011 on the developer's local install (2026-07-02).
- **Where to look**: The four subclass file paths — `includes/Database/{MCPServer,CliAuthLog,OAuthToken,OAuthAudit}/Table.php` — each contains a `public function maybe_upgrade(): void` override. Canonical sibling reference at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/Database/AcrossAI_Abilities_Table.php:96-101`.
