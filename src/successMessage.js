'use strict';

/**
 * STEP 14 — Print the final success summary.
 *
 * @param {string} siteurl
 * @param {{ id: string, name: string }} selectedServer  Matched server from STEP 8
 * @param {Array}  selectedApps   Apps the user chose (may be empty)
 * @param {Array}  detectedApps   Full detected-apps list from STEP 9
 */
function displaySuccessMessage(siteurl, selectedServer, selectedApps, detectedApps) {
  const configId = `wordpress-${selectedServer.id}`;

  console.log('✅ Setup Complete!\n');
  console.log(`  Site:      ${siteurl}`);
  console.log(`  Server:    ${selectedServer.name} (${selectedServer.id})`);
  console.log(`  Config ID: ${configId}`);
  console.log('');

  if (selectedApps.length > 0) {
    console.log('  Configured Applications:');
    selectedApps.forEach((app) => {
      console.log(`    ✓ ${app.name}`);
    });

    const skipped = detectedApps.filter(
      (a) => !selectedApps.find((s) => s.id === a.id)
    );
    if (skipped.length > 0) {
      console.log('');
      console.log('  Not configured (skipped or not installed):');
      skipped.forEach((app) => {
        const reason = app.detected ? 'not selected' : 'not installed';
        console.log(`    ✗ ${app.name} (${reason})`);
      });
    }

    console.log('');
    console.log('  Configuration Files:');
    selectedApps.forEach((app) => {
      console.log(`    • ${app.path}`);
    });

    console.log('');
    console.log('  Next Steps:');
    selectedApps.forEach((app, i) => {
      console.log(`    ${i + 1}. Restart ${app.name}`);
    });
    console.log(`    ${selectedApps.length + 1}. Your MCP server is ready to use!`);
  } else {
    console.log('  No applications were selected for auto-configuration.');
    console.log('  Configuration shown above for manual setup.');
    console.log('');
    console.log('  Next Steps:');
    console.log('    1. Manually paste the config above');
    console.log('    2. Restart your applications');
    console.log('    3. Your MCP server is ready to use!');
  }

  console.log('');
}

module.exports = { displaySuccessMessage };
