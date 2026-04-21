'use strict';

const fs   = require('fs');
const path = require('path');

/**
 * Read a JSON config file. Returns {} if the file doesn't exist yet.
 *
 * @param {string} absolutePath
 * @returns {object}
 */
function readConfigFile(absolutePath) {
  try {
    const raw = fs.readFileSync(absolutePath, 'utf8');
    return JSON.parse(raw);
  } catch (_) {
    return {};
  }
}

/**
 * Write an object to a JSON config file, creating parent directories as needed.
 *
 * @param {string} absolutePath
 * @param {object} config
 */
function writeConfigFile(absolutePath, config) {
  fs.mkdirSync(path.dirname(absolutePath), { recursive: true });
  fs.writeFileSync(absolutePath, JSON.stringify(config, null, 2) + '\n', 'utf8');
}

/**
 * Back up an existing config file to <path>.bak.<timestamp>.
 * No-op if the file does not exist.
 *
 * @param {string} absolutePath
 */
function createConfigBackup(absolutePath) {
  if (!fs.existsSync(absolutePath)) return;

  const backupPath = `${absolutePath}.bak.${Date.now()}`;
  fs.copyFileSync(absolutePath, backupPath);
}

/**
 * Build the MCP server entry to inject into a client config.
 *
 * @param {string} serverId
 * @param {string} siteurl
 * @param {string} username
 * @param {string} appPassword
 * @returns {object}
 */
function buildServerEntry(serverId, siteurl, username, appPassword) {
  return {
    [`wordpress-${serverId}`]: {
      command: 'npx',
      args:    ['-y', '@automattic/mcp-wordpress-remote'],
      env:     {
        WP_API_URL:      siteurl,
        WP_API_USERNAME: username,
        WP_API_PASSWORD: appPassword,
      },
    },
  };
}

/**
 * Merge a new server entry into an existing config object for a given app.
 *
 * VS Code settings.json uses a nested structure: { "mcp": { "servers": {...} } }
 * All other clients use a flat key at root level (mcpServers).
 *
 * @param {object} existing    Current parsed config
 * @param {object} app         Detected app descriptor
 * @param {object} serverEntry New server entry (from buildServerEntry)
 * @returns {object}           Merged config
 */
function mergeConfig(existing, app, serverEntry) {
  if (app.configType === 'vscode') {
    // Nested: existing["mcp"]["servers"] = { ...old, ...new }
    const mcpBlock = existing['mcp'] || {};
    return {
      ...existing,
      mcp: {
        ...mcpBlock,
        servers: {
          ...(mcpBlock.servers || {}),
          ...serverEntry,
        },
      },
    };
  }

  // Flat: existing["mcpServers"] = { ...old, ...new }
  const key = app.mcpKey || 'mcpServers';
  return {
    ...existing,
    [key]: {
      ...(existing[key] || {}),
      ...serverEntry,
    },
  };
}

/**
 * STEP 12 — Auto-configure only the apps the user selected in STEP 11.
 *
 * Each app gets:
 *   1. A backup of its existing config file (if it exists)
 *   2. The new server entry merged in (non-destructively)
 *   3. The merged config written back to disk
 *
 * @param {Array}  selectedApps  Apps chosen by the user
 * @param {string} siteurl
 * @param {string} serverId      CLI --server value
 * @param {string} appPassword
 * @param {string} username
 * @returns {Promise<void>}
 */
async function autoConfigureSelectedApps(selectedApps, siteurl, serverId, appPassword, username) {
  if (selectedApps.length === 0) return;

  console.log('\nConfiguring selected applications...');

  const serverEntry = buildServerEntry(serverId, siteurl, username, appPassword);

  for (const app of selectedApps) {
    try {
      const existing = readConfigFile(app.absolutePath);
      createConfigBackup(app.absolutePath);
      const merged = mergeConfig(existing, app, serverEntry);
      writeConfigFile(app.absolutePath, merged);
      console.log(`  Configuring ${app.name}... ✓`);
    } catch (err) {
      console.log(`  Configuring ${app.name}... ✗ (${err.message})`);
    }
  }

  console.log('');
}

module.exports = {
  autoConfigureSelectedApps,
  buildServerEntry,
  readConfigFile,
};
