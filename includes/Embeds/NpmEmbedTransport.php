<?php
/**
 * NPM Methods embed transport (Feature 037 built-in).
 *
 * Gates frontend shortcode + block output for the NPM connection-method
 * category. Transport key aligned 1:1 with F035 DTO `category === 'npm'`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Embeds
 * @since      0.1.10
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Embeds;

use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;

defined( 'ABSPATH' ) || exit;

final class NpmEmbedTransport extends AbstractEmbedTransport {

	/**
	 * {@inheritDoc}
	 */
	public function get_transport_key(): string {
		return 'npm';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_checkbox_label(): string {
		return __( 'NPM Methods', 'acrossai-mcp-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * NPM is a single-item category (npx bridge only) — the storage entry
	 * is an int (0/1) instead of an array of enabled slugs.
	 */
	public function is_single_item(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dtos(): array {
		return ConnectionMethodRegistry::instance()->get_npm_methods();
	}
}
