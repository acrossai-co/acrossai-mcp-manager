'use strict';

/**
 * STEP 8 — Validate that the --server flag matches a server in the user's list.
 *
 * @param {string} serverId  CLI --server value
 * @param {Array}  servers   Server list from STEP 7
 * @returns {{ id: string, name: string, description: string }}
 * @throws {Error} with a formatted list of available servers
 */
function validateServerExists(serverId, servers) {
  const match = servers.find((s) => s.id === serverId);

  if (match) {
    return match;
  }

  const list = servers
    .map((s) => `  • ${s.id} (${s.name})`)
    .join('\n');

  throw new Error(
    `❌ Server '${serverId}' not in your available servers\n\n` +
    `Available servers:\n${list || '  (none)'}`
  );
}

module.exports = { validateServerExists };
