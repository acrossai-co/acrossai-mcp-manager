/**
 * detect-deprecations.mjs
 *
 * Scans WordPress plugin PHP files for calls to deprecated WordPress functions.
 *
 * Usage:
 *   node detect-deprecations.mjs [--dir=<plugin-root>]
 *
 * Prints WARN lines for each finding.
 * Always exits 0.
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
// Deprecated function registry
// Each entry: { name, note } where note is shown to the developer.
// ---------------------------------------------------------------------------
const DEPRECATED_FUNCTIONS = [
  {
    name: "get_currentuserinfo",
    note: "Deprecated in WP 4.5. Use wp_get_current_user() instead.",
  },
  {
    name: "wp_specialchars",
    note: "Deprecated in WP 2.8. Use esc_html() instead.",
  },
  {
    name: "attribute_escape",
    note: "Deprecated in WP 2.8. Use esc_attr() instead.",
  },
  {
    name: "the_category_id",
    note: "Deprecated in WP 0.71. Use get_the_category() instead.",
  },
  {
    name: "get_the_author_email",
    note: "Deprecated in WP 2.8. Use get_the_author_meta('user_email') instead.",
  },
  {
    name: "get_author_name",
    note: "Deprecated in WP 2.8. Use get_the_author_meta('display_name') instead.",
  },
  {
    name: "get_profile",
    note: "Deprecated in WP 2.0. Use get_the_author_meta() instead.",
  },
  {
    name: "get_userdatabylogin",
    note: "Deprecated in WP 3.3. Use get_user_by('login', ...) instead.",
  },
  {
    name: "sanitize_url",
    note: "Alias removed in WP 6.1. Use esc_url_raw() or wp_sanitize_redirect() instead.",
  },
  {
    name: "clean_url",
    note: "Deprecated in WP 3.0. Use esc_url() instead.",
  },
  {
    name: "wp_make_link_relative",
    note: "Not deprecated but avoid in plugins — it breaks multisite. Use wp_parse_url() logic.",
  },
  {
    name: "query_posts",
    note: "Deprecated pattern. Replace with WP_Query or pre_get_posts filter.",
  },
  {
    name: "wp_cache_reset",
    note: "Deprecated in WP 3.5. Use wp_cache_init() or a group-delete pattern instead.",
  },
  {
    name: "add_contextual_help",
    note: "Deprecated in WP 3.3. Use $screen->add_help_tab() instead.",
  },
  {
    // get_posts with suppress_filters is not deprecated but is a known bad pattern
    name: "suppress_filters",
    note:
      "Using suppress_filters in get_posts() or WP_Query disables critical hooks. Remove unless absolutely necessary.",
    matchPattern: /\bsuppress_filters\s*[=:>]+\s*true/,
  },
];

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

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------

let warnCount = 0;

const phpFiles = collectPhpFiles(pluginDir);

for (const file of phpFiles) {
  const content = readFile(file);
  if (!content) continue;

  const relFile = path.relative(pluginDir, file);

  for (const dep of DEPRECATED_FUNCTIONS) {
    // Use a custom matchPattern if supplied, otherwise match the function call by name
    const pattern =
      dep.matchPattern instanceof RegExp
        ? new RegExp(dep.matchPattern.source, "g")
        : new RegExp(`\\b${dep.name}\\s*\\(`, "g");

    let match;
    while ((match = pattern.exec(content)) !== null) {
      const ln = lineNumber(content, match.index);
      process.stdout.write(
        `WARN ${relFile}:${ln} Deprecated function: ${dep.name}() — ${dep.note}\n`
      );
      warnCount++;
    }
  }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
process.stdout.write(
  `\nScanned ${phpFiles.length} PHP file(s). ${warnCount} deprecation warning(s) found.\n`
);
process.exit(0);
