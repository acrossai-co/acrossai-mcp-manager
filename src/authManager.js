'use strict';

const axios = require('axios');
const ora   = require('ora');

/**
 * STEP 4 — Start an auth session on the WordPress site.
 *
 * POSTs to /auth/start and receives a pending auth code + browser URL.
 *
 * @param {string} siteurl
 * @param {string} serverId  CLI --server value
 * @returns {Promise<{ auth_code: string, auth_url: string, expires_in: number }>}
 * @throws {Error}
 */
async function startAuthSession(siteurl, serverId) {
  const spinner = ora('Starting authorization...').start();

  try {
    const { data } = await axios.post(
      `${siteurl}/wp-json/acrossai-mcp-manager/v1/auth/start`,
      { server_id: serverId },
      { timeout: 10000 }
    );

    spinner.succeed('Authorization session started');
    return {
      auth_code:  data.auth_code,
      auth_url:   data.auth_url,
      expires_in: data.expires_in,
    };
  } catch (err) {
    spinner.fail('Failed to start authorization');

    if (err.response) {
      throw new Error(
        `❌ Auth start failed (HTTP ${err.response.status}): ` +
        (err.response.data && err.response.data.message
          ? err.response.data.message
          : err.message)
      );
    }

    throw new Error(`❌ Auth start failed: ${err.message}`);
  }
}

/**
 * STEP 10 — Exchange an approved auth code for a WordPress Application Password.
 *
 * @param {string} siteurl
 * @param {string} authCode   The auth_code from STEP 4
 * @param {string} serverId   CLI --server value
 * @returns {Promise<{ app_password: string, username: string }>}
 * @throws {Error}
 */
async function generateAppPassword(siteurl, authCode, serverId) {
  const spinner = ora('Generating app password...').start();

  try {
    const { data } = await axios.post(
      `${siteurl}/wp-json/acrossai-mcp-manager/v1/auth/exchange`,
      { code: authCode, server_id: serverId },
      { timeout: 15000 }
    );

    spinner.succeed('App password generated');
    return {
      app_password: data.app_password,
      username:     data.username,
    };
  } catch (err) {
    spinner.fail('Failed to generate app password');

    if (err.response) {
      throw new Error(
        `❌ Password generation failed (HTTP ${err.response.status}): ` +
        (err.response.data && err.response.data.message
          ? err.response.data.message
          : err.message)
      );
    }

    throw new Error(`❌ Password generation failed: ${err.message}`);
  }
}

module.exports = { startAuthSession, generateAppPassword };
