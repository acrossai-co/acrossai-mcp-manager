<?php
/**
 * Feature 030 — AbilitiesManagerPromoCard unit coverage.
 *
 * Tests the three status-row states (missing / inactive / active) via
 * ReflectionMethod on the private render_status_row helper, plus the
 * public render_code_details() `<details>` block and the full render()
 * back-compat wrapper.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials\ServerTabs\Partials
 */

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials\ServerTabs\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials\AbilitiesManagerPromoCard;
use AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor;
use ReflectionMethod;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class AbilitiesManagerPromoCardTest extends WP_UnitTestCase {

	public function test_missing_state_renders_install_cta_to_addons_page(): void {
		$html = $this->invoke_status_row( 'missing' );

		$this->assertStringContainsString( 'not installed on this site', $html );
		$this->assertStringContainsString( 'Install &amp; Activate on Add-ons page', $html );
		$this->assertStringContainsString( 'page=acrossai-addons', $html );
	}

	public function test_inactive_state_renders_activate_cta_to_addons_page(): void {
		$html = $this->invoke_status_row( 'inactive' );

		$this->assertStringContainsString( 'installed but not active', $html );
		$this->assertStringContainsString( 'Activate on Add-ons page', $html );
		$this->assertStringContainsString( 'page=acrossai-addons', $html );
	}

	public function test_active_state_renders_edit_abilities_link(): void {
		$html = $this->invoke_status_row( 'active' );

		$this->assertStringContainsString( 'installed and active', $html );
		$this->assertStringContainsString( 'Edit Abilities', $html );
		$this->assertStringContainsString( 'page=acrossai-abilities-manager', $html, 'Active state MUST link to the sibling plugin admin page.' );
	}

	public function test_render_callout_emits_headline_description_and_status_row(): void {
		ob_start();
		AbilitiesManagerPromoCard::instance()->render_callout( array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'acrossai-mcp-pom__callout', $html, 'Callout wrapper class MUST render.' );
		$this->assertStringContainsString( 'Prefer per-ability control', $html );
		$this->assertStringContainsString( 'AcrossAI Abilities Manager', $html );
		$this->assertStringContainsString( 'acrossai-mcp-pom__status-row', $html );
		// Callout MUST NOT emit the <details> block — that is a sibling call.
		$this->assertStringNotContainsString( '<details', $html );
	}

	public function test_render_code_details_documents_filter_name_and_priority(): void {
		ob_start();
		AbilitiesManagerPromoCard::instance()->render_code_details();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<details', $html, '<details> block MUST render.' );
		$this->assertStringContainsString( 'Prefer to use code', $html );
		$this->assertStringContainsString( 'wp_register_ability_args', $html, 'MUST document the WP core filter name.' );
		$this->assertStringContainsString( (string) PermissionOverrideProcessor::PRIORITY, $html, 'MUST document the F030 filter priority (999999).' );
		$this->assertStringContainsString( 'add_filter', $html, 'MUST include a code snippet showing how to hook the filter.' );
	}

	public function test_render_backcompat_wrapper_emits_callout_and_details(): void {
		ob_start();
		AbilitiesManagerPromoCard::instance()->render( array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Prefer per-ability control', $html );
		$this->assertStringContainsString( 'AcrossAI Abilities Manager', $html );
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( 'wp_register_ability_args', $html );
		$this->assertStringContainsString( (string) PermissionOverrideProcessor::PRIORITY, $html );
	}

	/**
	 * Invoke the private render_status_row() method for a given state and
	 * return its captured output.
	 */
	private function invoke_status_row( string $state ): string {
		$card   = AbilitiesManagerPromoCard::instance();
		$method = new ReflectionMethod( $card, 'render_status_row' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $card, $state );
		return (string) ob_get_clean();
	}
}
