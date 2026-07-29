# Quickstart — F038 verification

**Feature**: User-Accessible MCP Servers Shortcode + Reusable Base Class
**Branch**: `037-user-accessible-mcp-servers-shortcode`
**Prereqs**: Plugin activated on a local WP install (LocalWP typical: `https://wordpress-7-0.local`). At least one MCP server row exists.

This guide validates F038 end-to-end using the local site path, without needing to spin up a companion plugin.

---

## Setup (one-time)

```bash
# From plugin root
composer install
npm ci               # no F038 JS but keeps validate-packages green
composer dump-autoload
```

Ensure at least two servers exist in `wp_acrossai_mcp_servers`. If not:

```bash
# From plugin root
wp acrossai mcp servers create --name="team-support" --slug="team-support" --enabled=1
wp acrossai mcp servers create --name="internal-dev" --slug="internal-dev" --enabled=1
```

If WP-CLI doesn't have this command yet, create rows via the admin: `?page=acrossai_mcp_manager` → **Add New**.

---

## Test 1 — Baseline (no embeds enabled anywhere)

Any MCP server that has NOT been configured on its Embeds tab should render nothing.

1. Log in as an administrator.
2. Create a page (Pages → Add New) with only the shortcode:
   ```
   [acrossai_mcp_servers]
   ```
3. Preview the page.

**Expected**:
- Empty-state wrapper: `<div class="acrossai-mcp-servers acrossai-mcp-servers--empty">` containing `<p>You do not have access to any MCP server yet.</p>` (default empty message).
- `<style>` block emitted once above the wrapper.

**Verify**: browser DevTools → Elements panel. Search for `acrossai-mcp-servers--empty`.

---

## Test 2 — Enable embeds on one server

Turn on the master toggle + one connection method for **team-support**.

1. Visit `?page=acrossai_mcp_manager&action=edit&server=<team-support-id>&tab=embeds`.
2. Toggle master **ON**. Toggle **Claude Desktop** (MCP Clients section) **ON**. Save.
3. Reload the page containing `[acrossai_mcp_servers]`.

**Expected**:
- One `<li class="acrossai-mcp-servers__server">` with `data-server-slug="team-support"`.
- Inside it, one `<section class="acrossai-mcp-servers__transport" data-key="client">` with an `<h4>` labeled **MCP Clients**.
- Inside that section, one `<li class="acrossai-mcp-servers__dto" data-slug="claude-desktop">` showing the 🍰 icon + "Claude Desktop" name.

---

## Test 3 — Access-control gating

Restrict **team-support** to editors only. Verify a subscriber cannot see it.

1. Visit `?page=acrossai_mcp_manager&action=edit&server=<team-support-id>&tab=access-control`.
2. Add a rule: **allow role = editor** only. Save.
3. Log out of admin. Log in as a **subscriber** (create the user first if needed: Users → Add New → Role: Subscriber).
4. Reload the shortcode page.

**Expected (subscriber)**:
- Empty-state wrapper renders — **team-support** is not visible.

5. Log out. Log in as an **editor**.
6. Reload.

**Expected (editor)**:
- **team-support** appears with Claude Desktop entry. Same shape as Test 2.

7. Log out. Verify anonymous:

**Expected (anonymous)**:
- Shortcode renders **nothing** (silent no-render — not even the empty wrapper).

---

## Test 4 — Multiple servers + transports

Enable all three transports on **internal-dev**.

1. As admin, visit `?page=acrossai_mcp_manager&action=edit&server=<internal-dev-id>&tab=embeds`.
2. Master toggle **ON**. Enable **Claude Desktop** + **VS Code** (MCP Clients). Enable **NPM (npx bridge)**. Enable **ChatGPT** (AI Connectors — if a connector is registered). Save.
3. Reload shortcode page as admin.

**Expected**:
- Two servers listed **alphabetically**: `internal-dev` first, then `team-support`.
- **internal-dev** shows three `<section>` elements in priority order: `npm` (10), `client` (20), `ai_connector` (30). Each contains its enabled DTOs.
- **team-support** shows just the one `client` section (Claude Desktop).

---

## Test 5 — Custom empty message

Verify the `empty_message` attribute overrides the default.

1. Edit the test page. Change the shortcode to:
   ```
   [acrossai_mcp_servers empty_message="Nothing here yet — try again after your admin gives you access."]
   ```
2. Turn off master toggle on both servers. Save the Embeds tabs.
3. Reload the page as admin.

**Expected**:
- Empty-state wrapper renders with the custom text, NOT the default.

---

## Test 6 — Filter-based customization (User Story 3)

Register an mu-plugin that hooks `acrossai_mcp_servers_shortcode_html` to wrap the output.

1. Create `wp-content/mu-plugins/f038-html-wrapper.php`:
   ```php
   <?php
   add_filter( 'acrossai_mcp_servers_shortcode_html', function ( $html, $data, $atts ) {
       return '<div class="my-brand-wrapper">' . $html . '</div>';
   }, 10, 3 );
   ```
2. Re-enable an embed on one server (per Test 2).
3. Reload page.

**Expected**:
- The output starts with `<div class="my-brand-wrapper">` and ends with `</div>`.
- Inside is the normal F038 HTML.

Remove the mu-plugin when done.

---

## Test 7 — Fail-open when wpb-access-control absent

Simulates a site where the access-control vendor package isn't installed.

1. Temporarily comment out the `\WPBoilerplate\AccessControl\AccessControlManager` class autoload in `vendor/composer/autoload_classmap.php` OR rename the vendor file.
2. Restrict **team-support** access control to admin-only (Test 3 setup).
3. Log in as a **subscriber**.
4. Reload page.

**Expected**:
- **team-support** appears — because F015 wrapper falls open when package absent, and F038 inherits that contract.

Revert the vendor tweak. Verify the subscriber view returns to "hidden" after revert.

---

## Test 8 — Two renders on one page = one `<style>` block

Place the shortcode twice on the same page.

1. Edit the test page:
   ```
   Top:

   [acrossai_mcp_servers]

   Middle text.

   [acrossai_mcp_servers heading="Again"]
   ```
2. Reload as an admin.

**Expected**:
- Both shortcode outputs render.
- **Exactly one** `<style>` block in the rendered HTML. Verify with DevTools → Elements → Ctrl+F for `<style` in the page source — expect 1 match inside the shortcode output area (WP itself may have others; count only F038's `acrossai-mcp-servers` prefixed rules).

---

## Test 9 — Third-party fourth transport (SC-005)

Simulates a companion plugin registering a new transport category.

1. Create `wp-content/mu-plugins/f038-fake-transport.php`:
   ```php
   <?php
   add_filter( 'acrossai_mcp_embed_transports', function ( array $classes ): array {
       $classes[] = \My_Companion\FakeTransport::class;
       return $classes;
   } );

   namespace My_Companion;
   use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

   class FakeTransport extends AbstractEmbedTransport {
       public function get_transport_key(): string { return 'buddyboss-profile'; }
       public function get_checkbox_label(): string { return 'BuddyBoss Profile'; }
       public function get_priority(): int { return 40; }
       public function get_dtos(): array {
           return [ [ 'slug' => 'bb-badge', 'name' => 'Profile Badge', 'icon' => '🏆', 'description' => '', 'meta' => [] ] ];
       }
   }
   ```
2. Visit `?page=acrossai_mcp_manager&action=edit&server=<team-support-id>&tab=embeds`. A new "BuddyBoss Profile" section should appear with a **Profile Badge** toggle. Turn it ON. Save.
3. Reload shortcode page as admin.

**Expected**:
- **team-support** now shows a fourth section: `<section data-key="buddyboss-profile">` with the `bb-badge` entry.
- **Zero F038 code changes** were required to surface it.

Remove the mu-plugin when done.

---

## Test 10 — PHPUnit suite green

```bash
composer run test -- --testsuite=user-servers
```

**Expected**: green, all test cases pass. Also run the full suite to confirm no regressions:

```bash
composer test
```

**Expected**: green.

---

## Test 11 — Quality gates

```bash
composer run phpcs
composer run phpstan
npm run validate-packages
```

**Expected**: all three pass with zero errors and zero warnings.

---

## Grep-gate verification

Run each of these from the plugin root; every one MUST return **zero hits**:

```bash
grep -rn "apply_filters.*acrossai_mcp_embed_transports" public/Renderers/UserServers/
grep -rn "apply_filters.*acrossai_mcp_client_classes" public/Renderers/UserServers/
grep -rn "_embeds_enabled\|_embeds_clients" public/Renderers/UserServers/
grep -rn "UserServers" includes/
```

And this MUST return **exactly one hit** (inside `UserServersBlock::register_shortcode`):

```bash
grep -rn "add_shortcode" public/Renderers/UserServers/
```

---

## Sign-off

If Tests 1–11 pass and every grep-gate result is as expected, F038 is ready for `/speckit-tasks` → `/speckit-implement` → PR.
