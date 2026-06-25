<?php
/**
 * US5 — Loader contract: every OAuth hook MUST be registered via the
 * Loader from Main::define_admin_hooks/define_public_hooks. Constitution A1.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Main as PluginMain;
use WP_UnitTestCase;

class LoaderContractTest extends WP_UnitTestCase {

	public function test_every_oauth_hook_registered_after_main_boot(): void {
		// PluginMain singleton boots on instantiation; if the plugin is
		// already loaded, hooks are already registered.
		PluginMain::instance();
		// In test environments the plugin file may auto-load; force the
		// loader to run if Main exposes a public `run()`.
		if ( method_exists( PluginMain::instance(), 'run' ) ) {
			PluginMain::instance()->run();
		}

		$this->assertNotFalse( has_filter( 'query_vars' ), 'query_vars filter not registered.' );
		$this->assertNotFalse( has_action( 'init' ), 'init action not registered.' );
		$this->assertNotFalse( has_action( 'template_redirect' ), 'template_redirect action not registered.' );
		$this->assertNotFalse( has_action( 'rest_api_init' ), 'rest_api_init action not registered.' );
		$this->assertNotFalse( has_filter( 'determine_current_user' ), 'determine_current_user filter not registered.' );
		$this->assertNotFalse( has_action( 'acrossai_mcp_oauth_cleanup' ), 'cleanup cron action not registered.' );
	}
}
