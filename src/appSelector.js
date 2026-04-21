'use strict';

const readline = require('readline');

/**
 * STEP 11 — Display detected MCP clients and let the user choose which to configure.
 *
 * Installed apps are shown with [N] ✓ and not-installed with [N] ✗.
 * The user enters comma-separated numbers, or presses Enter to skip.
 *
 * @param {Array} detectedApps  Output of detectInstalledClients()
 * @returns {Promise<Array>}    Subset of detectedApps the user selected (installed only)
 */
async function askUserWhichAppsToSetup(detectedApps) {
  console.log('\n📱 Available Applications:\n');

  // Split into installed / not-installed so installed apps get the lower indices
  const installed    = detectedApps.filter((a) => a.detected);
  const notInstalled = detectedApps.filter((a) => !a.detected);

  // Build a flat ordered list that matches the displayed [N] numbering
  const ordered = [...installed, ...notInstalled];

  installed.forEach((app, i) => {
    console.log(`  [${i + 1}] ✓ ${app.name}`);
    console.log(`      ${app.path}`);
  });

  notInstalled.forEach((app, i) => {
    console.log(`  [${installed.length + i + 1}] ✗ ${app.name} (not installed)`);
  });

  const rl = readline.createInterface({
    input:  process.stdin,
    output: process.stdout,
  });

  return new Promise((resolve) => {
    rl.question(
      '\nWhich applications would you like to configure?\n' +
      'Enter numbers separated by commas (e.g., 1,2) or press Enter to skip all:\n> ',
      (answer) => {
        rl.close();

        if (!answer.trim()) {
          console.log('');
          return resolve([]);
        }

        // Parse comma-separated indices (1-based → 0-based against ordered[])
        const indices = answer
          .split(',')
          .map((s) => parseInt(s.trim(), 10) - 1)
          .filter((i) => i >= 0 && i < ordered.length);

        // Keep only installed apps from the selection
        const selected = ordered.filter(
          (app, i) => indices.includes(i) && app.detected
        );

        if (selected.length > 0) {
          const names = selected.map((a) => a.name).join(', ');
          console.log(`\nSelected: ${names} ✓\n`);
        } else {
          console.log('');
        }

        resolve(selected);
      }
    );
  });
}

module.exports = { askUserWhichAppsToSetup };
