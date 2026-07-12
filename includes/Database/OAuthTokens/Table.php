<?php
/**
 * BerlinDB Table subclass for the OAuthTokens module (Feature 021).
 *
 * F011 phantom-version guard preserved. Sole storage for access + refresh
 * tokens (single table, `token_type` discriminator). SEC-021-001 family_id
 * column allows RFC 9700 §2.2.2 family-scoped revocation.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_oauth_tokens';

	/** @var string */
	protected $version = '1.0.0';

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_oauth_tokens_db_version';

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
