<?php
/**
 * CLI auth log schema definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

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
	 * @return array<string, array{type:string, default:mixed, format:string}>
	 */
	public function columns(): array {
		return array(
			'id'                => array( 'type' => 'int',    'default' => 0,    'format' => '%d' ),
			'server_id'         => array( 'type' => 'int',    'default' => 0,    'format' => '%d' ),
			'server_slug'       => array( 'type' => 'string', 'default' => '',   'format' => '%s' ),
			'user_id'           => array( 'type' => 'int',    'default' => 0,    'format' => '%d' ),
			'status'            => array( 'type' => 'string', 'default' => '',   'format' => '%s' ),
			'failure_code'      => array( 'type' => 'string', 'default' => '',   'format' => '%s' ),
			'auth_code_hash'    => array( 'type' => 'string', 'default' => '',   'format' => '%s' ),
			'app_password_uuid' => array( 'type' => 'string', 'default' => '',   'format' => '%s' ),
			'approved_at'       => array( 'type' => 'string', 'default' => null, 'format' => '%s' ),
			'completed_at'      => array( 'type' => 'string', 'default' => null, 'format' => '%s' ),
			'created_at'        => array( 'type' => 'string', 'default' => null, 'format' => '%s' ),
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
