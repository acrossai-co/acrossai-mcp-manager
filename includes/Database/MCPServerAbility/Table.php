<?php
/**
 * BerlinDB Table subclass for the MCPServerAbility module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

defined( 'ABSPATH' ) || exit;

// NB: DO NOT `use BerlinDB\Database\Kern\Table` — the local class name here
// is also `Table`, which would collide with the import. Extend via the
// leading-`\` FQN instead (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION).

/**
 * Manages database table creation and upgrades for the MCPServerAbility module.
 *
 * Extends BerlinDB Kern Table (Feature 011 pattern). Overrides `maybe_upgrade()`
 * with the phantom-version guard from F011 — silent per Clarification Q1
 * (no error_log, no admin notice, no transient).
 *
 * @since 0.1.0
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/**
	 * Physical table name (WITHOUT wpdb prefix).
	 *
	 * @var string
	 */
	protected $name = 'acrossai_mcp_server_abilities';

	/**
	 * Table schema version used to trigger maybe_upgrade().
	 *
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * WordPress option key that tracks the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = 'acrossai_mcp_server_abilities_db_version';

	/**
	 * Schema class for this table.
	 *
	 * @var string
	 */
	protected $schema = Schema::class;

	/**
	 * Use per-site prefix ($wpdb->prefix), not the network base prefix.
	 *
	 * @var bool
	 */
	protected $global = false;

	/**
	 * Singleton instance.
	 *
	 * @var Table|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Table
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create or upgrade the table with the phantom-version guard.
	 *
	 * If the db_version_key option exists but the physical table was manually
	 * dropped, BerlinDB's needs_upgrade() would return false and skip install.
	 * Clearing the option first forces a fresh install on the next run.
	 * SILENT per Clarification Q1 — no error_log, no admin notice, no transient.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}
}
