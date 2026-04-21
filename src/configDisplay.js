'use strict';

const { buildServerEntry } = require('./configWriter');

/**
 * STEP 13 — Print the manual JSON config and per-client paste instructions.
 *
 * @param {string} siteurl
 * @param {string} serverId     CLI --server value
 * @param {string} appPassword
 * @param {string} username
 */
function showManualConfigFallback(siteurl, serverId, appPassword, username) {
  const serverEntry = buildServerEntry(serverId, siteurl, username, appPassword);

  const manualJson = JSON.stringify(
    { mcpServers: serverEntry },
    null,
    2
  );

  console.log('─'.repeat(60));
  console.log('📋 Manual Configuration (if needed):\n');
  console.log(manualJson);
  console.log('');
  console.log('Where to paste:\n');
  console.log('  Claude Desktop:');
  console.log('    Settings → Developer → Edit Config');
  console.log('    File: ~/Library/Application Support/Claude/claude_desktop_config.json');
  console.log('');
  console.log('  VS Code:');
  console.log('    Settings → search "mcp" → edit settings.json');
  console.log('    File: ~/Library/Application Support/Code/User/settings.json');
  console.log('         (use key "mcp" > "servers" instead of "mcpServers")');
  console.log('');
  console.log('  JetBrains:');
  console.log('    Settings → Tools → MCP');
  console.log('    File: ~/Library/Application Support/JetBrains/mcp.json');
  console.log('─'.repeat(60));
  console.log('');
}

module.exports = { showManualConfigFallback };
