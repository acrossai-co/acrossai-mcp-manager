'use strict';

const os   = require('os');
const path = require('path');

/**
 * Expand a leading ~ to the user's home directory.
 *
 * @param {string} filePath
 * @returns {string}
 */
function expandHome(filePath) {
  if (filePath.startsWith('~')) {
    return path.join(os.homedir(), filePath.slice(1));
  }
  return filePath;
}

/**
 * Convert a server name to a URL-safe slug.
 * Mirrors WordPress sanitize_title() for ASCII input.
 *
 * @param {string} name  e.g. "Default MCP Server"
 * @returns {string}     e.g. "default-mcp-server"
 */
function serverSlug(name) {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

/**
 * Sleep for the given number of milliseconds.
 *
 * @param {number} ms
 * @returns {Promise<void>}
 */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Format seconds as "Xm Ys".
 *
 * @param {number} seconds
 * @returns {string}
 */
function formatCountdown(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

module.exports = { expandHome, serverSlug, sleep, formatCountdown };
