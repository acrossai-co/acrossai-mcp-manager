<?php
/**
 * Abstract MCP client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared contract for all MCP client definitions.
 */
abstract class AbstractMCPClient {

	/**
	 * Return the client ID used in URLs and REST payloads.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Return the display label.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Return the human-readable description.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * Return the icon shown in admin tabs.
	 *
	 * @return string
	 */
	abstract public function get_icon();

	/**
	 * Return the top-level config key for this client.
	 *
	 * @return string
	 */
	abstract public function get_top_level_key();

	/**
	 * Return the suggested config file path.
	 *
	 * @return string
	 */
	abstract public function get_config_file();

	/**
	 * Return the default server name used by this client.
	 *
	 * @return string
	 */
	public function get_server_name() {
		return 'mcp-wordpress';
	}

	/**
	 * Build the full config payload for this client.
	 *
	 * @param string $server_key Server config key.
	 * @param array  $mcp_config Inner MCP config block.
	 *
	 * @return array
	 */
	public function build_full_config( $server_key, array $mcp_config ) {
		return array(
			$this->get_top_level_key() => array(
				$server_key => $mcp_config,
			),
		);
	}

	/**
	 * Return this client as the legacy metadata array.
	 *
	 * @return array<string,string>
	 */
	public function to_array() {
		return array(
			'label'         => $this->get_label(),
			'description'   => $this->get_description(),
			'icon'          => $this->get_icon(),
			'top_level_key' => $this->get_top_level_key(),
			'config_file'   => $this->get_config_file(),
			'server_name'   => $this->get_server_name(),
		);
	}
}
