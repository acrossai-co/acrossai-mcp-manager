'use strict';

/**
 * STEP 5 — Open the auth URL in the user's default browser.
 *
 * Non-blocking: returns immediately after launching.
 *
 * @param {string} authUrl
 * @returns {Promise<void>}
 */
async function openBrowser(authUrl) {
  console.log('\n📱 Opening your site for login approval...');
  console.log(`   ${authUrl}\n`);

  try {
    // open is an ESM package pinned to v8 (CommonJS-compatible)
    const open = require('open');
    await open(authUrl);
  } catch (_err) {
    // Non-fatal: the URL is already printed so the user can open it manually.
    console.log('   (Could not open browser automatically — please open the URL above.)');
  }
}

module.exports = { openBrowser };
