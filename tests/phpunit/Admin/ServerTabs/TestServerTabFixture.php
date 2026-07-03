<?php
/**
 * Test fixture for AbstractServerTabTest — exposes protected helpers.
 *
 * Split into its own file per PHPCS Generic.Files.OneObjectStructurePerFile.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab;

/**
 * Minimal concrete AbstractServerTab subclass that exposes protected helpers
 * via public wrappers so AbstractServerTabTest can invoke them directly.
 */
final class TestServerTabFixture extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'test';
	}

	/**
	 * Returns the tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Test';
	}

	/**
	 * Renders the tab body (default form pattern via inherited helpers).
	 *
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$this->open_form( $server, 'save_test' );
		$this->nonce_field( $server );
		$this->close_form( 'Save Test' );
	}

	/**
	 * Public wrapper for open_form().
	 *
	 * @param array  $server Server row data.
	 * @param string $action Action string.
	 * @return void
	 */
	public function call_open_form( array $server, string $action ): void {
		$this->open_form( $server, $action );
	}

	/**
	 * Public wrapper for nonce_field().
	 *
	 * @param array $server Server row data.
	 * @return void
	 */
	public function call_nonce_field( array $server ): void {
		$this->nonce_field( $server );
	}

	/**
	 * Public wrapper for json_config_block().
	 *
	 * @param array  $server      Server row data.
	 * @param string $client_slug Client slug.
	 * @param array  $config      Config data.
	 * @return void
	 */
	public function call_json_config_block( array $server, string $client_slug, array $config ): void {
		$this->json_config_block( $server, $client_slug, $config );
	}

	/**
	 * Public wrapper for server_edit_url().
	 *
	 * @param array  $server Server row data.
	 * @param string $tab    Tab slug.
	 * @return string
	 */
	public function call_server_edit_url( array $server, string $tab ): string {
		return $this->server_edit_url( $server, $tab );
	}
}
