<?php
/**
 * FR-040 cryptographic column width invariants.
 *
 * These widths are cryptographic — narrowing them breaks the SHA-256
 * SEC invariant (char(64)) or PKCE S256 (char(43)) or SEC-021-001
 * token_family_id (char(36) UUIDv4). Test guards against any migration
 * that quietly narrows them.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Schema as ClientsSchema;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Schema as TokensSchema;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Schema as AuthCodesSchema;

/**
 * @coversNothing
 */
class ColumnWidthInvariantsTest extends OAuthTestCase {

	public function test_clients_secret_hash_is_char_64(): void {
		$this->assert_column( new ClientsSchema(), 'client_secret_hash', 'char', '64' );
	}

	public function test_clients_metadata_fingerprint_is_char_64(): void {
		$this->assert_column( new ClientsSchema(), 'metadata_fingerprint', 'char', '64' );
	}

	public function test_tokens_hash_is_char_64(): void {
		$this->assert_column( new TokensSchema(), 'token_hash', 'char', '64' );
	}

	public function test_tokens_family_id_is_char_36(): void {
		// SEC-021-001 — UUIDv4 lineage identifier invariant.
		$this->assert_column( new TokensSchema(), 'token_family_id', 'char', '36' );
	}

	public function test_auth_codes_hash_is_char_64(): void {
		$this->assert_column( new AuthCodesSchema(), 'code_hash', 'char', '64' );
	}

	public function test_auth_codes_challenge_is_char_43(): void {
		// F011 PKCE S256 invariant — DO NOT narrow.
		$this->assert_column( new AuthCodesSchema(), 'code_challenge', 'char', '43' );
	}

	/**
	 * @param \BerlinDB\Database\Kern\Schema $schema
	 * @param string                         $column_name
	 * @param string                         $expected_type
	 * @param string                         $expected_length
	 */
	private function assert_column( \BerlinDB\Database\Kern\Schema $schema, string $column_name, string $expected_type, string $expected_length ): void {
		foreach ( $schema->columns as $col ) {
			if ( isset( $col['name'] ) && $col['name'] === $column_name ) {
				$this->assertSame(
					$expected_type,
					$col['type'] ?? '',
					sprintf( 'Column %s type drift', $column_name )
				);
				$this->assertSame(
					$expected_length,
					isset( $col['length'] ) ? (string) $col['length'] : '',
					sprintf( 'FR-040 invariant violated: column %s width narrowed', $column_name )
				);
				return;
			}
		}
		$this->fail( 'Column not found: ' . $column_name );
	}
}
