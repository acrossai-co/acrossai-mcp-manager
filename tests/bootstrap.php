<?php
/**
 * PHPUnit bootstrap for AcrossAI MCP Manager.
 *
 * Phase 4 (MCP Client Classes) deliberately bootstraps WITHOUT
 * WordPress per SC-003 — the MCPClients module is a pure service
 * layer (FR-008) and its tests prove that purity by running in a
 * WP-free environment.
 *
 * Tests for WordPress-dependent modules (Database/, Admin/Partials/,
 * etc.) will need a different bootstrap (`tests/bootstrap-wp.php`)
 * that loads wp-phpunit. That harness is a Phase 2 RT-4 follow-up,
 * not this phase's concern.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

// ABSPATH guard so any production file that has `defined('ABSPATH')||exit;`
// at its top still loads cleanly under test (it would otherwise exit).
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Composer autoloader (PSR-4 mapping: AcrossAI_MCP_Manager\Includes\* → includes/*).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
