# Tasks — F073 Wizard Client Snippet Parity

**Feature**: [spec.md](./spec.md) · [plan.md](./plan.md)
**Branch**: `072-quick-setup-entry-points` (see plan.md § Branch / commit hygiene)
**Total**: 6 tasks (4 code + 1 verification + 1 commit)

## Phase 1 — Code changes (T002 depends on T001 semantically; T003 + T004 parallel-safe)

- [x] **T001** Edit `public/Discovery/ConnectionMethodRegistry.php::get_clients()`.
  - Change signature `(): array` → `(?array $server = null): array`.
  - Update docblock to describe the new param + F073 semantic contract.
  - Body: resolve `$server_url` via `rest_url( trailingslashit( $server['server_route_namespace'] ) . $server['server_route'] )` when `$server` is provided.
  - Add `'instructions' => $client->get_instructions()` to every DTO.
  - When `$server_url !== null`, add `'config' => wp_json_encode( $client->get_config_snippet( $server_url, '' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )` (or raw string).
  - See plan.md → *Per-file change map → `ConnectionMethodRegistry.php`* for the exact diff.

- [x] **T002** Edit `includes/REST/QuickSetupController.php::handle_state()`.
  - Immediately after `$methods = ConnectionMethodRegistry::instance()->get_all();`, add a `$server_id > 0` guard.
  - Inside the guard, run `MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )`.
  - On non-empty result, override `$methods['clients'] = ConnectionMethodRegistry::instance()->get_clients( $rows[0]->to_array() );`.
  - Preserve the existing `rest_ensure_response()` payload shape (no other keys change).

- [x] **T003** [P] Edit `src/js/quick-setup/steps/Step11_ClientDetail.jsx`.
  - Immediately below the existing App Password `<Notice>`, add:
    1. `qs-meta-row` for Config File (from `activeClient.meta.config_file`, gated by `activeClient?.meta?.config_file`).
    2. `qs-meta-row` for Top-Level Key (from `activeClient.meta.top_level_key`, wrapped in `"…"`, gated by `activeClient?.meta?.top_level_key`).
  - Immediately below the existing `<CodeBlock>`, add:
    3. `<Notice status="info">` with two `<p>` — per-client `activeClient.instructions` + shared Access Control paragraph. Gate the entire callout on `activeClient?.instructions` (mirrors PHP tab's `if ( '' !== $instructions )` at `MCPClientsBlock.php:252`).
  - Keep the shared Access Control string byte-identical to `MCPClientsBlock.php:256` so translators only key it once.

- [x] **T004** [P] Edit `src/scss/quick-setup.scss`.
  - Add three utility classes `.qs-meta-row`, `.qs-meta-label`, `.qs-meta-value` per plan.md → *Per-file change map → SCSS*.
  - Fixed 120px label width so `Config File` and `Top-Level Key` values align between rows.
  - Zero changes to existing classes.

## Phase 2 — Verification (sequential, after Phase 1)

- [x] **T005** Run PHPCS on the two touched PHP files. Must not introduce new violations beyond the pre-existing baseline (3 `error_log()` warnings in `QuickSetupController.php`).
  ```
  ./vendor/bin/phpcs public/Discovery/ConnectionMethodRegistry.php \
                     includes/REST/QuickSetupController.php
  ```

- [ ] **T005b** Manual byte-identity check (needs `npm run build`).
  1. Pick one server (e.g. id=3) and one client (e.g. `claude-desktop`).
  2. Open `?page=acrossai_mcp_manager&action=edit&server=3&tab=clients&client=claude-desktop`; copy the JSON body from the `<textarea>` (view source or right-click → view frame source).
  3. `curl -H 'X-WP-Nonce: <nonce>' -H 'Cookie: <admin session>' /wp-json/acrossai-mcp-manager/v1/quick-setup/state` and jq out `.methods.clients[] | select(.slug=="claude-desktop") | .config`.
  4. Diff the two — must be byte-identical.

- [ ] **T005c** Manual smoke check across three shape-distinct clients (needs `npm run build`).
  1. **Claude Desktop** — standard `mcpServers` top-level key + `command`/`args`/`env` entry. Confirms the baseline shape.
  2. **VS Code** — same top-level key with global-path conventions. Confirms metadata rows render.
  3. **OpenCode** — non-standard `mcp` top-level key + `type: 'local'` + `command` as array + `environment` (not `env`). Confirms the wizard doesn't hardcode the standard shape.
  For each, open the Clients tab and the wizard Step 11 side-by-side. Confirm: pill row shows all 16 clients; JSON pane content matches character-for-character; Config File + Top-Level Key rows match; Instructions callout matches (both paragraphs).

## Phase 3 — Commit (hold on user signal)

- [ ] **T006** `git commit` per plan.md § Branch / commit hygiene. Suggested split (three commits on the current branch):
  1. F072 code + F072 spec-kit artifacts.
  2. F072 follow-up UI tweaks (Step 4 ability card, banner simplification).
  3. F073 code + F073 spec-kit artifacts + `PAID` → `RECOMMENDED`.
  **Do NOT open a PR.** The user asked to hold off — the branch will sit locally awaiting their signal.

## Dependency Diagram

```
T001 → T002 ─┐
T003 ────────┼→ T005 (PHPCS) → T005b (byte-identity) → T005c (smoke) → T006 (commit)
T004 ────────┘
```

T001 done before T002 (T002 calls the new signature). T003 and T004 are file-independent and can land in parallel. T005 gates Phase 2/3; T005b and T005c require a JS/CSS build (`npm run build`) so they run after Phase 1 code + T005 PHPCS.
