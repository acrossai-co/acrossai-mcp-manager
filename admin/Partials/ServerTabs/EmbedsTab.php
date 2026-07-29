<?php
/**
 * Embeds tab — master toggle + per-transport sub-toggles.
 *
 * Feature 037. Post-refactor: this class is fully self-contained. Extending
 * `AbstractReactMountServerTab` gives it its own asset enqueue, REST
 * GET/POST controller, permission gate, bootstrap payload, and noscript
 * fallback — the class body is now just identity + config + the two state
 * methods (`get_state_for_server` reads / `set_state_for_server` writes)
 * plus a domain-shaped noscript summary override.
 *
 * The previous `admin/Main.php::maybe_enqueue_embeds_app()` +
 * `includes/REST/EmbedsController.php` are retired — their responsibilities
 * are absorbed by the base class here.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.10
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

defined( 'ABSPATH' ) || exit;

final class EmbedsTab extends AbstractReactMountServerTab {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * {@inheritDoc}
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	// ── Identity ──────────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'embeds';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Embeds', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — between Access Control (70) and Danger Zone (100).
	 */
	public function priority(): int {
		return 90;
	}

	// ── Body chrome ───────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 */
	public function get_heading(): string {
		return __( 'Frontend Embeds', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'Control which connection methods this server exposes via the [acrossai_mcp_embed] shortcode + block on the frontend. Master toggle default OFF; per-transport toggles are additive gates on top of any Access Control rules.',
			'acrossai-mcp-manager'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_react_root_id(): string {
		return 'acrossai-mcp-embeds-root';
	}

	// ── Assets ────────────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 */
	public function get_asset_handle(): string {
		return 'acrossai-mcp-manager-embeds';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_asset_manifest_path(): string {
		return \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/embeds.asset.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_asset_script_url(): string {
		return \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/embeds.js';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_asset_style_url(): string {
		$css_path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/embeds.css';
		return file_exists( $css_path ) ? \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/embeds.css' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_localize_object_name(): string {
		return 'acrossaiMcpEmbeds';
	}

	// ── REST ──────────────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 */
	public function get_rest_route_path(): string {
		return '/servers/(?P<server_id>\d+)/embeds';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_save_request_args(): array {
		return array(
			'master' => array(
				'type'     => 'boolean',
				'required' => true,
			),
			// Nested shape per per-DTO redesign — outer keys are
			// transport_key (category), inner keys are dto_slug, values are bool:
			// { "client": { "claude-desktop": true, ... },
			// "npm":    { "npx-acrossai-mcp-manager": true },
			// "ai_connector": { "chatgpt": true, ... } }
			'items'  => array(
				'type'     => 'object',
				'required' => true,
			),
		);
	}

	// ── State contract ────────────────────────────────────────────────

	/**
	 * Assemble the server's Embeds state — master toggle + registered
	 * transport groups with per-DTO enable flags.
	 *
	 * Consumed by the REST GET + first-render bootstrap payload + the
	 * noscript fallback (via `summary_rows_from_state()`).
	 *
	 * Shape:
	 *   {
	 *     master: bool,
	 *     groups: [
	 *       { key, label, priority, dtos: [{ slug, name, icon, enabled }] },
	 *       ...
	 *     ]
	 *   }
	 *
	 * @param int $server_id Server PK.
	 * @return array<string, mixed>
	 */
	public function get_state_for_server( int $server_id ): array {
		$master_enabled = self::master_enabled_from_meta( $server_id );
		$items          = AbstractEmbedTransport::get_items_for_server( $server_id );
		$groups         = array();

		foreach ( AbstractEmbedTransport::get_all_registered_transports() as $transport ) {
			$storage_key = $transport->get_storage_key();
			$is_single   = $transport->is_single_item();
			$entry       = $items[ $storage_key ] ?? null;
			$dtos_out    = array();

			foreach ( $transport->get_dtos() as $dto ) {
				if ( ! is_array( $dto ) || empty( $dto['slug'] ) ) {
					continue;
				}
				$dto_slug   = (string) $dto['slug'];
				$dtos_out[] = array(
					'slug'    => $dto_slug,
					'name'    => isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : $dto_slug,
					'icon'    => isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '',
					'enabled' => AbstractEmbedTransport::entry_enables_slug( $entry, $dto_slug, $is_single ),
				);
			}

			$groups[] = array(
				'key'      => $transport->get_transport_key(),
				'label'    => $transport->get_checkbox_label(),
				'priority' => (int) $transport->get_priority(),
				'dtos'     => $dtos_out,
			);
		}

		return array(
			'master' => $master_enabled,
			'groups' => $groups,
		);
	}

	/**
	 * Persist submitted state. Handles the master toggle transition +
	 * per-DTO diff-based observability events, then returns the
	 * refreshed state so the REST POST response echoes it back.
	 *
	 * @param int   $server_id Server PK.
	 * @param array $submitted { master: bool, items: { transport_key: { dto_slug: bool } } }.
	 * @return array<string, mixed>
	 */
	public function set_state_for_server( int $server_id, array $submitted ): array {
		// ── Master toggle ──────────────────────────────────────────
		$new_master = ! empty( $submitted['master'] );
		$old_master = self::master_enabled_from_meta( $server_id );

		if ( $new_master !== $old_master ) {
			if ( $new_master ) {
				ServerMetaQuery::update_meta( $server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
			} else {
				ServerMetaQuery::delete_meta( $server_id, AbstractEmbedTransport::META_KEY_MASTER );
			}

			// FR-024 observability — R3 per-listener isolation. A single
			// broken listener MUST NOT abort subsequent listeners on the
			// same hook OR roll back the DB write above.
			AbstractEmbedTransport::fire_action_isolated(
				'acrossai_mcp_embed_master_toggled',
				$server_id,
				$new_master,
				get_current_user_id()
			);
		}

		// ── Per-DTO sub-toggles ────────────────────────────────────
		// Iterate registered transports × F035 DTOs (source of truth
		// — NOT the submitted array; prevents mass-assignment on
		// unknown transports).
		$items_submitted = isset( $submitted['items'] ) && is_array( $submitted['items'] )
			? $submitted['items']
			: array();
		$old_items       = AbstractEmbedTransport::get_items_for_server( $server_id );
		$new_items       = array();

		foreach ( AbstractEmbedTransport::get_all_registered_transports() as $transport ) {
			$transport_key = $transport->get_transport_key();
			$storage_key   = $transport->get_storage_key();
			$is_single     = $transport->is_single_item();
			$dtos          = $transport->get_dtos();
			$submitted_grp = isset( $items_submitted[ $transport_key ] ) && is_array( $items_submitted[ $transport_key ] )
				? $items_submitted[ $transport_key ]
				: array();

			// Build the new-state entry for this category.
			$enabled_slugs = array();
			foreach ( $dtos as $dto ) {
				if ( ! is_array( $dto ) || empty( $dto['slug'] ) ) {
					continue;
				}
				$slug = (string) $dto['slug'];
				if ( ! empty( $submitted_grp[ $slug ] ) ) {
					$enabled_slugs[] = $slug;
				}
			}
			if ( $is_single ) {
				if ( ! empty( $enabled_slugs ) ) {
					$new_items[ $storage_key ] = 1;
				}
			} elseif ( ! empty( $enabled_slugs ) ) {
				$new_items[ $storage_key ] = array_values( $enabled_slugs );
			}

			// Fire per-DTO transition events.
			foreach ( $dtos as $dto ) {
				if ( ! is_array( $dto ) || empty( $dto['slug'] ) ) {
					continue;
				}
				$dto_slug    = (string) $dto['slug'];
				$old_enabled = AbstractEmbedTransport::entry_enables_slug(
					$old_items[ $storage_key ] ?? null,
					$dto_slug,
					$is_single
				);
				$new_enabled = AbstractEmbedTransport::entry_enables_slug(
					$new_items[ $storage_key ] ?? null,
					$dto_slug,
					$is_single
				);
				if ( $new_enabled === $old_enabled ) {
					continue;
				}

				// FR-024 observability — R3 per-listener isolation.
				AbstractEmbedTransport::fire_action_isolated(
					'acrossai_mcp_embed_transport_toggled',
					$server_id,
					$transport_key,
					$dto_slug,
					$new_enabled,
					get_current_user_id()
				);
			}
		}

		// Persist the assembled JSON blob (or delete when fully empty).
		AbstractEmbedTransport::save_items_for_server( $server_id, $new_items );

		// Flush memoization so re-reads pick up the new state.
		AbstractEmbedTransport::flush_cache();

		return $this->get_state_for_server( $server_id );
	}

	// ── Noscript summary (domain-shaped override) ─────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * Overrides the base's naive flatten with a human-readable summary:
	 * master toggle status + per-transport enabled counts.
	 *
	 * @param array $state State from `get_state_for_server()`.
	 */
	public function summary_rows_from_state( array $state ): array {
		$rows = array(
			array(
				'label' => __( 'Master toggle', 'acrossai-mcp-manager' ),
				'value' => ! empty( $state['master'] )
					? __( 'Enabled', 'acrossai-mcp-manager' )
					: __( 'Disabled', 'acrossai-mcp-manager' ),
			),
		);

		$groups = isset( $state['groups'] ) && is_array( $state['groups'] ) ? $state['groups'] : array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['label'] ) ) {
				continue;
			}
			$dtos    = isset( $group['dtos'] ) && is_array( $group['dtos'] ) ? $group['dtos'] : array();
			$enabled = 0;
			foreach ( $dtos as $dto ) {
				if ( ! empty( $dto['enabled'] ) ) {
					++$enabled;
				}
			}
			$rows[] = array(
				'label' => (string) $group['label'],
				'value' => sprintf(
					/* translators: %d: enabled DTO count */
					__( '%d item(s) enabled', 'acrossai-mcp-manager' ),
					$enabled
				),
			);
		}

		return $rows;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Adds the "This tab requires JavaScript" notice to the base's
	 * empty-string default so the noscript block actually renders.
	 */
	public function get_noscript_notice(): string {
		return __(
			'This tab requires JavaScript. Enable JavaScript in your browser to edit Embeds settings. Current state is shown below (read-only).',
			'acrossai-mcp-manager'
		);
	}

	// ── Private helpers ───────────────────────────────────────────────

	/**
	 * Read the master toggle state from the meta table.
	 *
	 * @param int $server_id Server PK.
	 * @return bool
	 */
	private static function master_enabled_from_meta( int $server_id ): bool {
		$val = ServerMetaQuery::get_meta( $server_id, AbstractEmbedTransport::META_KEY_MASTER );
		return null !== $val && '1' === (string) $val;
	}
}
