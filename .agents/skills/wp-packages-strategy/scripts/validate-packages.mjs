#!/usr/bin/env node
/**
 * Validate WordPress package usage.
 *
 * Scans a plugin's source files for:
 *  - Direct React/ReactDOM imports (should use @wordpress/element)
 *  - Duplicate React versions in node_modules
 *  - Whether @wordpress/element is declared in package.json
 *
 * Usage (run from repo root):
 *   node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.
 *   node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=../my-plugin
 *
 * Exit 0 = all good. Exit 1 = conflicts found.
 */

import fs from "node:fs";
import path from "node:path";

// ---------------------------------------------------------------------------
// Parse --dir argument
// ---------------------------------------------------------------------------
const dirArg = process.argv.find((a) => a.startsWith("--dir="));
const projectRoot = dirArg
  ? path.resolve(dirArg.slice("--dir=".length))
  : process.cwd();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function exists(p) {
  try { fs.statSync(p); return true; } catch { return false; }
}

function readJson(p) {
  try { return JSON.parse(fs.readFileSync(p, "utf8")); } catch { return null; }
}

/** Recursively collect files with given extensions, excluding ignored dirs. */
function collectFiles(dir, exts, ignore = ["node_modules", "vendor", "build", "dist", ".git"]) {
  const results = [];
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return results; }
  for (const entry of entries) {
    if (ignore.includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...collectFiles(full, exts, ignore));
    } else if (entry.isFile() && exts.some((e) => entry.name.endsWith(e))) {
      results.push(full);
    }
  }
  return results;
}

/** Return lines in a file that match a regex, with 1-based line numbers. */
function matchLines(filePath, regex) {
  const hits = [];
  const lines = fs.readFileSync(filePath, "utf8").split("\n");
  for (let i = 0; i < lines.length; i++) {
    if (regex.test(lines[i])) {
      hits.push({ line: i + 1, text: lines[i].trim() });
    }
  }
  return hits;
}

// ---------------------------------------------------------------------------
// Replacement suggestions
// ---------------------------------------------------------------------------
const REPLACEMENTS = [
  {
    pattern: /import\s+React\s*,?\s*(\{[^}]*\})?\s*from\s+['"]react['"]/,
    suggestion: "import { createElement } from '@wordpress/element'",
    note: "React's default export is not needed; import named hooks/utils from @wordpress/element",
  },
  {
    pattern: /import\s+\{[^}]*\}\s+from\s+['"]react['"]/,
    suggestion: "import { <same names> } from '@wordpress/element'",
    note: "@wordpress/element re-exports all React named exports",
  },
  {
    pattern: /require\s*\(\s*['"]react['"]\s*\)/,
    suggestion: "const { createElement } = require('@wordpress/element')",
    note: "CJS require of react — switch to @wordpress/element",
  },
  {
    pattern: /from\s+['"]react-dom['"]/,
    suggestion: "import { render, unmountComponentAtNode } from '@wordpress/element'",
    note: "@wordpress/element wraps ReactDOM — use it instead",
  },
  {
    pattern: /from\s+['"]react-dom\/client['"]/,
    suggestion: "import { createRoot } from '@wordpress/element'",
    note: "@wordpress/element exports createRoot (WP 6.2+)",
  },
  {
    pattern: /require\s*\(\s*['"]react-dom['"]\s*\)/,
    suggestion: "const { render } = require('@wordpress/element')",
    note: "CJS require of react-dom — switch to @wordpress/element",
  },
];

function suggestReplacement(lineText) {
  for (const r of REPLACEMENTS) {
    if (r.pattern.test(lineText)) return r;
  }
  return null;
}

// Broad pattern to catch any remaining react / react-dom imports not covered above
const REACT_IMPORT_PATTERN =
  /(from\s+['"]react['"]|from\s+['"]react-dom[^'"]*['"]|require\s*\(\s*['"]react['"]|require\s*\(\s*['"]react-dom)/;

// ---------------------------------------------------------------------------
// Check 1 — Direct react / react-dom imports in source files
// ---------------------------------------------------------------------------
const sourceExts = [".js", ".jsx", ".ts", ".tsx", ".mjs", ".cjs"];
const sourceFiles = collectFiles(projectRoot, sourceExts);

const importIssues = [];
for (const file of sourceFiles) {
  const hits = matchLines(file, REACT_IMPORT_PATTERN);
  for (const hit of hits) {
    const replacement = suggestReplacement(hit.text);
    importIssues.push({ file: path.relative(projectRoot, file), ...hit, replacement });
  }
}

// ---------------------------------------------------------------------------
// Check 2 — @wordpress/element in package.json
// ---------------------------------------------------------------------------
const pkgPath = path.join(projectRoot, "package.json");
const pkg = readJson(pkgPath);
const allDeps = { ...pkg?.dependencies, ...pkg?.devDependencies };
const hasWpElement = allDeps && "@wordpress/element" in allDeps;
const hasWpScripts = allDeps && "@wordpress/scripts" in allDeps;
// @wordpress/scripts implies @wordpress/element is available via the build pipeline
const wpElementAvailable = hasWpElement || hasWpScripts;

// ---------------------------------------------------------------------------
// Check 3 — Duplicate React versions in node_modules
// ---------------------------------------------------------------------------
const nodeModules = path.join(projectRoot, "node_modules");
const duplicates = [];

function findReactVersions(modDir, depth = 0) {
  if (depth > 4) return; // don't recurse indefinitely
  const reactPkg = path.join(modDir, "react", "package.json");
  const reactDomPkg = path.join(modDir, "react-dom", "package.json");
  if (exists(reactPkg)) {
    const v = readJson(reactPkg)?.version;
    if (v) duplicates.push({ package: "react", version: v, location: path.relative(projectRoot, modDir) });
  }
  if (exists(reactDomPkg)) {
    const v = readJson(reactDomPkg)?.version;
    if (v) duplicates.push({ package: "react-dom", version: v, location: path.relative(projectRoot, modDir) });
  }
  // Check nested node_modules inside each package
  if (!exists(modDir)) return;
  let entries;
  try { entries = fs.readdirSync(modDir, { withFileTypes: true }); } catch { return; }
  for (const entry of entries) {
    if (!entry.isDirectory() || entry.name.startsWith(".")) continue;
    const nested = path.join(modDir, entry.name, "node_modules");
    if (exists(nested)) findReactVersions(nested, depth + 1);
  }
}

if (exists(nodeModules)) {
  findReactVersions(nodeModules);
}

// Deduplicate and count distinct versions
const reactVersions = [...new Set(duplicates.filter((d) => d.package === "react").map((d) => d.version))];
const reactDomVersions = [...new Set(duplicates.filter((d) => d.package === "react-dom").map((d) => d.version))];
const hasDuplicateReact = reactVersions.length > 1;
const hasDuplicateReactDom = reactDomVersions.length > 1;

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
const hasIssues = importIssues.length > 0 || hasDuplicateReact || hasDuplicateReactDom;

if (!hasIssues) {
  process.stdout.write("✓ Package validation passed\n\n");
  process.stdout.write("✓ No direct React imports found\n");
  process.stdout.write(
    wpElementAvailable
      ? "✓ @wordpress/element is available\n"
      : "⚠ @wordpress/element not found in package.json (add it or use @wordpress/scripts)\n"
  );
  process.stdout.write("✓ No duplicate React packages detected\n");
  if (!exists(nodeModules)) {
    process.stdout.write("⚠ node_modules/ not found — run npm install before a full check\n");
  }
  process.stdout.write("\nRun: npm run build\n");
  process.exit(0);
}

// There are issues
process.stdout.write("✗ Package validation failed\n\n");

if (importIssues.length > 0) {
  for (const issue of importIssues) {
    process.stdout.write(`❌ ${issue.file}:${issue.line}\n`);
    process.stdout.write(`   Found: ${issue.text}\n`);
    if (issue.replacement) {
      process.stdout.write(`   Use:   ${issue.replacement.suggestion}\n`);
      if (issue.replacement.note) {
        process.stdout.write(`   Why:   ${issue.replacement.note}\n`);
      }
    }
    process.stdout.write("\n");
  }
}

if (hasDuplicateReact) {
  process.stdout.write("❌ Duplicate React versions found in node_modules:\n");
  for (const v of reactVersions) process.stdout.write(`   - react@${v}\n`);
  process.stdout.write("\n");
}

if (hasDuplicateReactDom) {
  process.stdout.write("❌ Duplicate react-dom versions found in node_modules:\n");
  for (const v of reactDomVersions) process.stdout.write(`   - react-dom@${v}\n`);
  process.stdout.write("\n");
}

process.stdout.write("Fix:\n");
if (importIssues.length > 0) {
  const files = [...new Set(importIssues.map((i) => i.file))];
  process.stdout.write(`1. Replace React imports in: ${files.join(", ")}\n`);
  process.stdout.write("   See: references/wordpress-packages.md for the replacement map\n");
}
if (hasDuplicateReact || hasDuplicateReactDom) {
  process.stdout.write("2. Add webpack aliases in webpack.config.js:\n");
  process.stdout.write("     alias: { react: path.resolve(__dirname, 'node_modules/@wordpress/element') }\n");
  process.stdout.write("   See: references/webpack-aliasing.md\n");
}
process.stdout.write("3. Run: npm install\n");
process.stdout.write("4. Run: node skills/wp-packages-strategy/scripts/validate-packages.mjs --dir=.\n");

process.exit(1);
