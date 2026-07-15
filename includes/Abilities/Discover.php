<?php
/**
 * Plugin-owned replacement callbacks for `mcp-adapter/discover-abilities`.
 *
 * Bound to the ability at registration time via
 * `CallbackReplacer::replace_callbacks()` on `wp_register_ability_args`.
 * Reimplements vendor's iteration so the exposure filter can both
 * widen (add non-public abilities) and narrow (hide public ones) —
 * a post-hoc filter can only narrow.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned callbacks for the `mcp-adapter/discover-abilities` tool.
 *
 * @since 0.1.0
 */
final class Discover {
	use AbilityHelpers;

	/**
	 * Bound as `permission_callback` on `mcp-adapter/discover-abilities`.
	 *
	 * Vendor's original required auth + `mcp_adapter_discover_abilities_capability`
	 * (default 'read'). We preserve both — mirroring the vendor's own
	 * validate_user_access flow — because the tool-level capability
	 * gate is orthogonal to the per-ability exposure filter (see the
	 * "exposure ≠ authorization" security invariant).
	 *
	 * @param mixed $input Unused — discover takes no arguments.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		unset( $input );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'authentication_required',
				__( 'User must be authenticated to access this ability.', 'acrossai-mcp-manager' )
			);
		}

		/** This filter is fired by vendor too — reuse the same name for BC. */
		$required_capability = apply_filters( 'mcp_adapter_discover_abilities_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- capability resolved via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: %s: capability slug required by the tool */
					__( 'User lacks required capability: %s', 'acrossai-mcp-manager' ),
					$required_capability
				)
			);
		}

		return true;
	}

	/**
	 * Bound as `execute_callback` on `mcp-adapter/discover-abilities`.
	 *
	 * @param mixed $input Unused.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		unset( $input );

		$abilities    = function_exists( 'wp_get_abilities' ) ? \wp_get_abilities() : array();
		$ability_list = array();

		foreach ( $abilities as $ability ) {
			// Only surface tool-typed abilities — matches vendor's semantics.
			if ( 'tool' !== self::mcp_type( $ability ) ) {
				continue;
			}
			if ( ! self::apply_exposure_filter( $ability, 'discover' ) ) {
				continue;
			}

			$ability_list[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
			);
		}

		return array( 'abilities' => $ability_list );
	}
}
