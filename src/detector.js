'use strict';

const fs   = require('fs');
const path = require('path');
const os   = require('os');
const ora  = require('ora');
const { expandHome } = require('./utils');

/**
 * Platform-specific config paths for each MCP client.
 *
 * Keys: 'darwin' | 'linux' | 'win32'
 */
const CLIENT_PATHS = {
  'claude': {
    name: 'Claude Desktop',
    configType: 'claude',
    mcpKey: 'mcpServers',
    paths: {
      darwin:  '~/Library/Application Support/Claude/claude_desktop_config.json',
      linux:   '~/.config/Claude/claude_desktop_config.json',
      win32:   '%APPDATA%\\Claude\\claude_desktop_config.json',
    },
    detectDir: {
      darwin:  '~/Library/Application Support/Claude',
      linux:   '~/.config/Claude',
      win32:   '%APPDATA%\\Claude',
    },
  },
  'vscode': {
    name: 'VS Code',
    configType: 'vscode',
    mcpKey: 'mcp',        // nested: { "mcp": { "servers": { ... } } }
    mcpSubKey: 'servers',
    paths: {
      darwin:  '~/Library/Application Support/Code/User/settings.json',
      linux:   '~/.config/Code/User/settings.json',
      win32:   '%APPDATA%\\Code\\User\\settings.json',
    },
    detectDir: {
      darwin:  '~/Library/Application Support/Code',
      linux:   '~/.config/Code',
      win32:   '%APPDATA%\\Code',
    },
  },
  'jetbrains': {
    name: 'JetBrains IDEs',
    configType: 'jetbrains',
    mcpKey: 'mcpServers',
    paths: {
      darwin:  '~/Library/Application Support/JetBrains/mcp.json',
      linux:   '~/.config/JetBrains/mcp.json',
      win32:   '%APPDATA%\\JetBrains\\mcp.json',
    },
    detectDir: {
      darwin:  '~/Library/Application Support/JetBrains',
      linux:   '~/.config/JetBrains',
      win32:   '%APPDATA%\\JetBrains',
    },
  },
};

/**
 * Expand %APPDATA% on Windows.
 *
 * @param {string} p
 * @returns {string}
 */
function expandEnv(p) {
  return p.replace(/%([^%]+)%/g, (_, key) => process.env[key] || '');
}

/**
 * Resolve a raw path string to an absolute path for the current platform.
 *
 * @param {string} raw
 * @returns {string}
 */
function resolvePath(raw) {
  let p = raw;
  if (process.platform === 'win32') {
    p = expandEnv(p);
  }
  return expandHome(p);
}

/**
 * Return true if the given directory exists.
 *
 * @param {string} rawDir
 * @returns {boolean}
 */
function dirExists(rawDir) {
  try {
    return fs.statSync(resolvePath(rawDir)).isDirectory();
  } catch (_) {
    return false;
  }
}

/**
 * STEP 9 — Detect which MCP clients are installed on this machine.
 *
 * Detection is based on the presence of the application's config directory;
 * the config file itself does not need to exist yet.
 *
 * @returns {Promise<Array<{
 *   id: string,
 *   name: string,
 *   path: string,
 *   absolutePath: string,
 *   detected: boolean,
 *   configType: string,
 *   mcpKey: string,
 *   mcpSubKey?: string,
 * }>>}
 */
async function detectInstalledClients() {
  const spinner  = ora('Detecting installed applications...').start();
  const platform = process.platform; // 'darwin' | 'linux' | 'win32'
  const results  = [];

  for (const [id, client] of Object.entries(CLIENT_PATHS)) {
    const rawPath    = client.paths[platform]    || client.paths['linux'];
    const rawDir     = client.detectDir[platform] || client.detectDir['linux'];
    const absPath    = resolvePath(rawPath);
    const detected   = dirExists(rawDir);
    const displayPath = rawPath; // keep ~ for display

    const entry = {
      id,
      name:         client.name,
      path:         displayPath,
      absolutePath: absPath,
      detected,
      configType:   client.configType,
      mcpKey:       client.mcpKey,
    };

    if (client.mcpSubKey) {
      entry.mcpSubKey = client.mcpSubKey;
    }

    results.push(entry);
  }

  const detectedCount = results.filter((r) => r.detected).length;
  spinner.succeed(`Detected ${detectedCount} application(s)`);

  return results;
}

module.exports = { detectInstalledClients };
