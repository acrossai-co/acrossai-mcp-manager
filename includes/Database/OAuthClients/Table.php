<?php
/**
 * BerlinDB Table subclass for the OAuthClients module (Feature 021).
 *
 * F011 phantom-version guard preserved — silent per Clarification Q1.
 * DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION: extend via leading-\ FQN.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_clients';

	/** @var string */
	protected $version = '1.0.0';

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_clients_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool Per-site prefix (multisite-safe). */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Phantom-version guard (F011 SEC-011-002 preservation).
	 *
	 * If the version option exists but the physical table was manually
	 * dropped, BerlinDB's needs_upgrade() would skip the CREATE. Clear the
	 * option so the fresh install fires. SILENT per Q1.
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}
}
