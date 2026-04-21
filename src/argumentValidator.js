'use strict';

const yargs = require('yargs/yargs');
const { hideBin } = require('yargs/helpers');

/**
 * STEP 1 — Parse and validate CLI arguments.
 *
 * Required flags:
 *   --siteurl   Base URL of the WordPress site (e.g. https://example.com)
 *   --server    MCP server slug to configure   (e.g. default-mcp-server)
 *
 * @returns {{ siteurl: string, server: string }}
 * @throws {Error} if either flag is missing
 */
function validateArguments() {
  const argv = yargs(hideBin(process.argv))
    .usage('Usage: npx @acrossai/mcp-manager --siteurl=<url> --server=<id>')
    .option('siteurl', {
      type: 'string',
      description: 'Base URL of the WordPress site',
    })
    .option('server', {
      type: 'string',
      description: 'MCP server ID/slug to configure',
    })
    .help()
    .parse();

  if (!argv.siteurl) {
    throw new Error(
      '❌ --siteurl parameter is required\n\n' +
      'Usage: npx @acrossai/mcp-manager --siteurl="https://example.com" --server="default-mcp-server"'
    );
  }

  if (!argv.server) {
    throw new Error(
      '❌ --server parameter is required\n\n' +
      'Usage: npx @acrossai/mcp-manager --siteurl="https://example.com" --server="default-mcp-server"'
    );
  }

  // Normalise: strip trailing slash
  const siteurl = argv.siteurl.replace(/\/+$/, '');
  const server  = argv.server.trim();

  return { siteurl, server };
}

module.exports = { validateArguments };
