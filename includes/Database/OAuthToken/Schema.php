<?php
/**
 * OAuth access token schema definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

class Schema {

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
	 * Private — use ::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Column metadata for the access-tokens table.
	 *
	 * @return array<string, array{type:string, default:mixed, format:string}>
	 */
	public function columns(): array {
		return array(
			'id'                  => array(
				'type'    => 'int',
				'default' => 0,
				'format'  => '%d',
			),
			'access_token_hash'   => array(
				'type'    => 'string',
				'default' => '',
				'format'  => '%s',
			),
			'server_id'           => array(
				'type'    => 'int',
				'default' => 0,
				'format'  => '%d',
			),
			'user_id'             => array(
				'type'    => 'int',
				'default' => 0,
				'format'  => '%d',
			),
			'issued_from_code_id' => array(
				'type'    => 'int',
				'default' => 0,
				'format'  => '%d',
			),
			'scope'               => array(
				'type'    => 'string',
				'default' => 'mcp',
				'format'  => '%s',
			),
			'created_at'          => array(
				'type'    => 'string',
				'default' => null,
				'format'  => '%s',
			),
			'expires_at'          => array(
				'type'    => 'string',
				'default' => '',
				'format'  => '%s',
			),
			'revoked_at'          => array(
				'type'    => 'string',
				'default' => null,
				'format'  => '%s',
			),
		);
	}

	/**
	 * Ordered list of column names.
	 *
	 * @return string[]
	 */
	public function column_names(): array {
		return array_keys( $this->columns() );
	}

	/**
	 * Whether the given column name is declared in the schema.
	 *
	 * @param string $name Candidate column name.
	 */
	public function has_column( string $name ): bool {
		return array_key_exists( $name, $this->columns() );
	}

	/**
	 * Wpdb format specifier for the given column (`%d` or `%s`).
	 *
	 * @param string $name Column name.
	 */
	public function format_for( string $name ): string {
		$cols = $this->columns();
		return $cols[ $name ]['format'] ?? '%s';
	}
}
