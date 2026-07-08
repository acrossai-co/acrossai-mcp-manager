<?php
/**
 * Singleton dispatch + ordered tab list for per-server-edit tabs.
 *
 * Feature 013 — every concrete tab class under admin/Partials/ServerTabs/
 * is registered here. `Settings::render_edit_page()` calls
 * `Registry::instance()->render( $tab_slug, $server )` to dispatch to the
 * correct tab body, and `Registry::instance()->visible_tabs( $server )` to
 * build the nav bar's slug→label list.
 *
 * Feature 019 — the `all_tabs()` list is now the SEED for a filter,
 * `acrossai_mcp_manager_server_tabs`, fired inside `for_server()`. Companion
 * plugins hook the filter to add, remove, reorder, or re-gate tabs on the
 * Edit MCP Server page. See `docs/extending-per-server-tabs.md` for the
 * contract and worked examples. The class hierarchy from Feature 013 is
 * SUPPLEMENTED, not superseded — built-in tabs remain the authoritative
 * source of behaviour; third-party contributions are wrapped in
 * `FilteredServerTab` and adopt the same dispatch pipeline.
 *
 * Normalization + dedup mirrors vendor `\AcrossAI_Main_Menu\Tabs::get_tabs()`
 * (extracted from `TabbedPageRenderer::resolve_tabs()` in 0.0.13 into a
 * standalone abstract in 0.0.14 —
 * `vendor/acrossai-co/main-menu/src/Tabs.php`). Feature 019 keeps a
 * plugin-local implementation rather than subclassing `Tabs` because the
 * per-server extension surface needs three primitives vendor `Tabs` does
 * not provide: (1) `render_callback` dispatch — companion plugins
 * contribute body HTML, no class instance to route through; (2) per-`$server`
 * filter context — the filter fires with `$server` as arg 2 so callbacks
 * can decide per-server; (3) throw safety on both callbacks. See the
 * planning doc's "Why not subclass vendor Tabs" note for details.
 *
 * Singleton member ordering matches F012 SettingsMenu (DEC-VENDOR-SETTINGS-
 * TAB-INTEGRATION): protected static $instance → public static instance() →
 * private __construct().
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registry — dispatches per-server-edit tab renders.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class Registry {

	/**
	 * Filter name for third-party tab contribution.
	 *
	 * Callbacks receive `( array $tabs, array $server )` and return an array
	 * of normalized entries. See `docs/extending-per-server-tabs.md`.
	 *
	 * @since 0.0.7
	 * @var string
	 */
	public const FILTER_NAME = 'acrossai_mcp_manager_server_tabs';

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var Registry|null
	 */
	protected static $instance = null;

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @since 0.0.6
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.6
	 */
	private function __construct() {}

	/**
	 * Returns the canonical ordered list of built-in tab instances.
	 *
	 * Feature 019 — this method is deliberately NOT filtered. It is the seed
	 * for `for_server()`, and it is what tests, introspection code, and
	 * administrative tooling read to get the ground-truth list of built-ins.
	 * Callers who want the effective (filter-applied) list MUST use
	 * `for_server()` or `visible_tabs()`.
	 *
	 * Post-Feature 016 the tab count is 10.
	 *
	 * @since 0.0.6
	 * @return AbstractServerTab[]
	 */
	public function all_tabs(): array {
		return array(
			new OverviewTab(),
			new NpmTab(),
			new ClientsTab(),
			new WpCliTab(),
			new ToolsTab(),
			new AbilitiesTab(),
			new AccessControlTab(),
			new McpTrackerTab(),
			new UpdateServerTab(),
			new DangerZoneTab(),
		);
	}

	/**
	 * Effective tab list for the given server — fires the third-party
	 * filter, normalizes, dedups, hydrates, sorts.
	 *
	 * Feature 019. Sole call site of `apply_filters( self::FILTER_NAME, … )`.
	 *
	 * @since 0.0.7
	 * @param array<string, mixed> $server Server row data.
	 * @return AbstractServerTab[] Sorted ascending by `->priority()`.
	 */
	public function for_server( array $server ): array {
		$seeded = $this->builtin_entries();

		/**
		 * Filter the per-server-edit tab list.
		 *
		 * @since 0.0.7
		 * @param array<int, array<string, mixed>> $tabs   Normalized entry arrays. Built-ins are marked `_builtin => true`.
		 * @param array<string, mixed>             $server Server row array (id, name, slug, registered_from, …).
		 */
		$raw = apply_filters( self::FILTER_NAME, $seeded, $server );

		if ( ! is_array( $raw ) ) {
			$raw = $seeded;
		}

		$normalized = $this->normalize_entries( $raw );
		$hydrated   = $this->hydrate( $normalized );

		usort(
			$hydrated,
			static function ( AbstractServerTab $a, AbstractServerTab $b ): int {
				return $a->priority() <=> $b->priority();
			}
		);

		return $hydrated;
	}

	/**
	 * Returns tabs filtered by their `visible_for()` check for the given
	 * server.
	 *
	 * Feature 019 — this method now delegates to `for_server()` first, so
	 * third-party tabs are included in the nav-bar output.
	 *
	 * @since 0.0.6
	 * @param array<string, mixed> $server Server row data.
	 * @return AbstractServerTab[]
	 */
	public function visible_tabs( array $server ): array {
		return array_values(
			array_filter(
				$this->for_server( $server ),
				static function ( AbstractServerTab $t ) use ( $server ): bool {
					return $t->visible_for( $server );
				}
			)
		);
	}

	/**
	 * Dispatches on tab slug; renders the matching tab. Unknown slug falls
	 * back to the first tab in `for_server()` (OverviewTab in the default
	 * built-in-only case; may be a third-party tab if the filter reorders).
	 *
	 * Feature 019 — third-party tabs are dispatchable identically to
	 * built-ins because `for_server()` returns them wrapped in
	 * `FilteredServerTab`.
	 *
	 * @since 0.0.6
	 * @param string               $tab_slug Requested tab slug.
	 * @param array<string, mixed> $server   Server row data.
	 * @return void
	 */
	public function render( string $tab_slug, array $server ): void {
		$tabs = $this->for_server( $server );
		foreach ( $tabs as $tab ) {
			if ( $tab->slug() === $tab_slug ) {
				$tab->render( $server );
				return;
			}
		}
		// Fallback — render the first tab in the effective list.
		if ( ! empty( $tabs ) ) {
			$tabs[0]->render( $server );
		}
	}

	/**
	 * Converts the built-in class list into the entry-array shape used by
	 * the filter. Marks each with `_builtin => true` so `hydrate()` can
	 * route them back to their concrete class instances instead of wrapping
	 * them in `FilteredServerTab`.
	 *
	 * @since 0.0.7
	 * @return array<int, array<string, mixed>>
	 */
	private function builtin_entries(): array {
		$entries = array();
		foreach ( $this->all_tabs() as $tab ) {
			$entries[] = array(
				'slug'             => $tab->slug(),
				'label'            => $tab->label(),
				'priority'         => $tab->priority(),
				'capability'       => 'manage_options',
				'render_callback'  => null, // built-ins render through their own class instance.
				'visible_callback' => null, // built-ins gate through their own `visible_for()`.
				'_builtin'         => true,
			);
		}
		return $entries;
	}

	/**
	 * Normalizes + dedups the filter's raw output.
	 *
	 * Mirrors vendor `\AcrossAI_Main_Menu\Tabs::get_tabs()`
	 * (`vendor/acrossai-co/main-menu/src/Tabs.php` in 0.0.14; was
	 * `TabbedPageRenderer::resolve_tabs()` in 0.0.13):
	 * - `sanitize_key()` on slug — dropped when empty.
	 * - Missing `label` OR missing/non-callable `render_callback` on a
	 *   non-built-in entry → dropped with `_doing_it_wrong` under `WP_DEBUG`.
	 * - Duplicate slug → first-registration wins; the duplicate is dropped
	 *   with `_doing_it_wrong` under `WP_DEBUG`.
	 * - `priority` coerced to int (default 100).
	 * - `capability` sanitized via `sanitize_key()`; empty → `'manage_options'`.
	 * - `visible_callback` must be callable or null; anything else → null.
	 *
	 * @since 0.0.7
	 * @param array<int, mixed> $raw Filter output.
	 * @return array<int, array<string, mixed>> Normalized entries in stable input order.
	 */
	private function normalize_entries( array $raw ): array {
		$seen       = array();
		$normalized = array();
		$index      = 0;

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$is_builtin = ! empty( $entry['_builtin'] );

			$slug = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
			if ( '' === $slug ) {
				if ( ! $is_builtin ) {
					$this->doing_it_wrong( 'entry missing slug' );
				}
				continue;
			}

			$label = isset( $entry['label'] ) ? (string) $entry['label'] : '';
			if ( '' === $label && ! $is_builtin ) {
				$this->doing_it_wrong( sprintf( 'entry "%s" missing label', $slug ) );
				continue;
			}

			$render_callback = $entry['render_callback'] ?? null;
			if ( ! $is_builtin && ! is_callable( $render_callback ) ) {
				$this->doing_it_wrong( sprintf( 'entry "%s" missing callable render_callback', $slug ) );
				continue;
			}

			if ( isset( $seen[ $slug ] ) ) {
				if ( ! $is_builtin ) {
					$this->doing_it_wrong( sprintf( 'duplicate slug "%s" — first registration wins', $slug ) );
				}
				continue;
			}
			$seen[ $slug ] = true;

			$capability = isset( $entry['capability'] ) ? sanitize_key( (string) $entry['capability'] ) : 'manage_options';
			if ( '' === $capability ) {
				$capability = 'manage_options';
			}

			$visible_callback = $entry['visible_callback'] ?? null;
			if ( null !== $visible_callback && ! is_callable( $visible_callback ) ) {
				$visible_callback = null;
			}

			$normalized[] = array(
				'slug'             => $slug,
				'label'            => $label,
				'priority'         => isset( $entry['priority'] ) ? (int) $entry['priority'] : 100,
				'capability'       => $capability,
				'render_callback'  => $render_callback,
				'visible_callback' => $visible_callback,
				'_builtin'         => $is_builtin,
				'_index'           => $index++,
			);
		}

		return $normalized;
	}

	/**
	 * Hydrates normalized entries back into `AbstractServerTab[]`.
	 *
	 * Built-in entries (`_builtin === true`) map to the concrete class
	 * instance from `all_tabs()` by slug. Third-party entries are wrapped
	 * in a `FilteredServerTab` adapter.
	 *
	 * @since 0.0.7
	 * @param array<int, array<string, mixed>> $entries Normalized entries.
	 * @return AbstractServerTab[]
	 */
	private function hydrate( array $entries ): array {
		$builtin_map = array();
		foreach ( $this->all_tabs() as $tab ) {
			$builtin_map[ $tab->slug() ] = $tab;
		}

		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['_builtin'] ) && isset( $builtin_map[ $entry['slug'] ] ) ) {
				$out[] = $builtin_map[ $entry['slug'] ];
				continue;
			}
			$out[] = new FilteredServerTab( $entry );
		}
		return $out;
	}

	/**
	 * `_doing_it_wrong()` wrapper for malformed filter entries.
	 *
	 * Only fires under `WP_DEBUG` to match vendor `TabbedPageRenderer`'s
	 * signal-in-development-only pattern. In production, malformed entries
	 * are silently dropped — the site does not surface `_doing_it_wrong`
	 * notices to end users.
	 *
	 * @since 0.0.7
	 * @param string $reason Human-readable description of the malformation.
	 * @return void
	 */
	private function doing_it_wrong( string $reason ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		_doing_it_wrong(
			esc_html( self::FILTER_NAME ),
			esc_html( $reason ),
			'0.0.7'
		);
	}
}
