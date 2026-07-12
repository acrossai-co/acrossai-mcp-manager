<?php
/**
 * Per-(server, connector) settings storage for F024.
 *
 * Stored as wp_options entries keyed
 *   `acrossai_mcp_connector_settings_{server_id}_{slug}` =
 *     array{ enabled: bool, require_admin_approval: bool }
 *
 * Adjacent option lists:
 *   `acrossai_mcp_connector_approved_users_{server_id}_{slug}` = array<int, int>
 *   `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}` = array<int, int>
 *
 * Reads default to `enabled=true, require_admin_approval=false` on cache miss.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Connectors
 * @since 0.1.0 (F024)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Connectors;

defined( 'ABSPATH' ) || exit;

final class ConnectorSettings {

	/**
	 * Read the settings row for (server_id, slug). Defaults on miss.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array{enabled: bool, require_admin_approval: bool}
	 */
	public static function get( int $server_id, string $slug ): array {
		$raw = get_option( self::settings_key( $server_id, $slug ), null );
		if ( ! is_array( $raw ) ) {
			return array(
				'enabled'                => true,
				'require_admin_approval' => false,
			);
		}
		return array(
			'enabled'                => isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : true,
			'require_admin_approval' => isset( $raw['require_admin_approval'] ) ? (bool) $raw['require_admin_approval'] : false,
		);
	}

	/**
	 * Save the settings row for (server_id, slug).
	 *
	 * @param int                                                  $server_id Server row id.
	 * @param string                                               $slug      Connector slug.
	 * @param array{enabled?: bool, require_admin_approval?: bool} $settings  Fields to save.
	 * @return void
	 */
	public static function save( int $server_id, string $slug, array $settings ): void {
		$current = self::get( $server_id, $slug );
		$merged  = array(
			'enabled'                => isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : $current['enabled'],
			'require_admin_approval' => isset( $settings['require_admin_approval'] ) ? (bool) $settings['require_admin_approval'] : $current['require_admin_approval'],
		);
		update_option( self::settings_key( $server_id, $slug ), $merged, false );
	}

	/**
	 * Return true iff the connector is enabled on this server.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return bool
	 */
	public static function is_enabled( int $server_id, string $slug ): bool {
		return self::get( $server_id, $slug )['enabled'];
	}

	/**
	 * List of user_ids pre-approved to authorize this connector.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array<int, int>
	 */
	public static function approved_user_ids( int $server_id, string $slug ): array {
		$raw = get_option( self::approved_key( $server_id, $slug ), array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $raw ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Add a user_id to the pre-approved list. No-op if already present.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id to approve.
	 * @return void
	 */
	public static function add_approved_user( int $server_id, string $slug, int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$list = self::approved_user_ids( $server_id, $slug );
		if ( in_array( $user_id, $list, true ) ) {
			return;
		}
		$list[] = $user_id;
		update_option( self::approved_key( $server_id, $slug ), array_values( $list ), false );
	}

	/**
	 * True iff the user is pre-approved for this connector on this server.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public static function is_user_approved( int $server_id, string $slug, int $user_id ): bool {
		return in_array( $user_id, self::approved_user_ids( $server_id, $slug ), true );
	}

	/**
	 * List of user_ids awaiting admin approval for this connector.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array<int, int>
	 */
	public static function pending_user_ids( int $server_id, string $slug ): array {
		$raw = get_option( self::pending_key( $server_id, $slug ), array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $raw ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Add a user_id to the pending list. No-op if already present or already approved.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function add_pending_user( int $server_id, string $slug, int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( self::is_user_approved( $server_id, $slug, $user_id ) ) {
			return;
		}
		$list = self::pending_user_ids( $server_id, $slug );
		if ( in_array( $user_id, $list, true ) ) {
			return;
		}
		$list[] = $user_id;
		update_option( self::pending_key( $server_id, $slug ), array_values( $list ), false );
	}

	/**
	 * Remove a user_id from the pending list.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function remove_pending_user( int $server_id, string $slug, int $user_id ): void {
		$list = self::pending_user_ids( $server_id, $slug );
		$next = array_values( array_filter( $list, static fn( int $id ): bool => $id !== $user_id ) );
		update_option( self::pending_key( $server_id, $slug ), $next, false );
	}

	/**
	 * Delete every wp_option row associated with this (server_id, slug).
	 * Called from the F024 disable-connector nuclear path.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return void
	 */
	public static function delete_all( int $server_id, string $slug ): void {
		delete_option( self::settings_key( $server_id, $slug ) );
		delete_option( self::approved_key( $server_id, $slug ) );
		delete_option( self::pending_key( $server_id, $slug ) );
	}

	/**
	 * Build the wp_options key for the settings row.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return string
	 */
	private static function settings_key( int $server_id, string $slug ): string {
		return sprintf( 'acrossai_mcp_connector_settings_%d_%s', $server_id, sanitize_key( $slug ) );
	}

	/**
	 * Build the wp_options key for the approved-users list.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return string
	 */
	private static function approved_key( int $server_id, string $slug ): string {
		return sprintf( 'acrossai_mcp_connector_approved_users_%d_%s', $server_id, sanitize_key( $slug ) );
	}

	/**
	 * Build the wp_options key for the pending-approvals list.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return string
	 */
	private static function pending_key( int $server_id, string $slug ): string {
		return sprintf( 'acrossai_mcp_connector_pending_approvals_%d_%s', $server_id, sanitize_key( $slug ) );
	}
}
