<?php
/**
 * Utilities\SiteSlug — WP-bootstrapped tests for the site-slug helper.
 *
 * These tests DO use the WP test lib (unlike the MCPClients suite which is
 * SC-003 WP-free). Verifies the real `sanitize_title(get_bloginfo('name'))`
 * chain that the CLI's `/health` response and the admin UI's config key
 * share via `SiteSlug::get()`.
 *
 * @package AcrossAI_MCP_Manager\Tests\Utilities
 */

namespace AcrossAI_MCP_Manager\Tests\Utilities;

use AcrossAI_MCP_Manager\Includes\Utilities\SiteSlug;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class SiteSlugTest extends WP_UnitTestCase {

	private ?string $original_name = null;

	public function setUp(): void {
		parent::setUp();
		$this->original_name = get_bloginfo( 'name' );
	}

	public function tearDown(): void {
		if ( null !== $this->original_name ) {
			update_option( 'blogname', $this->original_name );
		}
		parent::tearDown();
	}

	public function test_returns_sanitized_site_name(): void {
		update_option( 'blogname', 'AcrossAI' );
		$this->assertSame( 'acrossai', SiteSlug::get() );
	}

	public function test_multi_word_site_name_becomes_kebab_case(): void {
		update_option( 'blogname', 'My Cool WordPress Site' );
		$this->assertSame( 'my-cool-wordpress-site', SiteSlug::get() );
	}

	public function test_special_characters_stripped_by_sanitize_title(): void {
		update_option( 'blogname', "Site's Name & Co." );
		$slug = SiteSlug::get();
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $slug );
		// The exact output shape depends on WP's sanitize_title implementation;
		// just guarantee the sanitized form contains lowercase-hyphenated tokens.
		$this->assertStringContainsString( 'site', $slug );
		$this->assertStringContainsString( 'name', $slug );
	}

	public function test_empty_site_name_falls_back_to_wordpress(): void {
		update_option( 'blogname', '' );
		$this->assertSame( SiteSlug::EMPTY_FALLBACK, SiteSlug::get() );
	}

	public function test_whitespace_only_site_name_falls_back_to_wordpress(): void {
		update_option( 'blogname', '   ' );
		$this->assertSame( SiteSlug::EMPTY_FALLBACK, SiteSlug::get() );
	}

	public function test_fallback_constant_matches_cli_default(): void {
		// The CLI's siteValidator.js:50 uses `data.site_slug || 'wordpress'`.
		// These two constants MUST stay in sync — an accidental change here
		// would silently drift admin UI and CLI apart on empty-site-name installs.
		$this->assertSame( 'wordpress', SiteSlug::EMPTY_FALLBACK );
	}
}
