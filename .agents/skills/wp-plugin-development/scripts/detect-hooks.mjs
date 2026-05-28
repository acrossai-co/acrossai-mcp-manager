/**
 * detect-hooks.mjs
 *
 * Scans all PHP files in a WordPress plugin for add_action / add_filter calls
 * and outputs a JSON array describing each hook registration.
 *
 * Usage:
 *   node detect-hooks.mjs [--dir=<plugin-root>]
 *
 * Output format (to stdout):
 *   [
 *     {
 *       "type": "action" | "filter",
 *       "hook": "admin_menu",
 *       "callback": "MyPlugin\\Admin\\Main::register_menu",
 *       "priority": 10,
 *       "file": "includes/Main.php",
 *       "line": 42
 *     },
 *     ...
 *   ]
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
 * Extract the content of an outer function call's argument list,
 * starting from `startIndex` (the character AFTER the opening parenthesis).
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
 * Split a PHP argument string on top-level commas (not inside brackets/strings).
 */
function splitArgs(argsStr) {
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
 * Extract a printable callback label from a PHP argument expression.
 * Handles: 'string', "string", [ $obj, 'method' ], [ ClassName::class, 'method' ],
 * [ $this, 'method' ], __CLASS__ . '::method', 'ClassName::method', etc.
 */
function parseCallback(raw) {
  const s = raw.trim();

  // Array callback: [ $obj, 'method' ] or [ SomeClass::class, 'method' ]
  const arrayMatch = s.match(/^\[\s*([^,\]]+)\s*,\s*['"]([^'"]+)['"]\s*\]$/);
  if (arrayMatch) {
    const obj = arrayMatch[1].trim().replace(/\s*::\s*class\s*$/, "");
    return `${obj}::${arrayMatch[2]}`;
  }

  // String callback: 'my_function' or "my_function"
  const stringMatch = s.match(/^['"]([^'"]+)['"]$/);
  if (stringMatch) return stringMatch[1];

  // Concatenated: __CLASS__ . '::method'
  const concatMatch = s.match(/__CLASS__\s*\.\s*['"]::([^'"]+)['"]/);
  if (concatMatch) return `(class)::${concatMatch[1]}`;

  // Fallback: return raw trimmed value
  return s;
}

/**
 * Parse an integer priority from a PHP expression, falling back to 10.
 */
function parsePriority(raw) {
  if (!raw) return 10;
  const trimmed = raw.trim();
  const n = parseInt(trimmed, 10);
  return isNaN(n) ? 10 : n;
}

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------

/** @type {Array<{type:string,hook:string,callback:string,priority:number,file:string,line:number}>} */
const hookRegistrations = [];

// Match add_action or add_filter followed by opening paren
const registrationPattern = /\b(add_action|add_filter)\s*\(/g;

const phpFiles = collectPhpFiles(pluginDir);

for (const file of phpFiles) {
  const content = readFile(file);
  if (!content) continue;

  let match;
  while ((match = registrationPattern.exec(content)) !== null) {
    const type = match[1] === "add_action" ? "action" : "filter";
    const ln = lineNumber(content, match.index);
    const openParen = match.index + match[0].length - 1; // position of '('

    const { args: rawArgs } = extractCallArgs(content, openParen + 1);
    const parts = splitArgs(rawArgs);

    if (parts.length < 2) continue; // Malformed call — skip

    // Argument positions: hook, callback, [priority], [accepted_args]
    const hook = parts[0]
      ? parts[0].replace(/^['"]|['"]$/g, "").trim()
      : "(dynamic)";
    const callback = parts[1] ? parseCallback(parts[1]) : "(unknown)";
    const priority = parsePriority(parts[2]);

    hookRegistrations.push({
      type,
      hook,
      callback,
      priority,
      file: path.relative(pluginDir, file),
      line: ln,
    });
  }
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
process.stdout.write(JSON.stringify(hookRegistrations, null, 2) + "\n");
