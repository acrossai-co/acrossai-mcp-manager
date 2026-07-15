<?php
/**
 * Callback-swap layer for vendor's three built-in MCP abilities.
 *
 * Hooks the WP core `wp_register_ability_args` filter (fires inside
 * `WP_Abilities_Registry::register()` — see `wp-includes/abilities-api/
 * class-wp-abilities-registry.php:129`) and, when the ability being
 * registered is one of vendor's three defaults, rebinds
 * `execute_callback` + `permission_callback` to plugin-owned classes.
 *
 * The ability schemas, labels, descriptions, categories, and
 * annotations remain vendor-supplied.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton per A11. Public methods are hook callbacks;
 * wire in `Includes\Main::define_admin_hooks()`.
 *
 * @since 0.1.0
 */
final class CallbackReplacer {

	/**
	 * Map of vendor ability slug → [ callback_class, permission_method, execute_method ].
	 */
	private const VENDOR_ABILITIES = array(
		'mcp-adapter/discover-abilities' => array( Discover::class, 'check_permission', 'execute' ),
		'mcp-adapter/get-ability-info'   => array( GetAbilityInfo::class, 'check_permission', 'execute' ),
		'mcp-adapter/execute-ability'    => array( Execute::class, 'check_permission', 'execute' ),
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
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
	 * Private — use ::instance(). Hook wiring lives in Main.php per A1.
	 */
	private function __construct() {}

	/**
	 * Callback for `wp_register_ability_args`.
	 *
	 * @param array<string, mixed> $args The ability registration args.
	 * @param string               $name The ability slug being registered.
	 * @return array<string, mixed>
	 */
	public function replace_callbacks( array $args, string $name ): array {
		if ( ! isset( self::VENDOR_ABILITIES[ $name ] ) ) {
			return $args;
		}

		[ $class, $permission_method, $execute_method ] = self::VENDOR_ABILITIES[ $name ];

		$args['permission_callback'] = array( $class, $permission_method );
		$args['execute_callback']    = array( $class, $execute_method );

		return $args;
	}
}
