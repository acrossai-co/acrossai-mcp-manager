/**
 * validate-security.mjs
 *
 * Static security analysis for WPBoilerplate WordPress plugins.
 * Scans all PHP files and warns on common security anti-patterns.
 *
 * Usage:
 *   node validate-security.mjs [--dir=<plugin-root>]
 *
 * Always exits 0 — warnings are informational; static analysis has false positives.
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

let warnCount = 0;

function warn(file, line, msg) {
  const rel = path.relative(pluginDir, file);
  process.stdout.write(`WARN ${rel}:${line} ${msg}\n`);
  warnCount++;
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

/**
 * Recursively collect all .php files, excluding vendor/ and node_modules/.
 */
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

/**
 * Return the 1-based line number for the character at `index` in `text`.
 */
function lineNumber(text, index) {
  return text.slice(0, index).split("\n").length;
}

/**
 * Return a window of lines around a given 1-based line number.
 */
function getLines(text, lineNum, radius = 3) {
  const lines = text.split("\n");
  const start = Math.max(0, lineNum - 1 - radius);
  const end = Math.min(lines.length - 1, lineNum - 1 + radius);
  return lines.slice(start, end + 1).join("\n");
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

/**
 * CHECK 1: Raw $_POST / $_GET / $_REQUEST without wp_unslash on nearby lines.
 *
 * Heuristic: if the line or the two lines above do not contain wp_unslash,
 * sanitize_*, absint, intval, or a type cast, warn.
 */
function checkRawSuperglobals(file, content) {
  const superglobalPattern = /\$_(POST|GET|REQUEST)\s*\[/g;
  const safePatterns = [
    /wp_unslash/,
    /sanitize_/,
    /absint/,
    /intval/,
    /\(int\)/,
    /\(float\)/,
    /\(bool\)/,
    /\(array\)/,
    /esc_url_raw/,
    /wp_kses/,
  ];

  const lines = content.split("\n");
  let match;
  while ((match = superglobalPattern.exec(content)) !== null) {
    const ln = lineNumber(content, match.index);
    // Check current line and up to 2 preceding lines
    const window = lines.slice(Math.max(0, ln - 3), ln).join("\n");
    const isSafe = safePatterns.some((p) => p.test(window));
    if (!isSafe) {
      warn(
        file,
        ln,
        `Unsanitized $${match[1]}[...] — wrap with wp_unslash() then sanitize_*()`
      );
    }
  }
}

/**
 * CHECK 2: `echo $` without an escaping function wrapping it.
 *
 * Matches: echo $var, echo $var->prop, echo $arr['key'], echo $func().
 * Does NOT match: echo esc_html($var), echo wp_kses_post($var), etc.
 */
function checkUnescapedEcho(file, content) {
  const echoPattern = /\becho\s+(\$[a-zA-Z_][a-zA-Z0-9_$\[\]'"->{}.]*)/g;
  const escFuncs =
    /\b(esc_html|esc_attr|esc_url|esc_js|esc_textarea|wp_kses|wp_kses_post|absint|intval|number_format|wp_json_encode|htmlspecialchars|htmlentities)\s*\(/;

  let match;
  while ((match = echoPattern.exec(content)) !== null) {
    const ln = lineNumber(content, match.index);
    // Check the full statement on this line
    const lineStr = content.split("\n")[ln - 1] ?? "";
    if (!escFuncs.test(lineStr)) {
      warn(
        file,
        ln,
        `echo $variable without escaping — use esc_html(), esc_attr(), or esc_url()`
      );
    }
  }
}

/**
 * CHECK 3: $wpdb->query() with string concatenation (SQL injection risk).
 *
 * Matches calls like: $wpdb->query( "SELECT..." . $var )
 *                     $wpdb->query( "SELECT $var" )
 */
function checkSqlConcatenation(file, content) {
  // Match $wpdb->query( followed by content that includes a variable or string concat
  const queryPattern =
    /\$wpdb\s*->\s*(query|get_results|get_var|get_row|get_col)\s*\(\s*(?:"[^"]*\$|'[^']*\$|"[^"]*"\s*\.|'[^']*'\s*\.)/g;

  let match;
  while ((match = queryPattern.exec(content)) !== null) {
    const ln = lineNumber(content, match.index);
    warn(
      file,
      ln,
      `Possible SQL injection: $wpdb->${match[1]}() with string interpolation or concatenation — use $wpdb->prepare()`
    );
  }
}

/**
 * CHECK 4: register_rest_route() without a permission_callback key.
 *
 * Heuristic: scan forward from the register_rest_route( call and check whether
 * 'permission_callback' appears within the same bracket-balanced block.
 */
function checkRestRoutePermissions(file, content) {
  const routePattern = /register_rest_route\s*\(/g;

  let match;
  while ((match = routePattern.exec(content)) !== null) {
    const startIndex = match.index + match[0].length - 1; // position of opening (
    const ln = lineNumber(content, match.index);

    // Walk forward, tracking bracket depth to find the closing ) of register_rest_route
    let depth = 1;
    let i = startIndex + 1;
    let block = "";
    while (i < content.length && depth > 0) {
      const ch = content[i];
      if (ch === "(") depth++;
      if (ch === ")") depth--;
      if (depth > 0) block += ch;
      i++;
    }

    if (!/permission_callback/.test(block)) {
      warn(
        file,
        ln,
        `register_rest_route() call appears to be missing 'permission_callback'`
      );
    }
  }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

const phpFiles = collectPhpFiles(pluginDir);

if (phpFiles.length === 0) {
  process.stdout.write(
    `INFO No PHP files found in ${pluginDir} (excluding vendor/, node_modules/)\n`
  );
  process.exit(0);
}

for (const file of phpFiles) {
  const content = readFile(file);
  if (content === null) continue;

  checkRawSuperglobals(file, content);
  checkUnescapedEcho(file, content);
  checkSqlConcatenation(file, content);
  checkRestRoutePermissions(file, content);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
const scanned = phpFiles.length;
process.stdout.write(
  `\nScanned ${scanned} PHP file(s). ${warnCount} warning(s) found.\n`
);
process.exit(0); // Always exit 0 — warnings only
