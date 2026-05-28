/**
 * validate-structure.mjs
 *
 * Validates the directory structure of a WPBoilerplate-based WordPress plugin.
 *
 * Usage:
 *   node validate-structure.mjs [--dir=<plugin-root>]
 *
 * Defaults to process.cwd() if --dir is omitted.
 * Exits 0 on pass, 1 if any check fails.
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

let passed = 0;
let failed = 0;
const warnings = [];

function pass(id, msg) {
  process.stdout.write(`PASS ${id} ${msg}\n`);
  passed++;
}

function fail(id, msg) {
  process.stdout.write(`FAIL ${id} ${msg}\n`);
  failed++;
}

function warn(msg) {
  warnings.push(msg);
  process.stdout.write(`WARN ${msg}\n`);
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

function isFile(p) {
  try {
    return fs.statSync(p).isFile();
  } catch {
    return false;
  }
}

function isDir(p) {
  try {
    return fs.statSync(p).isDirectory();
  } catch {
    return false;
  }
}

/** Recursively find files matching a predicate. */
function findFiles(dir, predicate, excluded = []) {
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
      results.push(...findFiles(full, predicate, excluded));
    } else if (entry.isFile() && predicate(entry.name)) {
      results.push(full);
    }
  }
  return results;
}

// ---------------------------------------------------------------------------
// C01 — plugin root is a directory
// ---------------------------------------------------------------------------
if (!isDir(pluginDir)) {
  fail("C01", `Plugin directory does not exist: ${pluginDir}`);
  process.stdout.write(`\n1 tests, 0 passed, 1 failed\n`);
  process.exit(1);
} else {
  pass("C01", `Plugin directory exists: ${pluginDir}`);
}

// ---------------------------------------------------------------------------
// C02 — Find the main plugin file (a .php file with "Plugin Name:" header)
// ---------------------------------------------------------------------------
const rootPhpFiles = [];
let mainPluginFile = null;

try {
  const rootEntries = fs.readdirSync(pluginDir, { withFileTypes: true });
  for (const entry of rootEntries) {
    if (entry.isFile() && entry.name.endsWith(".php")) {
      rootPhpFiles.push(entry.name);
      const content = readFile(path.join(pluginDir, entry.name)) ?? "";
      if (/Plugin Name\s*:/i.test(content)) {
        mainPluginFile = entry.name;
      }
    }
  }
} catch {
  // handled below
}

if (mainPluginFile) {
  pass("C02", `Main plugin file found: ${mainPluginFile}`);
} else {
  fail("C02", `No PHP file with "Plugin Name:" header found in ${pluginDir}`);
}

// ---------------------------------------------------------------------------
// C03 — readme.txt exists
// ---------------------------------------------------------------------------
if (isFile(path.join(pluginDir, "readme.txt"))) {
  pass("C03", "readme.txt exists");
} else {
  fail("C03", "readme.txt not found in plugin root");
}

// ---------------------------------------------------------------------------
// C04 — No extra PHP files in the plugin root (only main + uninstall.php)
// ---------------------------------------------------------------------------
const allowedRootPhpFiles = new Set([mainPluginFile, "uninstall.php"].filter(Boolean));
const unexpectedRootPhpFiles = rootPhpFiles.filter((f) => !allowedRootPhpFiles.has(f));

if (unexpectedRootPhpFiles.length === 0) {
  pass("C04", `Only expected PHP files in plugin root (${[...allowedRootPhpFiles].join(", ")})`);
} else {
  fail(
    "C04",
    `Unexpected PHP files in plugin root: ${unexpectedRootPhpFiles.join(", ")}. ` +
      "Move them to a subdirectory (includes/, admin/, public/)."
  );
}

// ---------------------------------------------------------------------------
// C05 — Functions and classes are prefixed
//
// Strategy: grep all PHP files for bare `function ` declarations where the
// function name does NOT start with a known plugin prefix. We derive the
// prefix from the main plugin file's namespace or "Text Domain" header.
// ---------------------------------------------------------------------------

// Derive prefix candidates from main plugin file
const prefixCandidates = new Set();

if (mainPluginFile) {
  const mainContent = readFile(path.join(pluginDir, mainPluginFile)) ?? "";

  // Text Domain: my-plugin  →  my_plugin, my-plugin, myplugin
  const tdMatch = mainContent.match(/Text Domain\s*:\s*([^\r\n]+)/i);
  if (tdMatch) {
    const td = tdMatch[1].trim();
    prefixCandidates.add(td.replace(/-/g, "_"));
    prefixCandidates.add(td.replace(/-/g, ""));
    prefixCandidates.add(td);
  }

  // namespace RootNamespace\... → root part lowercased
  const nsMatch = mainContent.match(/namespace\s+([A-Za-z_][A-Za-z0-9_]*)/);
  if (nsMatch) {
    const ns = nsMatch[1].toLowerCase();
    prefixCandidates.add(ns);
  }

  // Plugin Name: My Plugin → my_plugin, myplugin
  const pnMatch = mainContent.match(/Plugin Name\s*:\s*([^\r\n]+)/i);
  if (pnMatch) {
    const pn = pnMatch[1].trim().toLowerCase();
    prefixCandidates.add(pn.replace(/\s+/g, "_"));
    prefixCandidates.add(pn.replace(/\s+/g, ""));
    prefixCandidates.add(pn.replace(/[\s-]+/g, "_"));
  }
}

const phpFiles = findFiles(pluginDir, (f) => f.endsWith(".php"), [
  "vendor",
  "node_modules",
]);

// Match global function declarations (not inside class bodies — heuristic)
// We warn on `function name(` where name does not contain any prefix candidate.
const bareFunctionPattern = /^[ \t]*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/gm;

let bareCount = 0;
for (const file of phpFiles) {
  const content = readFile(file) ?? "";
  let match;
  while ((match = bareFunctionPattern.exec(content)) !== null) {
    const funcName = match[1];
    // Skip magic methods and well-known WP function name patterns
    if (funcName.startsWith("__")) continue;

    const hasPrefix =
      prefixCandidates.size === 0 ||
      [...prefixCandidates].some((p) => funcName.toLowerCase().startsWith(p));

    if (!hasPrefix) {
      const lineNum =
        content.slice(0, match.index).split("\n").length;
      const rel = path.relative(pluginDir, file);
      warn(`Possibly unprefixed function: ${funcName}() in ${rel}:${lineNum}`);
      bareCount++;
    }
  }
}

if (bareCount === 0) {
  pass("C05", "All detected function names appear to be prefixed");
} else {
  // Warn only — prefixing check is a heuristic; some false positives are expected
  pass(
    "C05",
    `Prefix check done — ${bareCount} possibly unprefixed function(s) found (see WARN lines above)`
  );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
const total = passed + failed;
process.stdout.write(`\n${total} checks, ${passed} passed, ${failed} failed\n`);
if (warnings.length > 0) {
  process.stdout.write(`${warnings.length} warning(s)\n`);
}
process.exit(failed > 0 ? 1 : 0);
