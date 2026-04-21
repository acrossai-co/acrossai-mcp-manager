'use strict';

const axios              = require('axios');
const ora                = require('ora');
const { sleep, formatCountdown } = require('./utils');

const POLL_INTERVAL_MS = 2000;
const TIMEOUT_SECONDS  = 300; // 5 minutes

/**
 * STEP 6 — Poll the auth/status endpoint until the user approves in the browser.
 *
 * @param {string} siteurl
 * @param {string} authCode   The auth_code from STEP 4
 * @param {string} serverId   CLI --server value
 * @returns {Promise<string>} Session token (Bearer token for STEP 7)
 * @throws {Error} on timeout or permanent failure
 */
async function pollUntilApproved(siteurl, authCode, serverId) {
  const spinner    = ora('⏳ Waiting for approval in browser...').start();
  const statusUrl  = `${siteurl}/wp-json/acrossai-mcp-manager/v1/auth/status`;
  const deadline   = Date.now() + TIMEOUT_SECONDS * 1000;
  let   elapsed    = 0;

  while (Date.now() < deadline) {
    const remaining = Math.ceil((deadline - Date.now()) / 1000);
    spinner.text = `⏳ Waiting for approval in browser... (${formatCountdown(remaining)} remaining)`;

    try {
      const { data } = await axios.get(statusUrl, {
        params:  { code: authCode, server: serverId },
        timeout: 8000,
      });

      if (data.approved === true) {
        spinner.succeed('Approved!');
        return data.token;
      }
    } catch (err) {
      // 404 means the code expired
      if (err.response && err.response.status === 404) {
        spinner.fail('Auth code expired');
        throw new Error('❌ Auth code expired. Please run the command again.');
      }
      // Transient network errors — keep polling
    }

    await sleep(POLL_INTERVAL_MS);
    elapsed += POLL_INTERVAL_MS;
  }

  spinner.fail('Approval timed out');
  throw new Error(
    `❌ Timed out waiting for browser approval (${TIMEOUT_SECONDS / 60} minutes).\n` +
    '   Please run the command again and approve promptly in the browser.'
  );
}

module.exports = { pollUntilApproved };
