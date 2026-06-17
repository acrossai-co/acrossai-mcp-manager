<?php
/**
 * MCP Server schema definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

class Schema {

	protected static $_instance = null;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {}

	/**
	 * Column metadata: name => [type, default, wpdb_format].
	 * type: 'int' | 'string'. wpdb_format: '%d' | '%s'.
	 *
	 * @return array<string, array{type:string, default:mixed, format:string}>
	 */
	public function columns(): array {
		return array(
			'id'                             => array( 'type' => 'int',    'default' => 0,       'format' => '%d' ),
			'server_name'                    => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'server_slug'                    => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'description'                    => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'is_enabled'                     => array( 'type' => 'int',    'default' => 0,       'format' => '%d' ),
			'registered_from'                => array( 'type' => 'string', 'default' => 'plugin','format' => '%s' ),
			'server_route_namespace'         => array( 'type' => 'string', 'default' => 'mcp',   'format' => '%s' ),
			'server_route'                   => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'server_version'                 => array( 'type' => 'string', 'default' => 'v1.0.0','format' => '%s' ),
			'claude_connector_client_id'     => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'claude_connector_client_secret' => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'claude_connector_redirect_uri'  => array( 'type' => 'string', 'default' => '',      'format' => '%s' ),
			'created_at'                     => array( 'type' => 'string', 'default' => null,    'format' => '%s' ),
		);
	}

	public function column_names(): array {
		return array_keys( $this->columns() );
	}

	public function has_column( string $name ): bool {
		return array_key_exists( $name, $this->columns() );
	}

	public function format_for( string $name ): string {
		$cols = $this->columns();
		return $cols[ $name ]['format'] ?? '%s';
	}
}
