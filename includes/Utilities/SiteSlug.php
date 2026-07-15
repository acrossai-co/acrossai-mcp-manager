<?php
/**
 * Site-slug helper — single canonical source for the site identifier used by
 * both the CLI `/health` response and the MCP Clients admin-UI config
 * snippet's `mcpServers` key.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the current WordPress site's slug — used to namespace the
 * `mcpServers` config key so multiple installs of the plugin (e.g.
 * `acrossai.co` + `staging.acrossai.co` + a local dev site) can coexist in
 * the same `~/.claude.json` without overwriting each other's entries.
 *
 * Canonical derivation: `sanitize_title( get_bloginfo( 'name' ) )` with an
 * empty-input fallback of the literal string `wordpress` (lowercase — this
 * is a machine-readable slug value, not a proper noun reference to the
 * WordPress project). This matches:
 *  - The CLI's `siteValidator.js:50` fallback `data.site_slug || <literal>`.
 *  - The CLI's `configDisplay.js:15` / `configWriter.js` key format
 *    `${siteSlug}-${serverId}` (which reads `site_slug` from `/health`).
 *
 * Consumed by:
 *  - `Includes\REST\CliController::handle_health` — returns as `site_slug`
 *    in the REST response so the CLI can build the config key.
 *  - `Includes\MCPClients\AbstractMCPClient::derive_server_key` — prefixes
 *    the last-URL-segment key so the admin-UI snippet renders the SAME key
 *    the CLI would write. Fixes the historical mismatch where admin UI
 *    showed `mcp-adapter-default-server` but the CLI wrote
 *    `acrossai-mcp-adapter-default-server`.
 *
 * This class exposes only `static` methods — no singleton ceremony, no
 * instance state. Matches the `Utilities\AdminPageSlugs` pattern.
 */
final class SiteSlug {

	/**
	 * Fallback when `get_bloginfo( 'name' )` returns an empty / whitespace
	 * string. Matches the CLI's own fallback so the admin UI and CLI never
	 * diverge on empty-site-name installs.
	 */
	public const EMPTY_FALLBACK = 'wordpress';

	/**
	 * Return the current site's slug.
	 */
	public static function get(): string {
		if ( ! function_exists( 'get_bloginfo' ) || ! function_exists( 'sanitize_title' ) ) {
			return self::EMPTY_FALLBACK;
		}

		$slug = sanitize_title( (string) get_bloginfo( 'name' ) );

		return '' === $slug ? self::EMPTY_FALLBACK : $slug;
	}
}
