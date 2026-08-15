Design a 5-step "MCP Quick Setup Wizard" for a WordPress admin plugin. This is a design-review deliverable — desktop-first, WP admin styling (matches @wordpress/components + core wp-admin chrome).

## Product context

The plugin is "AcrossAI MCP Manager" — it lets WordPress admins expose their site as an MCP (Model Context Protocol) endpoint that AI clients (Claude, ChatGPT, Cursor, etc.) can connect to. Today the setup is scattered across 11 tabs on a server-edit page; the Quick Setup wizard condenses the essential path into a single linear flow.

Target user: WordPress admin (site owner or agency dev). Comfortable with WP admin UI. May not know what MCP is yet — copy should be self-explanatory.

## Architecture

- **Full-page wizard** (not a modal). Renders inside the WP admin at `wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1`
- Step index in URL (`&step=1..5`) so back button works and users can bookmark.
- Left sidebar: vertical stepper (5 steps, current highlighted, past checked, future greyed out).
- Right pane: current step content + footer with "Back" / "Continue" buttons.
- Header: plugin logo left, "Quick Setup" title, "Exit setup" link right (returns to server list).

## Entry point (design an admin bar chip + first-run banner)

1. **Admin bar** — top wp-admin bar shows a chip labeled "MCP Quick Setup" with a wrench dashicon. Clicking it opens the wizard.
2. **First-run banner** — the first time an admin visits the plugin page after install, show a dismissible WP-native notice: "Get started in under a minute — try the Quick Setup wizard." with a "Start setup" button and "Dismiss" link. Not a popup; sits above the server list.

## Step 1 — Select or create a server

- Data table of existing MCP servers with columns: Name, Status (Active/Inactive badge), Route.
- Radio-select one row to advance.
- Below the table: "+ Create new server" button (secondary, prominent). Clicking it slides in a compact inline form (Name, Slug auto-suggested from Name, optional Description). Save → new row appears in table, auto-selected, "Continue" enabled.

**Hardcoded sample rows** (use these for the mockup):

| Name               | Status   | Route                             |
|--------------------|----------|-----------------------------------|
| Default MCP Server | Active   | mcp/mcp-adapter-default-server    |
| Marketing Site     | Active   | acrossai-mcp/v1/marketing         |
| Staging Playground | Inactive | acrossai-mcp/v1/staging           |

**Empty state** (no servers yet): full-width card centered with an icon, headline "No MCP servers yet", subhead "Create one to get started — takes 10 seconds.", primary button "Create your first server".

## Step 2 — User Access Control

Reuses the existing Access Control tab's form pattern. Show:

**Info banner** (blue tint, info icon) — use this exact text:

> Default policy: administrators only. When the "Who can access" dropdown below reads "No user access added by admin" (no rule configured), only users with the `manage_options` capability (WordPress administrators) can reach this server's MCP endpoint. Set any rule below — Anyone / Authenticated users / a role / a user / a capability — to broaden access. Enforced at request time via a runtime filter; no database access-control rules are seeded automatically.

**Form:**

- Label: "Who can access"
- Dropdown with these options:
  - No user access added by admin (default)
  - Public (no login required)
  - Any logged-in user
  - WordPress role
  - Specific user
  - Specific capability
- When "WordPress role" is selected, show a role multi-picker below (checkbox list: Administrator, Editor, Author, Contributor, Subscriber). Similar reveal for the other rule types.

Small footnote below the form:

> You can change this anytime under the server's Access Control tab.

## Step 3 — Abilities

Two possible states — design BOTH:

### State A: Abilities Manager plugin NOT installed (default)

Big number: **3** abilities available.
Subhead: "WordPress ships with only 3 abilities by default."
List:

- `core/get-environment-info`
- `core/get-site-info`
- `core/get-user-info`

Then a promo card (purple accent, matches AcrossAI brand):

> **Unlock 300+ abilities with AcrossAI Abilities Manager**
> Create pages, update content, install plugins, update WordPress core, manage your entire site — all from your AI client. See real use cases at acrossai.co/use-cases
> [Install from WordPress.org →] (primary CTA)
> [View case studies →] (secondary link)

Info footnote:

> By default, all abilities registered by Abilities Manager can be accessed by users with the `manage_options` capability (admins only). You can broaden this per-ability in the Access Control step.

### State B: Abilities Manager IS installed

Big number: **327** abilities available (sample count).
Subhead: "You have 327 abilities from AcrossAI Abilities Manager."

Two action buttons side-by-side:

- **Enable all abilities for this server** (primary, filled)
- **Configure abilities one-by-one** (secondary, opens the existing Abilities tab in a new browser tab — show a small external-link icon)

Sample ability categories to illustrate the list (collapsed accordion, showing top 3 categories with counts):

- Content Management (48)
- Site Configuration (36)
- User Management (29)

Same admin-only info footnote as State A.

## Step 4 — Enable the server

Two states:

### State A: Server is already enabled — SKIP this step.

The stepper visually marks step 4 as "already done" and auto-advances to step 5.

### State B: Server is currently disabled

Full-width card:

> **Your server is currently disabled.**
> Enable it now so AI clients can connect.
> [Enable this server] (primary button, filled)

Small helper: "You can disable it anytime from the server list."

## Step 5 — Choose a connection method

Grid of 4 large cards (2×2 on desktop, stacked on mobile). Each card has an icon, title, one-line description, and a "Set up →" CTA.

### Card 1 — Connectors / Integrations (paid, badge in top-right corner: "PAID" or "PRO")

- Icon: connected chain-links or a hosted-cloud icon
- Title: "One-click OAuth (Connectors)"
- Description: "Paste one URL into Claude, ChatGPT, Grok, or Gemini and approve. No config files."

Three sub-states for this card — design ALL THREE:

**A) AcrossAI Pro NOT installed:**
CTA: "Get AcrossAI Pro →" opens `https://acrossai.co/pricing/#pricing` in new tab.
Trust line below the card:

> Start on Personal with a 30-day free trial on 1 site. No card charged today, cancel any time before it ends. Try it risk-free for 14 days.

**B) Installed but not active:**
CTA: "Activate AcrossAI Pro" (blue primary) — clicking activates in place (spinner then success state).

**C) Active:**
Card expands into a 2×2 mini-grid with these four client buttons (each opens a new tab to the corresponding config panel):

- ChatGPT
- Claude
- Gemini
- Grok

### Card 2 — MCP Client (npx bridge)

- Icon: puzzle-piece or plugin-icon
- Title: "MCP Client"
- Description: "Paste a JSON config into Claude Desktop, VS Code, Cursor, and 6 more."

Click expands the card into a client picker. Show these 8 clients (actual production list) as a horizontal pill row:

- 🍰 Claude Desktop
- 📄 Claude Code
- ▤ VS Code
- 🐱 GitHub Copilot
- ⚡ Cursor
- 🐙 Codex
- 💎 Gemini CLI
- ⚙ Custom Client

Selecting a client reveals a JSON config code block with a "Copy config" button (monospace font, syntax-highlighted).

### Card 3 — npm command

- Icon: terminal / npm logo
- Title: "npm (one-line install)"
- Description: "Run a single npx command — no config files, no JSON."

Click reveals a code block:

```
npx -y @acrossai/mcp-manager --siteurl=https://example.com --server=my-server
```

With a "Copy command" button.

### Card 4 — WP-CLI (STDIO)

- Icon: terminal / WordPress logo
- Title: "WP-CLI (local subprocess)"
- Description: "Best for CI or local dev. No network credentials transmitted."

Click reveals a code block:

```
wp mcp-adapter serve --server=my-server --user=admin
```

With a "Copy command" button.

## Completion screen (Step 5+ after user confirms)

Full-height success screen:

- Big green checkmark, "You're all set!" headline
- Summary card with 4 rows:
  - ✅ Server: **Default MCP Server**
  - ✅ Access: **Administrators only**
  - ✅ Abilities: **3 enabled** (or **327 enabled**)
  - ✅ Connected via: **MCP Client (Claude Desktop)** *(varies)*
- Three CTAs:
  - **Go to server dashboard** (primary)
  - **Set up another server** (secondary — restarts wizard)
  - **Dismiss** (text link — closes wizard, returns to server list)
- Tiny footer: "You can re-run this wizard anytime from the top admin bar."

## Visual style

- WordPress admin native — use `@wordpress/components` chrome (buttons, inputs, dropdowns, notices).
- Purple accent for AcrossAI brand highlights (`#4f46e5` family — matches existing AI Connectors promo tab).
- Use dashicons where reasonable; svg-inline icons for connection-method card icons (Feather / Lucide style).
- White cards on a `#f0f0f1` admin background (WP default).
- Generous whitespace — this wizard replaces multiple screens' worth of navigation, so it should feel airy, not cramped.
- Mobile: stack everything vertically, sidebar collapses to a horizontal stepper strip on top.

## Deliverables

Please produce:

1. High-fidelity mockups of all 5 steps + completion screen.
2. All conditional states called out (Step 3 A/B, Step 4 A/B, Step 5 Connectors card A/B/C).
3. Empty state for Step 1 (no servers).
4. Admin bar chip + first-run banner as separate mockups.
5. Light-mode only (WP admin doesn't ship dark mode; skip it).
