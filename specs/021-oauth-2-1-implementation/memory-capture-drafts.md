# Feature 021 — Memory Capture Drafts

Prepared during Phase 8 for post-merge `/speckit-memory-md-capture-from-diff`.
Not written to durable memory until the F021 branch merges to main.

---

## DEC — Feature 021 shipped

### `DEC-OAUTH-REFRESH-FAMILY-REVOCATION` (Decision)

**Status**: Active
**Why durable**: This is the RFC 9700 §2.2.2 pattern for any future OAuth
feature. Applies whenever refresh-token rotation is implemented — the
family_id column + `revoke_by_family_id` + reuse-detection branch together
close the stolen-refresh-token compromise window from refresh-TTL to
access-TTL.

**Decision**
- `OAuthTokens.token_family_id char(36)` — UUIDv4 shared across all tokens
  descended from a single auth code.
- `TokensQuery::revoke_by_family_id( string $family_id ): array<int, int>`
  — bulk revoke with `strlen === 36` guard against empty-string wipes.
- `TokenController::handle_refresh_token` — on presented refresh with
  `revoked === 1`, bulk-revoke family + fire `token_revoked` per row with
  reason `'family_reuse_detected'`.

**Tradeoffs**
- Gained: attacker window collapses from 30d → 3600s on any stolen refresh.
- Reconsider: only if RFC 9700 is superseded by a different recommendation.

---

### `DEC-CRON-HANDLER-SAME-PHASE-AS-SCHEDULE` (Decision)

**Status**: Active
**Why durable**: SEC-021-T01 finding from the tasks-phase security review.
Applies to any feature that schedules a WP-Cron event. Scheduling without
the handler class in-place creates a between-checkpoints window where the
cron fires with no callback (silent no-op at best, PHP fatal at worst).

**Decision**
- Any feature that calls `wp_schedule_event( ..., $hook )` in its
  Activator MUST ship the `add_action( $hook, ... )` wire AND the callback
  class body in the SAME phase.
- Grep verification is straightforward:
  `grep -R 'wp_schedule_event' includes/` and confirm every hook has a
  matching `add_action` in Main.php AND a callback class that exists.

**Tradeoffs**
- Gained: no partial-deploy fatal error window.
- Reconsider: never — this is a correctness invariant, not a preference.

---

### `DEC-OAUTH-AICONNECTORSTAB-CARDS-OVER-DATAVIEWS` (Decision)

**Status**: Active (accepted deviation from Constitution IV)
**Why durable**: Second occurrence of the "hand-rolled admin UI where
neither DataForm nor DataViews expresses the intended UX" pattern
(F020 shuttle picker was the first). This precedent establishes that
per-connector-branded cards with per-item action buttons are acceptable
when the alternative (DataViews grid) would materially degrade UX.

**Decision**
- `AIConnectorsTab::render_body` renders one card per registered
  `AbstractConnectorProfile` — with brand icon, name, setup instructions,
  and Generate/Regenerate button.
- Alternative rejected: DataViews grid loses the profile-branded card
  layout that operators need to visually distinguish Claude from ChatGPT
  from Gemini.

**Tradeoffs**
- Gained: instantly-recognizable connector picker UX.
- Reconsider: if `@wordpress/dataviews` ships per-row template rendering
  (currently it does not).

---

### `DEC-OAUTH-BUILTIN-TAB-NOT-FILTER` (Decision)

**Status**: Active
**Why durable**: Feature 019 introduced the
`acrossai_mcp_manager_server_tabs` filter for third-party per-server tabs.
Feature 021 adds `AIConnectorsTab` as a BUILT-IN tab — inserted directly
into `Registry::all_tabs()`, NOT via the filter. Any future confusion
about "should this new tab go through the filter?" is answered here.

**Decision**
- Third-party contribution → `acrossai_mcp_manager_server_tabs` filter.
- Base-plugin tabs → direct insertion into `Registry::all_tabs()` at their
  priority slot. Class-level docblock explains this to future maintainers.

**Tradeoffs**
- Gained: clean separation — the filter list stays authoritative for
  "which tabs come from companion plugins."
- Reconsider: never — mixing built-ins into the filter would break the
  F019 contract.

---

### `DEC-OAUTH-TEMPLATES-DIRECTORY` (Decision)

**Status**: Active
**Why durable**: F021 introduces a new top-level `templates/` directory
for `templates/oauth/consent.php`. This is the first time the plugin uses
this convention — future features (login screens, error pages, any
public-facing HTML rendered outside the admin frame) should follow.

**Decision**
- `templates/` is the plugin-level home for self-contained HTML templates
  rendered OUTSIDE the admin frame (no admin bar, no theme header).
- Rendered via `require`; parameters passed via local variables in the
  including method's scope.

**Tradeoffs**
- Gained: honest layout — `public/Partials/` stays for PHP class rendering.
- Reconsider: if the plugin adopts a proper templating engine
  (Blade, Twig).

---

## BUGS — new preventive patterns

### `B26 — DCR fingerprint MUST sort arrays before hashing`

**Category**: Bug pattern (correctness)
**Symptom**: DCR clients that re-register with byte-identical metadata
but arrays in different order should dedup — but naive
`hash('sha256', json_encode($meta))` produces different hashes for
`['a','b']` vs `['b','a']`, causing duplicate client rows and secret
churn.

**Prevention**:
```php
sort( $canonical['redirect_uris'] );
sort( $canonical['grant_types'] );
sort( $canonical['response_types'] );
return hash( 'sha256', (string) wp_json_encode( $canonical ) );
```

Test: `DCRDedupTest::test_field_order_does_not_affect_fingerprint`.

---

## WORKLOG

### 2026-07-10 — Feature 021 OAuth 2.1 authorization server

Shipped provider-agnostic OAuth 2.1 + PKCE authorization server:

- 4 domain-root endpoints (RFC 8414 + RFC 9728 metadata, `/authorize`, `/token`)
- RFC 7591 Dynamic Client Registration at `/wp-json/acrossai-mcp-manager/v1/oauth/register`
- Admin credential generator at `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client`
- Built-in AI Connectors tab (priority 35)
- `AbstractConnectorProfile` + `acrossai_mcp_manager_connector_profiles` filter
- Bearer TokenValidator on `determine_current_user @ 20` with RFC 8707 audience-binding
- Refresh token rotation with **SEC-021-001 family revocation** (RFC 9700 §2.2.2)
- Daily cleanup cron, `deleted_user` cascade, uninstall opt-in

3 new BerlinDB tables + ~30 new PHP classes + ~20 tests. Zero new
composer dependencies. Zero PHPCS errors. PHPStan level 8 clean.

Governance chain: `/speckit-git-feature` → `/speckit-specify` →
`/speckit-clarify` (4 clarifications) → `/speckit-memory-md-plan-with-memory` →
`/speckit-architecture-guard-governed-plan` (produced HIGH plan-phase
security finding SEC-021-001 refresh family revocation) →
`/speckit-tasks` → `/speckit-architecture-guard-governed-tasks` (6 T-level
security findings + 7 refactor tasks merged inline) →
`/speckit-architecture-guard-governed-implement` (7 phases delivered).

---

## Index rows to add

Add these lines to `docs/memory/INDEX.md` after F021 merges to main:

```text
| DEC-OAUTH-REFRESH-FAMILY-REVOCATION | Decisions.md | 2026-07-10 | RFC 9700 §2.2.2 family_id pattern for future OAuth features |
| DEC-CRON-HANDLER-SAME-PHASE-AS-SCHEDULE | Decisions.md | 2026-07-10 | Handler class MUST ship in same phase as wp_schedule_event |
| DEC-OAUTH-AICONNECTORSTAB-CARDS-OVER-DATAVIEWS | Decisions.md | 2026-07-10 | Constitution IV accepted deviation — 2nd occurrence after F020 shuttle |
| DEC-OAUTH-BUILTIN-TAB-NOT-FILTER | Decisions.md | 2026-07-10 | Built-in tabs skip F019 filter |
| DEC-OAUTH-TEMPLATES-DIRECTORY | Decisions.md | 2026-07-10 | New top-level `templates/` for out-of-admin HTML |
| B26 | Bugs.md | 2026-07-10 | Sort arrays before JSON+hash for canonical fingerprints |
```
