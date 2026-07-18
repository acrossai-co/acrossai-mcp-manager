<?php
/**
 * BerlinDB Table subclass for the CliAuthLog module.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation and upgrades for the CliAuthLog module.
 *
 * Extends BerlinDB Kern Table (Feature 011 — supersedes the hand-rolled
 * dbDelta lifecycle documented in DECISIONS.md D9 + D7). Overrides
 * maybe_upgrade() with the phantom-version guard from
 * AcrossAI_Abilities_Table.php:96-101 — silent per Clarification Q1.
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/**
	 * Physical table name (WITHOUT wpdb prefix).
	 *
	 * @var string
	 */
	protected $name = 'acrossai_mcp_cli_auth_logs';

	/**
	 * Table schema version used to trigger maybe_upgrade().
	 *
	 * `1.0.1` (F029): forces reconciliation of three type-mismatched columns
	 * (`status`, `failure_code`, `app_password_uuid`) that drifted from
	 * `Schema.php` on some installs — dbDelta compares column names only, not
	 * types, so the widths stayed wrong until this version bump ran the
	 * explicit `ALTER TABLE MODIFY COLUMN` override in `maybe_upgrade()` below.
	 *
	 * @var string
	 */
	protected $version = '1.0.1';

	/**
	 * WordPress option key that tracks the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = 'acrossai_mcp_cli_auth_logs_db_version';

	/**
	 * Schema class for this table.
	 *
	 * @var string
	 */
	protected $schema = Schema::class;

	/**
	 * Use per-site prefix ($wpdb->prefix), not the network base prefix.
	 *
	 * @var bool
	 */
	protected $global = false;

	/**
	 * Singleton instance.
	 *
	 * @var Table|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Table
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create or upgrade the table with the phantom-version guard AND
	 * F029 column-type reconciliation.
	 *
	 * Phantom-version guard: if the db_version_key option exists but the
	 * physical table was manually dropped, BerlinDB's needs_upgrade() would
	 * return false and skip install. Clearing the option first forces a fresh
	 * install on the next run. SILENT per Clarification Q1 — no error_log,
	 * no admin notice, no transient.
	 *
	 * F029 column-type reconciliation: dbDelta compares column names only,
	 * not types, so pre-1.0.1 installs whose live DB widths drifted from
	 * Schema.php stayed wrong forever. Snapshot the pre-upgrade version;
	 * after parent::maybe_upgrade() has run (which brings versions in sync
	 * via dbDelta name-only diff), run explicit `ALTER TABLE MODIFY COLUMN`
	 * on the 3 drifted columns. Idempotent-safe via INFORMATION_SCHEMA
	 * width check — reruns are no-ops on already-correct installs.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}

		$prev_version = (string) get_option( $this->db_version_key, '' );

		parent::maybe_upgrade();

		if ( '' === $prev_version || version_compare( $prev_version, '1.0.1', '<' ) ) {
			self::reconcile_column_types();
		}
	}

	/**
	 * F029 — Bring `status`, `failure_code`, `app_password_uuid` to the
	 * widths declared in Schema.php. Idempotent per-column via
	 * INFORMATION_SCHEMA width comparison; only ALTERs columns whose live
	 * width differs from the target.
	 *
	 * @return void
	 */
	private static function reconcile_column_types(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_cli_auth_logs';

		$targets = array(
			'status'            => array(
				'length' => 32,
				'ddl'    => "MODIFY COLUMN `status` varchar(32) NOT NULL DEFAULT 'pending'",
			),
			'failure_code'      => array(
				'length' => 64,
				'ddl'    => "MODIFY COLUMN `failure_code` varchar(64) NOT NULL DEFAULT ''",
			),
			'app_password_uuid' => array(
				'length' => 36,
				'ddl'    => "MODIFY COLUMN `app_password_uuid` varchar(36) NOT NULL DEFAULT ''",
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-drift read; INFORMATION_SCHEMA has no caching layer.
		$current = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
				 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME IN ('status', 'failure_code', 'app_password_uuid')",
				DB_NAME,
				$table
			),
			OBJECT_K
		);

		if ( ! is_array( $current ) ) {
			return;
		}

		foreach ( $targets as $column_name => $spec ) {
			if ( ! isset( $current[ $column_name ] ) ) {
				continue;
			}
			if ( (int) $current[ $column_name ]->CHARACTER_MAXIMUM_LENGTH === $spec['length'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL with plugin-owned table name ($wpdb->prefix + hardcoded slug) + hardcoded column definitions; idempotent via width check above. $wpdb->prepare() does not support DDL identifiers.
			$wpdb->query( "ALTER TABLE `{$table}` " . $spec['ddl'] );
		}
	}
}
