<?php
/**
 * Access Control tab — three-section renderer.
 *
 * F015 shipped as a thin delegate to `public/Renderers/AccessControlBlock.php`
 * (the vendor wpb-access-control React panel).
 *
 * F030 extends the tab with two ADDITIONAL sections below the vendor panel,
 * separated by <hr>:
 *
 *   1. Permission override form — a single checkbox that toggles
 *      `override_abilities_permission` on the server row. When ON, every
 *      ability exposed to this server via `wp_acrossai_mcp_server_abilities`
 *      has its `permission_callback` swapped for `__return_true` at
 *      ability-call time by `PermissionOverrideProcessor`. Gated by 6
 *      defensive layers documented in DEC-F030-PERMISSION-CALLBACK-OPERATOR-
 *      OPT-IN-BYPASS.
 *   2. AbilitiesManagerPromoCard — recommends the sibling
 *      `acrossai-abilities-manager` plugin for fine-grained per-ability
 *      access-control rules; includes a "Prefer to use code?" <details>
 *      block documenting the WP core filter (`wp_register_ability_args`,
 *      P999999) for developers who don't want the sibling plugin.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials\AbilitiesManagerPromoCard;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Access Control tab — vendor React panel + F030 override form + promo card.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class AccessControlTab extends AbstractServerTab {

	/**
	 * The tab's URL slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'access-control';
	}

	/**
	 * The tab's operator-visible label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Access Control', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 70;
	}

	/**
	 * Renders the three sections: vendor React panel, F030 permission-override
	 * form, F030 abilities-manager promo card.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data (from Row::to_array()).
	 * @return void
	 */
	protected function render_body( array $server ): void {
		// Section 1 — vendor wpb-access-control React panel (unchanged).
		\AcrossAI_MCP_Manager\Public\Renderers\AccessControlBlock::instance()->render(
			(int) $server['id'],
			array(
				'context'           => 'admin',
				'cap'               => 'manage_options',
				'submit_target_url' => $this->server_edit_url( $server, 'access-control' ),
				'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
			)
		);

		// F030 — save notice.
		$this->render_save_notice();

		// F030 — Permission Override section: one header + one form (toggle +
		// nested promo callout + footer) + a sibling <details> code block.
		echo '<hr class="acrossai-mcp-pom__sep">';
		$this->render_layout_styles();
		echo '<div class="acrossai-mcp-pom">';
		$this->render_pom_header();
		$this->render_permission_override_form( $server );
		AbilitiesManagerPromoCard::instance()->render_code_details();
		echo '</div>';
	}

	/**
	 * Render the shared header (icon + H2 + intro paragraph) that sits
	 * outside the main card.
	 *
	 * @return void
	 */
	private function render_pom_header(): void {
		echo '<div class="acrossai-mcp-pom__header">';
		echo '<h2 class="acrossai-mcp-pom__title">' . esc_html__( 'Permission Override', 'acrossai-mcp-manager' ) . '</h2>';
		echo '</div>';
		echo '<p class="acrossai-mcp-pom__intro">';
		printf(
			/* translators: 1: <code>permission_callback</code>, 2: <code>permission_callback</code>. */
			esc_html__( 'Control how MCP requests to this server evaluate each ability’s %1$s — either flip the coarse per-server override below, or install the AcrossAI Abilities Manager for a UI that edits every ability’s %2$s individually.', 'acrossai-mcp-manager' ),
			'<code>permission_callback</code>',
			'<code>permission_callback</code>'
		);
		echo '</p>';
	}

	/**
	 * Emit the minimal structural layout stylesheet for the Permission
	 * Override section. Layout-only (flex rows, badge, icon spacing) —
	 * no backgrounds, borders, or shadows so it inherits the surrounding
	 * WP admin visual style.
	 *
	 * @return void
	 */
	private function render_layout_styles(): void {
		static $emitted = false;
		if ( $emitted ) {
			return;
		}
		$emitted = true;
		echo <<<'CSS'
<style>
/* ── Outer wrapper ─────────────────────────────────────────────────────── */
.acrossai-mcp-pom {
	margin-top: 20px;
	color: #1e1f24;
}
.acrossai-mcp-pom code {
	padding: 2px 6px;
	border-radius: 4px;
	background: #f0f0f2;
	color: #1e1f24;
	font-size: 12px;
	font-family: Menlo, Consolas, monospace;
}

/* ── Header (icon tile + h2 + intro paragraph) ─────────────────────────── */
.acrossai-mcp-pom__header {
	display: flex;
	align-items: center;
	gap: 14px;
	margin: 0 0 12px;
}
.acrossai-mcp-pom__header-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 42px;
	height: 42px;
	border-radius: 10px;
	background: linear-gradient( 135deg, #6c4bdb 0%, #5039a8 100% );
	color: #fff;
	font-size: 22px;
	line-height: 1;
	box-shadow: 0 2px 6px rgba( 108, 75, 219, 0.25 );
}
.acrossai-mcp-pom__header-icon.dashicons {
	width: 42px;
	height: 42px;
	font-size: 22px;
}
.acrossai-mcp-pom__title {
	margin: 0;
	font-size: 22px;
	font-weight: 700;
	color: #1e1f24;
}
.acrossai-mcp-pom__intro {
	max-width: 780px;
	color: #4a5060;
	margin: 0 0 20px;
	line-height: 1.55;
}

/* ── Main card (form) — white background with rounded border ───────────── */
.acrossai-mcp-pom__card {
	background: #ffffff;
	border: 1px solid #e2e2e6;
	border-radius: 10px;
	padding: 22px 24px 20px;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.03 );
	margin: 0 0 14px;
}

/* ── Section head — h3 + priority badge ────────────────────────────────── */
.acrossai-mcp-pom__section-head {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 0 10px;
}
.acrossai-mcp-pom__section-head h3 {
	margin: 0;
	font-size: 15px;
	font-weight: 700;
	color: #1e1f24;
}
.acrossai-mcp-pom__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 999px;
	background: #fff4d6;
	color: #886400;
	font-size: 11px;
	font-weight: 600;
	font-family: Menlo, Consolas, monospace;
	letter-spacing: 0.02em;
	line-height: 1.6;
}
.acrossai-mcp-pom__card > p {
	color: #4a5060;
	line-height: 1.55;
	margin: 0 0 16px;
}

/* ── Toggle row — inset white card, checkbox + label + subtitle ────────── */
.acrossai-mcp-pom__toggle-row {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 14px 16px;
	margin: 0 0 14px;
	background: #fbfbfd;
	border: 1px solid #e6e6ea;
	border-radius: 8px;
}
.acrossai-mcp-pom__toggle-row input[type="checkbox"] {
	margin: 0;
	flex: 0 0 auto;
}
.acrossai-mcp-pom__toggle-label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	cursor: pointer;
	margin: 0;
}
.acrossai-mcp-pom__toggle-title {
	font-weight: 600;
	color: #1e1f24;
	font-size: 13px;
}
.acrossai-mcp-pom__toggle-sub {
	color: #6a7080;
	font-size: 12px;
}

/* ── Info callout — light blue background with darker blue heading ─────── */
.acrossai-mcp-pom__callout {
	padding: 14px 16px 0;
	margin: 0 0 14px;
	background: #eef2ff;
	border: 1px solid #dbe1fb;
	border-radius: 8px;
}
.acrossai-mcp-pom__callout-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 4px;
}
.acrossai-mcp-pom__callout-head h4 {
	margin: 0;
	font-size: 13px;
	font-weight: 700;
	color: #2c4180;
}
.acrossai-mcp-pom__callout-icon {
	color: #2c4180;
	width: 18px;
	height: 18px;
	font-size: 18px;
	line-height: 1;
}
.acrossai-mcp-pom__callout > p {
	color: #3d4560;
	font-size: 13px;
	line-height: 1.55;
	margin: 0 0 12px;
}

/* ── Status row inside callout — cream inset with amber bullet ─────────── */
.acrossai-mcp-pom__status-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 14px;
	padding: 12px 14px;
	margin: 12px -16px -0px;
	background: #fef8e4;
	border-top: 1px solid #f6ecc4;
	border-radius: 0 0 8px 8px;
}
.acrossai-mcp-pom__status-row-text {
	font-size: 13px;
	color: #6b5300;
	line-height: 1.4;
}
.acrossai-mcp-pom__status-row-text::before {
	content: "";
	display: inline-block;
	width: 8px;
	height: 8px;
	margin-right: 8px;
	border-radius: 999px;
	background: #f59e0b;
	vertical-align: middle;
}
.acrossai-mcp-pom__status-row .button.button-primary {
	margin: 0;
	background: #3d5afe;
	border-color: #3348d6;
	color: #fff;
	box-shadow: none;
	text-shadow: none;
}
.acrossai-mcp-pom__status-row .button.button-primary:hover,
.acrossai-mcp-pom__status-row .button.button-primary:focus {
	background: #3348d6;
	border-color: #2739b3;
	color: #fff;
}

/* ── Footer — divider, Save button left, helper text right (or below) ──── */
.acrossai-mcp-pom__footer {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 16px;
	padding-top: 16px;
	margin-top: 4px;
	border-top: 1px solid #eaeaee;
}
.acrossai-mcp-pom__footer .button.button-primary {
	margin: 0;
	background: #3d5afe;
	border-color: #3348d6;
	color: #fff;
	box-shadow: none;
	text-shadow: none;
}
.acrossai-mcp-pom__footer .button.button-primary:hover,
.acrossai-mcp-pom__footer .button.button-primary:focus {
	background: #3348d6;
	border-color: #2739b3;
	color: #fff;
}
.acrossai-mcp-pom__footer-help {
	color: #6a7080;
	font-size: 12px;
}

/* ── Warning banner (when override is ON) — sits above the toggle row ──── */
.acrossai-mcp-pom__card > .notice.notice-warning.inline {
	margin: 0 0 14px;
}

/* ── Details block — white card, sibling of the main form card ─────────── */
.acrossai-mcp-pom__details {
	background: #ffffff;
	border: 1px solid #e2e2e6;
	border-radius: 10px;
	padding: 14px 18px;
	box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.03 );
	margin-top: 14px;
}
.acrossai-mcp-pom__details summary {
	cursor: pointer;
	font-weight: 600;
	color: #1e1f24;
	list-style: revert;
}
.acrossai-mcp-pom__details[open] summary {
	margin-bottom: 10px;
	border-bottom: 1px solid #eaeaee;
	padding-bottom: 10px;
}
.acrossai-mcp-pom__details > p {
	color: #4a5060;
	line-height: 1.55;
}
.acrossai-mcp-pom__details pre {
	padding: 12px 14px;
	background: #0f1424;
	color: #e4e8f5;
	border-radius: 6px;
	overflow-x: auto;
	font-size: 12px;
	line-height: 1.55;
}
.acrossai-mcp-pom__details pre code {
	background: transparent;
	color: inherit;
	padding: 0;
	font-family: Menlo, Consolas, monospace;
}

/* ── Separator between vendor panel and F030 section — kept subtle ─────── */
.acrossai-mcp-pom__sep {
	border: 0;
	border-top: 1px solid #e2e2e6;
	margin: 24px 0;
}
</style>
CSS;
	}

	/**
	 * Render the success notice when the operator has just saved the override
	 * toggle. The `acrossai_mcp_manager_permission_saved=1` query flag is set
	 * by the redirect in `Settings::handle_save_permission_override()`.
	 *
	 * The flag only gates a static translated string — no user-supplied text
	 * is echoed from the GET param, so no XSS concern.
	 *
	 * @return void
	 */
	private function render_save_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag, no state mutation.
		if ( empty( $_GET['acrossai_mcp_manager_permission_saved'] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Permission override setting saved.', 'acrossai-mcp-manager' );
		echo '</p></div>';
	}

	/**
	 * Render the F030 permission-override form section.
	 *
	 * Structure per spec FR-003 / FR-005 / FR-016 / FR-017:
	 * - Optional warning banner when the flag is currently ON (FR-016).
	 * - Explanatory paragraph.
	 * - Form with per-server nonce + hidden action + hidden server_id +
	 *   labelled checkbox + submit button.
	 * - Inline <script> firing native confirm() when the form is submitted
	 *   with the checkbox checked (FR-017). CRITICAL: dynamic values (server
	 *   name) are interpolated via `wp_json_encode()` — never `esc_html()`
	 *   / `esc_attr()` for JS string context (SEC-030-001 remediation).
	 *
	 * @param array $server Server row data.
	 * @return void
	 */
	private function render_permission_override_form( array $server ): void {
		$server_id    = (int) $server['id'];
		$server_name  = (string) ( $server['server_name'] ?? '' );
		$is_on        = 1 === (int) ( $server['override_abilities_permission'] ?? 0 );
		$nonce_action = 'acrossai_mcp_manager_permission_override_' . $server_id;
		$nonce_field  = 'acrossai_mcp_manager_permission_override_nonce';
		$form_action  = add_query_arg(
			array(
				'page'   => \AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs::PARENT,
				'action' => 'save_permission_override',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);
		$form_id      = 'acrossai-mcp-permission-override-form-' . $server_id;
		$checkbox_id  = 'acrossai-mcp-permission-override-checkbox-' . $server_id;
		$confirm_msg  = sprintf(
			/* translators: %s: MCP server name. */
			__( 'Enable Ability Permission Override for the MCP server "%s"? Every ability exposed to this server will bypass its own permission_callback for MCP requests to this server.', 'acrossai-mcp-manager' ),
			$server_name
		);

		printf(
			'<form method="post" id="%1$s" action="%2$s" class="acrossai-mcp-pom__card">',
			esc_attr( $form_id ),
			esc_url( $form_action )
		);
		wp_nonce_field( $nonce_action, $nonce_field );

		// Section head — H3 + priority badge, matching reference layout.
		echo '<div class="acrossai-mcp-pom__section-head">';
		echo '<h3>' . esc_html__( 'Per-server override toggle', 'acrossai-mcp-manager' ) . '</h3>';
		echo '<span class="acrossai-mcp-pom__badge">' . esc_html__( 'priority 999999', 'acrossai-mcp-manager' ) . '</span>';
		echo '</div>';

		// Description paragraph, with inline <code> for keywords.
		echo '<p>';
		printf(
			/* translators: 1: <code>true</code>, 2: <code>permission_callback</code>. */
			esc_html__( 'When checked, every ability exposed to this MCP server (via the Abilities tab) will always return %1$s from its %2$s for MCP requests routed to this server. Site-wide callers (WP admin, other REST namespaces, WP-CLI) are unaffected. Overrides any prior per-ability access-control decision.', 'acrossai-mcp-manager' ),
			'<code>true</code>',
			'<code>permission_callback</code>'
		);
		echo '</p>';

		// Persistent warning banner when the flag is currently ON (FR-016).
		if ( $is_on ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: MCP server name. */
				esc_html__( 'Permission override is currently ON for "%s". Every ability exposed to this server bypasses its own permission_callback for MCP requests to this server. This SUPERSEDES access-control rules configured above and any per-ability access rules configured by other plugins.', 'acrossai-mcp-manager' ),
				esc_html( $server_name )
			);
			echo '</p></div>';
		}

		// Toggle row — checkbox inset with title + secondary status text.
		$subtitle = $is_on
			? __( 'Enabled — ALL exposed abilities allowed for MCP requests to this server.', 'acrossai-mcp-manager' )
			: __( 'Disabled — per-ability access control applies.', 'acrossai-mcp-manager' );

		echo '<div class="acrossai-mcp-pom__toggle-row">';
		printf(
			'<input type="checkbox" id="%1$s" name="override_abilities_permission" value="1"%2$s>',
			esc_attr( $checkbox_id ),
			checked( $is_on, true, false )
		);
		printf( '<label for="%s" class="acrossai-mcp-pom__toggle-label">', esc_attr( $checkbox_id ) );
		echo '<span class="acrossai-mcp-pom__toggle-title">' . esc_html__( 'Override abilities permission_callback for this MCP server', 'acrossai-mcp-manager' ) . '</span>';
		echo '<span class="acrossai-mcp-pom__toggle-sub">' . esc_html( $subtitle ) . '</span>';
		echo '</label>';
		echo '</div>';

		// Nested promo callout — abilities-manager plugin recommendation.
		AbilitiesManagerPromoCard::instance()->render_callout( $server );

		// Footer — Save button LEFT, helper text RIGHT (aligned per reference).
		echo '<div class="acrossai-mcp-pom__footer">';
		submit_button( __( 'Save Permission Override', 'acrossai-mcp-manager' ), 'primary', 'submit', false );
		echo '<span class="acrossai-mcp-pom__footer-help">' . esc_html__( 'Changes apply only to this server’s MCP route.', 'acrossai-mcp-manager' ) . '</span>';
		echo '</div>';

		echo '</form>';

		// FR-017 + SEC-030-001 remediation — inline confirm() prompt. Dynamic
		// values MUST be JSON-encoded (NOT esc_html / esc_attr) for JS-string
		// context; esc_html would leave `'`/`"` un-escaped for JS parsers,
		// producing broken JS or stored XSS by an admin who set a hostile
		// server_name.
		printf(
			'<script>document.getElementById(%1$s).addEventListener("submit", function (event) { if (this.elements["override_abilities_permission"] && this.elements["override_abilities_permission"].checked) { if (!window.confirm(%2$s)) { event.preventDefault(); } } });</script>',
			wp_json_encode( $form_id ),
			wp_json_encode( $confirm_msg )
		);
	}
}
