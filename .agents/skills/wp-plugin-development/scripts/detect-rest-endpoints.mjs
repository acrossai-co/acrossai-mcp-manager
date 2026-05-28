/**
 * detect-rest-endpoints.mjs
 *
 * Scans WordPress plugin PHP files for register_rest_route() calls and
 * reports endpoint metadata as JSON. Warns and exits 1 if any route is
 * missing a permission_callback.
 *
 * Usage:
 *   node detect-rest-endpoints.mjs [--dir=<plugin-root>]
 *
 * Output:
 *   Prints a JSON array to stdout, then WARN lines for insecure routes.
 *   Exits 1 if any route is missing permission_callback, else exits 0.
 */

import fs from "node:fs";
import path from "node:path";

// ---------------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------------
const args = process.argv.slice(2);
let pluginDir = process.cwd();
for (const arg of args) {
  const m = arg.match(/^--dir=(.+)$/);
  if (m) pluginDir = path.resolve(m[1]);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function readFile(filePath) {
  try {
    return fs.readFileSync(filePath, "utf8");
  } catch {
    return null;
  }
}

function collectPhpFiles(dir, excluded = ["vendor", "node_modules"]) {
  const results = [];
  let entries;
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return results;
  }
  for (const entry of entries) {
    if (excluded.includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...collectPhpFiles(full, excluded));
    } else if (entry.isFile() && entry.name.endsWith(".php")) {
      results.push(full);
    }
  }
  return results;
}

function lineNumber(text, index) {
  return text.slice(0, index).split("\n").length;
}

/**
 * Extract the full argument block of a function call starting AFTER the
 * opening parenthesis at `startIndex`.
 * Tracks bracket depth to handle nested arrays/closures.
 * Returns { args: string, endIndex: number }.
 */
function extractCallArgs(content, startIndex) {
  let depth = 1;
  let i = startIndex;
  let block = "";
  let inString = false;
  let stringChar = "";

  while (i < content.length && depth > 0) {
    const ch = content[i];

    if (inString) {
      if (ch === "\\" && stringChar !== "`") {
        block += ch + (content[i + 1] ?? "");
        i += 2;
        continue;
      }
      if (ch === stringChar) inString = false;
      block += ch;
      i++;
      continue;
    }

    if (ch === '"' || ch === "'") {
      inString = true;
      stringChar = ch;
      block += ch;
      i++;
      continue;
    }

    if (ch === "(") depth++;
    if (ch === ")") {
      depth--;
      if (depth === 0) break;
    }
    block += ch;
    i++;
  }

  return { args: block, endIndex: i };
}

/**
 * Split a raw PHP argument string on top-level commas.
 */
function splitTopLevelArgs(argsStr) {
  const parts = [];
  let depth = 0;
  let inString = false;
  let stringChar = "";
  let current = "";

  for (let i = 0; i < argsStr.length; i++) {
    const ch = argsStr[i];

    if (inString) {
      if (ch === "\\" && stringChar !== "`") {
        current += ch + (argsStr[i + 1] ?? "");
        i++;
        continue;
      }
      if (ch === stringChar) inString = false;
      current += ch;
      continue;
    }

    if (ch === '"' || ch === "'") {
      inString = true;
      stringChar = ch;
      current += ch;
      continue;
    }

    if (ch === "(" || ch === "[" || ch === "{") { depth++; current += ch; continue; }
    if (ch === ")" || ch === "]" || ch === "}") { depth--; current += ch; continue; }

    if (ch === "," && depth === 0) {
      parts.push(current.trim());
      current = "";
      continue;
    }

    current += ch;
  }

  if (current.trim()) parts.push(current.trim());
  return parts;
}

/**
 * Extract a string literal value from a PHP expression.
 * Returns the string contents without quotes, or the raw expression if
 * not a simple string literal.
 */
function extractStringValue(expr) {
  const s = expr.trim();
  const m = s.match(/^['"](.*)['"]$/s);
  return m ? m[1] : s;
}

/**
 * Extract HTTP methods from the block.
 * Looks for 'methods' => 'GET'|WP_REST_Server::READABLE|etc.
 */
function extractMethods(block) {
  // Look for 'methods' => 'VALUE' or 'methods' => WP_REST_Server::CONSTANT
  const methodsMatch = block.match(
    /['"](methods)['"]\s*=>\s*(?:['"]([^'"]+)['"]|WP_REST_Server::(\w+))/
  );
  if (!methodsMatch) return "unknown";

  if (methodsMatch[2]) return methodsMatch[2]; // String literal e.g. 'GET'

  // Map WP_REST_Server constants to HTTP methods
  const constantMap = {
    READABLE: "GET",
    CREATABLE: "POST",
    EDITABLE: "POST, PUT, PATCH",
    DELETABLE: "DELETE",
    ALLMETHODS: "GET, POST, PUT, PATCH, DELETE",
  };
  return constantMap[methodsMatch[3]] ?? matchesMatch[3];
}

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------

/**
 * @typedef {{
 *   namespace: string,
 *   route: string,
 *   methods: string,
 *   has_permission_callback: boolean,
 *   file: string,
 *   line: number
 * }} EndpointInfo
 */

/** @type {EndpointInfo[]} */
const endpoints = [];

const phpFiles = collectPhpFiles(pluginDir);

// Match register_rest_route( calls
const routePattern = /\bregister_rest_route\s*\(/g;

for (const file of phpFiles) {
  const content = readFile(file);
  if (!content) continue;

  let match;
  while ((match = routePattern.exec(content)) !== null) {
    const ln = lineNumber(content, match.index);
    const openParen = match.index + match[0].length - 1;

    const { args: rawArgs } = extractCallArgs(content, openParen + 1);
    const topArgs = splitTopLevelArgs(rawArgs);

    if (topArgs.length < 2) continue; // Malformed — skip

    const namespace = extractStringValue(topArgs[0]);
    const route = extractStringValue(topArgs[1]);
    const routeBlock = topArgs.slice(2).join(","); // The options array arg(s)

    const hasPermissionCallback = /permission_callback/.test(rawArgs);

    // A route definition can be a list of method-specific handlers or a single handler.
    // Check if the block contains multiple method arrays or a single one.
    // For the methods field, scan the routeBlock (the array arg).
    const methods = extractMethods(routeBlock) !== "unknown"
      ? extractMethods(routeBlock)
      : extractMethods(rawArgs); // fallback: scan entire args

    endpoints.push({
      namespace,
      route,
      methods,
      has_permission_callback: hasPermissionCallback,
      file: path.relative(pluginDir, file),
      line: ln,
    });
  }
}

// ---------------------------------------------------------------------------
// Output JSON
// ---------------------------------------------------------------------------
process.stdout.write(JSON.stringify(endpoints, null, 2) + "\n");

// ---------------------------------------------------------------------------
// Warn on missing permission_callback and determine exit code
// ---------------------------------------------------------------------------
let missingCount = 0;
for (const ep of endpoints) {
  if (!ep.has_permission_callback) {
    process.stderr.write(
      `WARN ${ep.file}:${ep.line} register_rest_route() "${ep.namespace}${ep.route}" is missing permission_callback — this exposes the endpoint to unauthenticated access\n`
    );
    missingCount++;
  }
}

if (missingCount > 0) {
  process.stderr.write(
    `\n${missingCount} route(s) missing permission_callback. Add a permission_callback to each route.\n`
  );
  process.exit(1);
} else {
  if (endpoints.length > 0) {
    process.stderr.write(
      `\nAll ${endpoints.length} route(s) have a permission_callback.\n`
    );
  } else {
    process.stderr.write(`\nNo register_rest_route() calls found.\n`);
  }
  process.exit(0);
}
