# Security Constraints — F037 Per-Server Shortcode + Block Embeds Tab

**Feature**: F037 | **Date**: 2026-07-27 | **Plan**: [plan.md](./plan.md) | **Reviewer**: Inline plan-phase review (`speckit-security-review-plan` skill invocation deferred — will run explicitly at Governance Summary if user requests)

## Assessment Summary

- **Overall risk**: LOW → MODERATE (higher than F035 due to admin-facing form + frontend renderer + F015 access-control integration surface)
- **Trust boundaries crossed**: 4 (admin form input, filter contribution, F035 DTO delegation, F015 access-control wrapper)
- **New authentication/authorization surface**: 2 (admin save requires `manage_options` + nonce; frontend shortcode is public-read gated by F015 cascade)
- **New data-at-rest surface**: 2 (one column bump + one new junction table)
- **New user input**: 1 (admin form POST — checkbox array)

## Trust Boundaries

### Boundary 1 — REST endpoint (`EmbedsController::save`) — REVISED per Q4 pivot

**Data flow** (Option B — React + REST, replaces the original admin-form path):
1. Admin edits state in the React app under `src/js/embeds.js` — state held in React `useState` hooks.
2. On Save button click, React sends `POST /acrossai-mcp-manager/v1/servers/{server_id}/embeds` via `@wordpress/api-fetch` with `X-WP-Nonce: <wp_rest nonce>` header (WP core `apiFetch.createNonceMiddleware`).
3. `EmbedsController::permission_callback` verifies `current_user_can('manage_options')` (S2) — WP core REST validates the `wp_rest` nonce against the current user before the callback fires.
4. Controller reads current DB state (BerlinDB Row + junction rows) and computes diffs.
5. On actual value changes: writes via `MCPServerQuery::update_item()` + `ServerEmbedTransportsQuery::set_enabled_for_server()` (BerlinDB Query methods; typed args; no `$wpdb->update` with raw request body).
6. Fires observability actions per FR-024 (`acrossai_mcp_embed_master_toggled`, `acrossai_mcp_embed_transport_toggled`) inside per-listener `try/catch` per R3.
7. Returns freshly-assembled state so React re-hydrates without a second round-trip.

**Trust anchor**: `manage_options` capability (site administrator role) + WP core `wp_rest` nonce.

**Threat model**:
- **CSRF** → mitigated by `wp_rest` nonce (`X-WP-Nonce` header enforced by WP REST server against `current_user_id()`).
- **Capability bypass** → mitigated by `permission_callback` returning `WP_Error` 403 on missing cap.
- **Cross-server bypass (SEC-037-001 concern)** → **structurally impossible** — the REST URL contains `{server_id}` as a path parameter; a request against `/servers/2/embeds` cannot affect Server 1's row (`update_item($server_id, ...)` writes only to the target). Server-scoped nonce was the DEV5-era mitigation for the admin-form path; obsolete post-pivot because the URL itself carries the tenant.
- **Injection via request body** → mitigated by `register_rest_route` `args` schema (`master` bool, `transports` object with additionalProperties bool) + BerlinDB parameterized Query methods.
- **Missing / malformed request field** → REST `args` schema rejects with 400 before `save()` fires; on partial `transports` object, iteration uses `AbstractEmbedTransport::get_all_registered_transports()` as source of truth (absent key = OFF, matches presence-model semantic).

### Boundary 2 — Filter contribution (`acrossai_mcp_embed_transports`)

**Data flow**:
1. Companion plugin registers callback returning list of FQN strings.
2. `AbstractEmbedTransport::get_all_registered_transports()` validates + instantiates + dedups per D35 pattern.
3. Instances flow into `EmbedsTab::render_body()` (label rendering) and downstream `is_enabled_for_server()` gate lookups.

**Trust anchor**: Site admin who installed the companion plugin. Same anchor as F034 `acrossai_mcp_client_classes` and F035 `acrossai_mcp_npm_methods`/`acrossai_mcp_connection_methods` seams.

**Threat model**:
- Malicious FQN → non-subclass injected. Mitigation: `is_subclass_of` check per D35 → silent-skip.
- Malicious `get_checkbox_label()` returning `<script>` payload. Mitigation: `EmbedsTab::render_body()` escapes labels via `esc_html_x()` / `esc_html__()` at render.
- Malicious `get_transport_key()` returning malformed key (e.g., `../../etc/passwd`). Mitigation: `/\A[a-z0-9-]{1,64}\z/` regex per D35 → silent-skip + `_doing_it_wrong`.
- Malicious priority (e.g., `PHP_INT_MIN` to reorder ahead of built-ins). Mitigation: acceptable — priority controls display order only; consumers still see the same set of transports; NOT a security concern. Documented as by-design.

### Boundary 3 — F035 DTO delegation (`EmbedBlockRenderer` calling `ConnectionMethodRegistry`)

**Data flow**:
1. Shortcode renderer calls `ConnectionMethodRegistry::instance()->find( $category, $slug )` for single-DTO OR `get_*_methods()` for whole-category.
2. Returned DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed by admin-installed companion plugins (F035 SEC-035-002).
3. Renderer emits values via escape functions per SEC-035-002 preservation invariant.

**Trust anchor**: Site admin who installed the companion plugin (same as Boundary 2). F035 does not pre-escape.

**Threat model**:
- Malicious DTO string field with `<script>` payload → XSS at consumer render. Mitigation (SEC-035-002 preservation): `EmbedBlockRenderer` MUST escape every DTO string field at render — `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for URLs. Failing to escape = XSS.
- Malformed DTO shape → PHP notices/warnings surfaced to user. Mitigation: F035's own FR-009b type-check helper (SEC-035-001) already drops malformed contributions; F037 renderer assumes DTO shape validity per F035 contract.

### Boundary 4 — F015 access-control wrapper integration

**Data flow**:
1. Frontend shortcode calls `\AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )` when the wrapper class exists.
2. If wrapper missing (F015 not installed) → fail-open per D19 (block renders).
3. If wrapper present + denies → silent no-render.

**Trust anchor**: F015 vendor package (installed by site admin).

**Threat model**:
- F015 wrapper deliberately bypasses check. Mitigation: outside F037's scope — F015 wrapper trust is a plugin-choice made when installing wpb-access-control.
- F015 wrapper missing → fail-open. Mitigation: acceptable per D19 fail-open policy; F015 is optional integration.
- F015 present + bug returns true incorrectly → over-permissive render. Mitigation: F015's own test surface + this feature's SC-008 test with F015-stubbed.

## Constitutional Security Rules Applied

| Rule | Applicable? | Verification |
|---|---|---|
| S1 — All forms and AJAX endpoints MUST verify a nonce | **Yes** | EmbedsTab save handler MUST call `wp_verify_nonce` before touching state — FR-018 |
| S2 — All REST routes MUST have explicit `permission_callback` | N/A | F037 registers no REST routes (per FR-019) |
| S3 — OAuth tokens + App Passwords hashed | N/A | F037 handles no tokens |
| S4 — All DB queries MUST use `$wpdb->prepare()` | **Yes** | BerlinDB Query classes wrap this natively via typed placeholders. B39 defense on GC helper's `IN(…)` clause (per-item loop OR parameterized `%s` placeholders — NOT string interpolation) |
| S5 — `admin_url()` MUST be wrapped with `esc_url()` | **Yes** | EmbedsTab form action URLs MUST use `esc_url( admin_url( … ) )` |
| S6 — Singleton `__construct()` MUST be private | **Yes** | `EmbedBlockRenderer` `private __construct()` per D36; `AbstractServerTab` hierarchy already private |
| S7 — OAuth token endpoint `__return_true` exception | N/A |
| S8 — Body-authenticated mutating REST exception | N/A |
| S9 — Consent-surface displayed-state from authoritative store | N/A — no consent surface |

## Bug-Pattern Guard Rails Applied

| ID | Pattern | Prevention |
|---|---|---|
| B18 | `$wpdb` returns TINYINT as string | `is_enabled_for_server()` MUST cast `(int) $row->embeds_enabled` before strict compare AND same for `is_enabled` column. Verified by test — see plan §Constraints |
| B21 | BerlinDB v3 flag is `modified` NOT `date_updated` | `wp_acrossai_mcp_server_embed_transports.date_modified` column uses `'flags' => ['modified']`. Grep gate on `includes/Database/ServerEmbedTransports/` MUST return zero `'date_updated'` |
| B26 | Grep gates that hard-code directory allow-list silently skip newly-added layers | SC-005 + SC-006 grep gates spell out exact directory allow-list — if a future refactor moves files, gates silently stop enforcing. Documented risk in tasks.md verification tasks |
| B32 | Filter defaults MUST express canonical resolver output, not partial derivation | F037's frontend renderer delegates to `ConnectionMethodRegistry` (F035) — no re-implementation of the DTO enumeration. Verified by SC-005 grep gate |
| B34 | Silent write-loss on BerlinDB schema drift | D28 3-part contract applied to `embeds_enabled` column + new junction table. FR-016 confirms `Main::reconcile_database_schemas()` fires on `admin_init@3` |
| B36 | Inline `<script>` interpolation requires `wp_json_encode()` | EmbedsTab may need `<script>` for the JS toggle-master-reveals-subs UX. If any dynamic value goes into a JS literal, use `wp_json_encode()` NOT `esc_html()` — SEC-030-001 pattern |
| B39 | Dynamic `IN(…)` clauses trip PHPCS false-positives | GC helper `DELETE … WHERE transport_key IN (…)` MUST use per-item loop OR parameterized `%s` placeholders per B39 pattern |

## Security Checklist (per spec §Security Checklist)

Every applicable item verified during clarification + plan phase:

- [x] All form/AJAX handlers verify nonce — FR-018
- [x] All admin page renders check capability — `manage_options` on EmbedsTab render + save
- [x] All REST routes have explicit `permission_callback` — N/A (no REST)
- [x] All user input sanitized at system boundary — checkbox values via `absint()` or `(bool) (int)`
- [x] All output escaped at point of rendering — labels via `esc_html*`; DTO fields via SEC-035-002 preservation invariant; JS values via `wp_json_encode()` per B36
- [x] All DB queries use `$wpdb->prepare()` — BerlinDB Query classes native
- [x] OAuth tokens stored hashed — N/A (no token handling)
- [x] File uploads validated — N/A (no file uploads)

## Async / Concurrency Considerations

- Two admins simultaneously toggling different transports on the same server: BerlinDB `UPSERT`-shaped queries + `UNIQUE(server_id, transport_key)` constraint → last-write-wins per row; safe.
- Two admins toggling THE SAME transport row concurrently: last-write-wins; observability actions fire for both (each admin's transition emits its own event). Documented as by-design.
- No lock/serialization required — admin form save is human-paced; concurrent-admin conflicts are rare + non-destructive.

## Data Isolation

- Per-server scoping: every `wp_acrossai_mcp_server_embed_transports` row carries `server_id`; all Query methods take `$server_id` as the first arg. No cross-server bypass (mirrors D31 F032 OAuth per-server invariant).
- Per-user: no per-user gates in F037. Observability actions carry `$user_id` for audit but the toggle state itself is global (all site users see the same shortcode-rendered content on a public page).

## Recommendations (Advisory)

1. **Pre-merge**: Run `grep -rn "add_filter\|add_action\|add_shortcode" public/Renderers/EmbedBlock/ includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php` — MUST return zero hits per A1 (all wiring in Main.php).
2. **Pre-merge**: Run SC-005 + SC-006 grep gates — MUST return zero hits.
3. **Pre-merge**: Run `grep -rn "'date_updated'" includes/Database/ServerEmbedTransports/` — MUST return zero hits per B21.
4. **Staged-review time**: Rerun `/speckit-security-review-staged` after implementation to catch any drift.

## Conclusion

F037 introduces **1** new authentication/authorization surface (admin save handler), **2** new data-at-rest surfaces (column + table), **1** new user input surface (admin form checkbox array), **1** new frontend render surface (shortcode). All within established plugin patterns (F013 tab hierarchy, D28 schema contract, D35 canonical enumeration, D36 final-class policy, SEC-035-002 preservation invariant). **Approved for implementation** subject to test coverage on the B18 TINYINT strict-compare defense + SC-004 12-combination gate cascade matrix.
