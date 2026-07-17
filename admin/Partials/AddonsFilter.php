<?php
/**
 * Removes this plugin's own entry from the `acrossai_addons` list — an
 * already-active plugin should not advertise itself as an installable add-on
 * on the AcrossAI Add-ons page rendered by the acrossai-co/main-menu vendor.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

/**
 * Constitution: singleton + private __construct + zero add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 */
final class AddonsFilter {

	/** @var AddonsFilter|null */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Filter callback for `acrossai_addons`.
	 *
	 * @param mixed $addons Filter input — expected to be an array of add-on entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function remove_self( $addons ): array {
		if ( ! is_array( $addons ) ) {
			return array();
		}

		$own_slug = defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG' )
			? (string) \ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG
			: 'acrossai-mcp-manager';

		return array_values(
			array_filter(
				$addons,
				static function ( $addon ) use ( $own_slug ): bool {
					if ( ! is_array( $addon ) ) {
						return false;
					}
					return ( $addon['slug'] ?? '' ) !== $own_slug;
				}
			)
		);
	}
}
