<?php
/**
 * MCP Controller class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCP
 */

namespace ACROSSAI_MCP_MANAGER\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Manages the MCP Adapter lifecycle.
 *
 * Reads enabled servers from the DB on every init hook and boots
 * the \WP\MCP\Plugin adapter singleton when at least one server is active.
 *
 * Status values
 * -------------
 *   'running'   — adapter initialised successfully
 *   'disabled'  — no enabled server rows in the DB
 *   'not-found' — \WP\MCP\Plugin class not available
 *   'error'     — exception thrown during adapter init
 *   'unknown'   — initialize_adapter() not yet called
 *
 * @since 1.0.0
 */
class Controller {

	/**
	 * Adapter status.
	 *
	 * @var string|null
	 */
	private $adapter_status = null;

	/**
	 * Constructor — registers the init hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initialize_adapter' ), 1 );
	}

	/**
	 * Boot the MCP Adapter when at least one server is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function initialize_adapter() {
		if ( ! MCPServerTable::has_any_enabled() ) {
			$this->adapter_status = 'disabled';
			return;
		}

		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->adapter_status = 'not-found';
			return;
		}

		try {
			\WP\MCP\Plugin::instance();
			$this->adapter_status = 'running';
		} catch ( \Exception $e ) {
			$this->adapter_status = 'error';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action( 'acrossai_mcp_manager_adapter_init_error', $e );
			}
		}
	}

	/**
	 * Return the current adapter status string.
	 *
	 * Calls initialize_adapter() if it hasn't run yet.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_adapter_status() {
		if ( null === $this->adapter_status ) {
			$this->initialize_adapter();
		}

		return $this->adapter_status ?? 'unknown';
	}
}
