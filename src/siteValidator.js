'use strict';

const axios = require('axios');
const ora   = require('ora');

/**
 * STEP 2 — Validate the site URL is reachable.
 *
 * @param {string} siteurl
 * @returns {Promise<void>}
 * @throws {Error}
 */
async function validateSiteReachable(siteurl) {
  const spinner = ora('Validating site...').start();

  try {
    await axios.get(siteurl, { timeout: 10000, maxRedirects: 5 });
    spinner.succeed('Site reachable');
  } catch (err) {
    spinner.fail('Site not reachable');
    throw new Error(`❌ Cannot reach site: ${siteurl}\n   ${err.message}`);
  }
}

/**
 * STEP 3 — Validate the AcrossAI MCP Manager plugin is installed and active.
 *
 * @param {string} siteurl
 * @returns {Promise<void>}
 * @throws {Error}
 */
async function validatePluginInstalledActive(siteurl) {
  const spinner = ora('Validating plugin...').start();
  const url     = `${siteurl}/wp-json/acrossai-mcp-manager/v1/health`;

  try {
    const { data } = await axios.get(url, { timeout: 10000 });

    if (!data.plugin_installed) {
      spinner.fail('Plugin not installed');
      throw new Error('❌ AcrossAI MCP Manager plugin is not installed on this site.');
    }

    if (!data.plugin_active) {
      spinner.fail('Plugin not active');
      throw new Error('❌ AcrossAI MCP Manager plugin is installed but not active. Please activate it first.');
    }

    spinner.succeed(`Plugin active (v${data.version})`);
  } catch (err) {
    if (err.message.startsWith('❌')) throw err;

    spinner.fail('Plugin check failed');

    if (err.response && err.response.status === 404) {
      throw new Error(
        '❌ AcrossAI MCP Manager plugin is not installed on this site.\n' +
        '   Install it from: https://wordpress.org/plugins/acrossai-mcp-manager/'
      );
    }

    throw new Error(`❌ Could not verify plugin status: ${err.message}`);
  }
}

module.exports = { validateSiteReachable, validatePluginInstalledActive };
