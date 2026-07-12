<?php
/**
 * OAuth rewrite-rule router (Feature 021).
 *
 * Registers rewrite rules for the four domain-root OAuth endpoints and
 * dispatches parse_request to the appropriate Controller. Follows the
 * F007 FrontendAuth pattern — all wiring lives in Main.php per Principle A1;
 * this class only owns rule shape + dispatch.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class OAuthRouter {

	private const QUERY_VAR = 'acrossai_mcp_oauth';

	/** @var OAuthRouter|null */
	private static $instance = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the four rewrite rules. Wired by Main.php on `init`.
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . self::QUERY_VAR . '=as-metadata',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . self::QUERY_VAR . '=pr-metadata',
			'top'
		);
		add_rewrite_rule(
			'^authorize/?$',
			'index.php?' . self::QUERY_VAR . '=authorize',
			'top'
		);
		add_rewrite_rule(
			'^token/?$',
			'index.php?' . self::QUERY_VAR . '=token',
			'top'
		);
	}

	/**
	 * Whitelist the query var. Wired by Main.php on `query_vars` filter.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Dispatcher for the WP `parse_request` action.
	 *
	 * Wired by Main.php on `parse_request`. Reads the OAuth query var and
	 * delegates to the appropriate controller. `exit`s on match.
	 *
	 * @param \WP $wp WP object holding query_vars.
	 * @return void
	 */
	public function parse_request( \WP $wp ): void {
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$route = (string) $wp->query_vars[ self::QUERY_VAR ];

		switch ( $route ) {
			case 'as-metadata':
				DiscoveryController::instance()->render_authorization_server_metadata();
				break;
			case 'pr-metadata':
				DiscoveryController::instance()->render_protected_resource_metadata();
				break;
			case 'authorize':
				if ( 'POST' === self::request_method() ) {
					AuthorizationController::instance()->handle_post();
				} else {
					AuthorizationController::instance()->handle_get();
				}
				break;
			case 'token':
				TokenController::instance()->handle();
				break;
			default:
				// Unknown route via our query var: 404 out.
				status_header( 404 );
				exit;
		}
	}

	/**
	 * @return string HTTP method (uppercased).
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
			: 'GET';
	}
}
