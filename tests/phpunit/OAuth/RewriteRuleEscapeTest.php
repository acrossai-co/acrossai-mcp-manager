<?php
/**
 * R1 / B4 mitigation gate — the .well-known PCRE rewrite rules MUST have
 * a literal `\.well-known` (escaped dot) in the registered pattern.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use WP_UnitTestCase;

class RewriteRuleEscapeTest extends WP_UnitTestCase {

	public function test_well_known_rewrite_rules_use_escaped_dot(): void {
		ClaudeConnectors::instance()->register_rewrite_rules();
		flush_rewrite_rules();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules, 'rewrite_rules option missing — flush_rewrite_rules did not persist.' );

		$found_as = false;
		$found_rs = false;
		foreach ( array_keys( $rules ) as $pattern ) {
			$pattern = (string) $pattern;
			if ( str_contains( $pattern, '\\.well-known/oauth-authorization-server' ) ) {
				$found_as = true;
			}
			if ( str_contains( $pattern, '\\.well-known/oauth-protected-resource' ) ) {
				$found_rs = true;
			}
			if ( str_contains( $pattern, '.well-known/' ) && ! str_contains( $pattern, '\\.well-known/' ) ) {
				$this->fail( 'Unescaped dot found in rewrite rule: ' . $pattern );
			}
		}

		$this->assertTrue( $found_as, 'AS metadata rewrite rule not registered.' );
		$this->assertTrue( $found_rs, 'RS metadata rewrite rule not registered.' );
	}

	public function test_authorize_rewrite_rule_registered(): void {
		ClaudeConnectors::instance()->register_rewrite_rules();
		flush_rewrite_rules();

		$rules = (array) get_option( 'rewrite_rules' );
		$found = false;
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( str_contains( (string) $pattern, 'acrossai-mcp-oauth' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Authorize endpoint rewrite rule not registered.' );
	}
}
