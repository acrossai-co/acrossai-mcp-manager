<?php
/**
 * Shared no-cache defense for REST + AJAX response paths.
 *
 * Full-page caches (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache,
 * host-level FastCGI cache, generic reverse proxies) DO NOT honor arbitrary
 * `Cache-Control: no-store` response headers from the plugin. They gate their
 * caching decision on:
 *
 *   1. `define( 'DONOTCACHEPAGE', true )` — a WordPress-community convention
 *      the four major page caches all respect.
 *   2. An admin-configured exclusion list for the URL.
 *
 * `Cache-Control` response headers ARE respected by well-behaved intermediaries
 * (CDNs, load balancers, browser cache) — so we still send them.
 *
 * Verified against LiteSpeed Cache 7.8.1 source (see `src/control.cls.php`
 * `_setting_cacheable()` L845 for method exclusion and L638-641 for
 * `DONOTCACHEPAGE` handling). LSC ignores `Cache-Control: no-store` alone
 * unless DONOTCACHEPAGE is defined or the URL is in the admin `cache-exc` list.
 *
 * This helper is the companion to `acrossai-ai-connectors`'s
 * `CacheHeaders` (0.5.3 / PR #13). Same pattern, same rationale.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 * @since 0.2.1
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

final class CacheHeaders {

	/**
	 * Full no-cache defense for endpoints that emit response bytes directly
	 * (non-REST paths). Use from `template_redirect`/rewrite handlers, AJAX
	 * `wp_send_json_*` sites, and any custom HTTP response emission.
	 *
	 * Sends `Cache-Control: no-store, no-cache, must-revalidate, private` +
	 * `Pragma: no-cache`, `nocache_headers()`, and defines `DONOTCACHEPAGE`.
	 * The `no-store` directive is stronger than WordPress's default
	 * `nocache_headers()` output (which uses `no-cache`) — required for
	 * responses containing OAuth secrets, App Passwords, session tokens,
	 * per-user data, or per-request nonces.
	 *
	 * @return void
	 */
	public static function send_no_store(): void {
		self::define_donotcache_constant();
		nocache_headers();
		// Override nocache_headers() Cache-Control with the stronger no-store
		// directive required for responses carrying credentials/nonces. The
		// third arg `true` REPLACES any prior header of the same name.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private', true );
		header( 'Pragma: no-cache', true );
	}

	/**
	 * Define `DONOTCACHEPAGE` without emitting any HTTP header. Use from
	 * REST controllers where WordPress owns the header emission and we only
	 * need the page-cache exclusion signal.
	 *
	 * @return void
	 */
	public static function define_donotcache_constant(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Attach no-cache headers to a WP_REST_Response before returning it from
	 * a controller callback. Also defines `DONOTCACHEPAGE`. Use for any REST
	 * response body that varies per-request, per-user, or per-session.
	 *
	 * @param \WP_REST_Response $response
	 * @return \WP_REST_Response
	 */
	public static function apply_to_rest_response( \WP_REST_Response $response ): \WP_REST_Response {
		self::define_donotcache_constant();
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Attach explicit-public caching headers to a `WP_REST_Response` that
	 * IS meant to be edge-cached (e.g. static health/version endpoints,
	 * discovery documents). Opposite of `apply_to_rest_response()`.
	 *
	 * @param \WP_REST_Response $response
	 * @param int               $max_age Seconds. Defaults to 1 hour.
	 * @return \WP_REST_Response
	 */
	public static function apply_public_cache_to_rest_response( \WP_REST_Response $response, int $max_age = 3600 ): \WP_REST_Response {
		$response->header( 'Cache-Control', sprintf( 'public, max-age=%d', max( 0, $max_age ) ) );
		return $response;
	}
}
