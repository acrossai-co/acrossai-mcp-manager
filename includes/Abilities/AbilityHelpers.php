<?php
/**
 * Shared helpers for the plugin-owned replacements of vendor's
 * three built-in MCP abilities.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Kept as a trait (not a class of statics) so the three ability
 * callback classes stay symmetric — each one `use`s the trait,
 * calls `self::apply_exposure_filter()`, and reads like a plain
 * class with no cross-class coupling.
 */
trait AbilityHelpers {

	/**
	 * Return the ability's MCP type. Defaults to 'tool' when unset
	 * or when the value isn't one of the allowed enums.
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 */
	protected static function mcp_type( WP_Ability $ability ): string {
		$meta = $ability->get_meta();
		$type = $meta['mcp']['type'] ?? 'tool';
		if ( ! in_array( $type, array( 'tool', 'resource', 'prompt' ), true ) ) {
			return 'tool';
		}
		return (string) $type;
	}

	/**
	 * Return the ability's static `meta.mcp.public` flag.
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 */
	protected static function is_meta_public( WP_Ability $ability ): bool {
		$meta = $ability->get_meta();
		return (bool) ( $meta['mcp']['public'] ?? false );
	}

	/**
	 * Apply the plugin's per-server exposure filter with `server_id`.
	 * Default is the ability's `meta.mcp.public` flag so both narrowing
	 * and widening are single-callback operations for downstream.
	 *
	 * @param \WP_Ability $ability The ability being evaluated.
	 * @param string      $context One of 'discover' | 'get_info' | 'execute'.
	 * @return bool                Filter-resolved exposure decision.
	 */
	protected static function apply_exposure_filter( WP_Ability $ability, string $context ): bool {
		$default   = self::is_meta_public( $ability );
		$server_id = CurrentServerHolder::instance()->get_server_id();

		/**
		 * Filters whether an ability is exposed via MCP for the
		 * plugin-owned discover / get-info / execute tools.
		 *
		 * The default value is the ability's `meta.mcp.public` flag.
		 * Return `true` to expose an ability that is not statically
		 * marked public, or `false` to hide one that is. `server_id`
		 * is the DB PK of the MCP server handling the current request,
		 * or null when invoked outside an MCP request (WP-CLI, cron,
		 * direct `wp_get_ability()->execute()`).
		 *
		 * **Exposure is not authorization.** Returning true here does
		 * not bypass the ability's own `permission_callback`; the
		 * execute path still runs `WP_Ability::check_permissions()`
		 * before invoking the ability.
		 *
		 * @since 0.1.0
		 *
		 * @param bool        $is_exposed Default from `meta.mcp.public`.
		 * @param \WP_Ability $ability    The ability being checked.
		 * @param int|null    $server_id  Current MCP server DB PK, or null.
		 * @param string      $context    One of 'discover' | 'get_info' | 'execute'.
		 */
		return (bool) apply_filters(
			'acrossai_mcp_is_ability_exposed',
			$default,
			$ability,
			$server_id,
			$context
		);
	}
}
