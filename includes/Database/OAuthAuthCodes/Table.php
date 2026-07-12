<?php
/**
 * BerlinDB Table subclass for the OAuthAuthCodes module (Feature 021).
 *
 * F011 phantom-version guard preserved.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAuthCodes
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_auth_codes';

	/** @var string */
	protected $version = '1.0.0';

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_auth_codes_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool */
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
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}
}
