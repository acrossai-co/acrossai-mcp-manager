<?php
/**
 * MCP Clients embed transport (Feature 037 built-in).
 *
 * Gates frontend shortcode + block output for the MCP client
 * connection-method category (Claude Desktop, Cursor, VS Code, etc.).
 * Transport key aligned 1:1 with F035 DTO `category === 'client'`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Embeds
 * @since      0.1.10
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Embeds;

use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;

defined( 'ABSPATH' ) || exit;

final class ClientEmbedTransport extends AbstractEmbedTransport {

	/**
	 * {@inheritDoc}
	 */
	public function get_transport_key(): string {
		return 'client';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_checkbox_label(): string {
		return __( 'MCP Clients', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 20;
	}

	/**
	 * Storage-facing alias — `_embeds_clients` JSON uses `mcp-client`
	 * for readability at the persisted layer.
	 */
	public function get_storage_key(): string {
		return 'mcp-client';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dtos(): array {
		return ConnectionMethodRegistry::instance()->get_clients();
	}
}
