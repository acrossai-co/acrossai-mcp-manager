#!/usr/bin/env node
'use strict';

const { validateArguments }            = require('../src/argumentValidator');
const { validateSiteReachable,
        validatePluginInstalledActive } = require('../src/siteValidator');
const { startAuthSession,
        generateAppPassword }           = require('../src/authManager');
const { openBrowser }                  = require('../src/browserLauncher');
const { pollUntilApproved }            = require('../src/authPoller');
const { fetchListOfAvailableServers }  = require('../src/serverLister');
const { validateServerExists }         = require('../src/serverValidator');
const { detectInstalledClients }       = require('../src/detector');
const { askUserWhichAppsToSetup }      = require('../src/appSelector');
const { autoConfigureSelectedApps }    = require('../src/configWriter');
const { showManualConfigFallback }     = require('../src/configDisplay');
const { displaySuccessMessage }        = require('../src/successMessage');

/**
 * Print an error and ensure the process exits with code 1.
 *
 * @param {Error|unknown} err
 */
function showError(err) {
  const msg = err instanceof Error ? err.message : String(err);
  console.error('\n' + msg + '\n');
}

/**
 * Main 14-step MCP setup flow.
 */
async function main() {
  try {
    // ── STEP 1: Parse & validate arguments ──────────────────────────────────
    const { siteurl, server } = validateArguments();

    // ── STEP 2: Validate site reachable ─────────────────────────────────────
    await validateSiteReachable(siteurl);

    // ── STEP 3: Validate plugin installed & active ───────────────────────────
    await validatePluginInstalledActive(siteurl);

    // ── STEP 4: Start auth session ───────────────────────────────────────────
    const { auth_code, auth_url } = await startAuthSession(siteurl, server);

    // ── STEP 5: Open browser for user to login/approve ───────────────────────
    await openBrowser(auth_url);

    // ── STEP 6: Poll until approved ──────────────────────────────────────────
    const sessionToken = await pollUntilApproved(siteurl, auth_code, server);

    // ── STEP 7: Fetch personalized server list (AFTER auth!) ─────────────────
    const servers = await fetchListOfAvailableServers(siteurl, sessionToken);

    // ── STEP 8: Validate --server exists in user's list ──────────────────────
    const selectedServer = validateServerExists(server, servers);

    // ── STEP 9: Detect installed MCP clients ─────────────────────────────────
    const detectedApps = await detectInstalledClients();

    // ── STEP 10: Generate app password ───────────────────────────────────────
    const { app_password, username } = await generateAppPassword(
      siteurl,
      auth_code,
      server
    );

    // ── STEP 11: Ask user which apps to configure ─────────────────────────────
    const selectedApps = await askUserWhichAppsToSetup(detectedApps);

    // ── STEP 12: Auto-configure only selected apps ────────────────────────────
    if (selectedApps.length > 0) {
      await autoConfigureSelectedApps(
        selectedApps,
        siteurl,
        server,
        app_password,
        username
      );
    } else {
      console.log('\nℹ️  No applications were selected for auto-configuration');
      console.log('   Manual config will be shown below\n');
    }

    // ── STEP 13: Show manual config as fallback ───────────────────────────────
    showManualConfigFallback(siteurl, server, app_password, username);

    // ── STEP 14: Display success message ─────────────────────────────────────
    displaySuccessMessage(siteurl, selectedServer, selectedApps, detectedApps);

  } catch (err) {
    showError(err);
    process.exit(1);
  }
}

main();
