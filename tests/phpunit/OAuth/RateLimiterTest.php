<?php
/**
 * RateLimiter behavior — 10 pass, 11th blocks; window boundaries; IP
 * determination with trusted-proxies filter (SEC-021-003 / FR-044).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;

/**
 * @coversNothing
 */
class RateLimiterTest extends OAuthTestCase {

	public function test_ten_requests_pass_eleventh_blocks(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$result = RateLimiter::check( 'dcr', '203.0.113.1', 10, 60 );
			$this->assertTrue( $result, sprintf( 'request %d MUST pass', $i ) );
		}

		$blocked = RateLimiter::check( 'dcr', '203.0.113.1', 10, 60 );

		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'slow_down', $blocked->get_error_code() );
		$this->assertSame( 429, $blocked->get_error_data()['status'] ?? 0 );
	}

	public function test_different_ips_have_independent_buckets(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			RateLimiter::check( 'dcr', '203.0.113.1', 10, 60 );
		}

		$other = RateLimiter::check( 'dcr', '203.0.113.2', 10, 60 );
		$this->assertTrue( $other );
	}

	public function test_client_ip_default_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.42';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 172.16.0.1';

		// No trusted proxies filter → XFF ignored.
		$this->assertSame( '198.51.100.42', RateLimiter::client_ip() );
	}

	public function test_client_ip_honours_trusted_proxy_cidr(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';  // in 10.0.0.0/8.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 10.0.0.1';

		add_filter(
			'acrossai_mcp_manager_trusted_proxies',
			static fn () => array( '10.0.0.0/8' )
		);

		// REMOTE_ADDR is trusted-proxy; rightmost non-trusted XFF is 203.0.113.99.
		$this->assertSame( '203.0.113.99', RateLimiter::client_ip() );
	}

	public function test_client_ip_ignores_xff_when_remote_addr_not_trusted(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.5';  // NOT in trusted list.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

		add_filter(
			'acrossai_mcp_manager_trusted_proxies',
			static fn () => array( '10.0.0.0/8' )
		);

		// Untrusted REMOTE_ADDR: don't touch XFF — return REMOTE_ADDR.
		$this->assertSame( '203.0.113.5', RateLimiter::client_ip() );
	}
}
