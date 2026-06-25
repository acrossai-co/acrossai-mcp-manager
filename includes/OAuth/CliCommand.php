<?php
/**
 * WP-CLI command for OAuth maintenance — fallback for hosts that disable WP-Cron.
 *
 * Usage:
 *   wp acrossai-mcp oauth cleanup
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class CliCommand {

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
	/**
	 * Run the OAuth cleanup sweep immediately. Idempotent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acrossai-mcp oauth cleanup
	 *
	 * @param array<int, string>    $args       Positional WP-CLI args (WP-CLI dispatch signature).
	 * @param array<string, string> $assoc_args Associative WP-CLI args (WP-CLI dispatch signature).
	 */
	public function cleanup( array $args = array(), array $assoc_args = array() ): void {
		$counts = Storage::instance()->cleanup_oauth_data();
		AuditLog::instance()->write(
			AuditLog::EVENT_CLEANUP_RUN,
			array( 'details' => $counts )
		);
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::success(
				sprintf(
					'OAuth cleanup complete — codes: %d, tokens: %d, audit: %d.',
					$counts['rows_deleted_codes'],
					$counts['rows_deleted_tokens'],
					$counts['rows_deleted_audit']
				)
			);
		}
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Static registration helper — called from Main.php boot when WP_CLI is defined.
	 */
	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && \WP_CLI ) ) {
			return;
		}
		\WP_CLI::add_command( 'acrossai-mcp oauth', new self() );
	}
}
