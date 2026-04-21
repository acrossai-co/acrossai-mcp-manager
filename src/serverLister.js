'use strict';

const axios = require('axios');
const ora   = require('ora');

/**
 * STEP 7 — Fetch the personalized server list for the authenticated user.
 *
 * Requires a valid session token (Bearer) obtained from STEP 6.
 *
 * @param {string} siteurl
 * @param {string} sessionToken  Bearer token from pollUntilApproved()
 * @returns {Promise<Array<{ id: string, name: string, description: string, enabled: boolean }>>}
 * @throws {Error}
 */
async function fetchListOfAvailableServers(siteurl, sessionToken) {
  const spinner = ora('Fetching available servers...').start();

  try {
    const { data } = await axios.get(
      `${siteurl}/wp-json/acrossai-mcp-manager/v1/servers`,
      {
        headers: { Authorization: `Bearer ${sessionToken}` },
        timeout: 10000,
      }
    );

    spinner.succeed(`Found ${data.servers.length} server(s)`);
    return data.servers;
  } catch (err) {
    spinner.fail('Failed to fetch server list');

    if (err.response && err.response.status === 401) {
      throw new Error('❌ Session token invalid or expired. Please run the command again.');
    }

    throw new Error(`❌ Could not fetch server list: ${err.message}`);
  }
}

module.exports = { fetchListOfAvailableServers };
