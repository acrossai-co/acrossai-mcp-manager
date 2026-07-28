<?php
/**
 * AI Connectors embed transport (Feature 037 built-in).
 *
 * Gates frontend shortcode + block output for the AI connector
 * connection-method category (Claude.ai, ChatGPT, etc. — all companion-
 * plugin contributed profiles). Transport key aligned 1:1 with F035 DTO
 * `category === 'ai_connector'`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Embeds
 * @since      0.1.10
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Embeds;

use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;

defined( 'ABSPATH' ) || exit;

final class AiConnectorEmbedTransport extends AbstractEmbedTransport {

	/**
	 * {@inheritDoc}
	 */
	public function get_transport_key(): string {
		return 'ai_connector';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_checkbox_label(): string {
		return __( 'AI Connectors', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Storage-facing alias — `_embeds_clients` JSON uses `connectors`
	 * for readability at the persisted layer.
	 */
	public function get_storage_key(): string {
		return 'connectors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dtos(): array {
		return ConnectionMethodRegistry::instance()->get_ai_connectors();
	}
}
