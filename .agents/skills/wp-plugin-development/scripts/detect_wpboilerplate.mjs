import fs from "node:fs";
import path from "node:path";

const DEFAULT_IGNORES = new Set([
  ".git",
  "node_modules",
  "vendor",
  "dist",
  "build",
  "coverage",
  ".next",
  ".turbo",
]);

function statSafe(p) {
  try {
    return fs.statSync(p);
  } catch {
    return null;
  }
}

function readFileSafe(p, maxBytes = 128 * 1024) {
  try {
    const buf = fs.readFileSync(p);
    if (buf.byteLength > maxBytes) return buf.subarray(0, maxBytes).toString("utf8");
    return buf.toString("utf8");
  } catch {
    return null;
  }
}

function findFilesRecursive(repoRoot, predicate, { maxFiles = 6000, maxDepth = 10 } = {}) {
  const results = [];
  const queue = [{ dir: repoRoot, depth: 0 }];
  let visited = 0;

  while (queue.length > 0) {
    const { dir, depth } = queue.shift();
    if (depth > maxDepth) continue;

    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
      continue;
    }

    for (const ent of entries) {
      const fullPath = path.join(dir, ent.name);
      if (ent.isDirectory()) {
        if (DEFAULT_IGNORES.has(ent.name)) continue;
        queue.push({ dir: fullPath, depth: depth + 1 });
        continue;
      }
      if (!ent.isFile()) continue;

      visited += 1;
      if (visited > maxFiles) return { results, truncated: true };
      if (predicate(fullPath)) results.push(fullPath);
    }
  }

  return { results, truncated: false };
}

function parsePluginHeader(contents) {
  const header = {};
  const pairs = [
    ["Plugin Name", "name"],
    ["Plugin URI", "uri"],
    ["Version", "version"],
    ["Author", "author"],
    ["Text Domain", "textDomain"],
  ];
  for (const [label, key] of pairs) {
    const m = contents.match(new RegExp(`^\\s*${label}:\\s*(.+)\\s*$`, "im"));
    if (m) header[key] = m[1].trim();
  }
  if (!header.name) return null;
  return header;
}

function readComposerPsr4(repoRoot) {
  const composerPath = path.join(repoRoot, "composer.json");
  const txt = readFileSafe(composerPath);
  if (!txt) return {};
  try {
    const data = JSON.parse(txt);
    return data?.autoload?.["psr-4"] ?? {};
  } catch {
    return {};
  }
}

function detectNamespacePrefix(psr4Map) {
  // The boilerplate has three PSR-4 entries sharing a common root namespace.
  // Extract the root: "Foo\\Includes\\" → "Foo"
  for (const ns of Object.keys(psr4Map)) {
    const parts = ns.split("\\").filter(Boolean);
    if (parts.length >= 2) {
      const sub = parts[parts.length - 1];
      if (["Includes", "Admin", "Public"].includes(sub)) {
        return parts.slice(0, -1).join("\\");
      }
    }
  }
  return null;
}

function main() {
  const repoRoot = process.cwd();

  // Required structural files (relative to repoRoot)
  const requiredFiles = [
    "includes/Main.php",
    "includes/Loader.php",
    "includes/Autoloader.php",
    "admin/Main.php",
    "public/Main.php",
  ];

  const structurePresent = requiredFiles.every((f) => statSafe(path.join(repoRoot, f))?.isFile());

  // Find a top-level PHP file that declares namespace WordPress_Plugin_Boilerplate (or any
  // PSR-4 root namespace matching composer.json) and has a Plugin Name header.
  const psr4Map = readComposerPsr4(repoRoot);
  const detectedPrefix = detectNamespacePrefix(psr4Map);

  let bootstrapFile = null;
  let pluginHeader = null;

  const { results: phpFiles } = findFilesRecursive(repoRoot, (p) => p.toLowerCase().endsWith(".php"), {
    maxFiles: 200,
    maxDepth: 1, // only top-level PHP files
  });

  for (const phpPath of phpFiles) {
    const txt = readFileSafe(phpPath);
    if (!txt) continue;
    if (!/Plugin Name:/i.test(txt)) continue;
    // Check for boilerplate namespace declaration
    const hasBootstrapNs =
      /^\s*namespace\s+WordPress_Plugin_Boilerplate\s*;/m.test(txt) ||
      (detectedPrefix && new RegExp(`^\\s*namespace\\s+${detectedPrefix.replace(/\\/g, "\\\\")}\\s*;`, "m").test(txt));
    if (!hasBootstrapNs) continue;
    bootstrapFile = path.relative(repoRoot, phpPath);
    pluginHeader = parsePluginHeader(txt);
    break;
  }

  const isWPBoilerplate = structurePresent && bootstrapFile !== null;

  // Build namespace map from PSR-4 (filtered to Includes/Admin/Public sub-namespaces)
  const namespaceMap = {};
  for (const [ns, dir] of Object.entries(psr4Map)) {
    const parts = ns.split("\\").filter(Boolean);
    const sub = parts[parts.length - 1];
    if (["Includes", "Admin", "Public"].includes(sub)) {
      namespaceMap[ns] = dir;
    }
  }

  // Check for build artifacts
  const buildArtifactsPresent =
    statSafe(path.join(repoRoot, "build/js/backend.asset.php"))?.isFile() ?? false;

  const report = {
    tool: { name: "detect_wpboilerplate", version: "0.1.0" },
    repoRoot,
    isWPBoilerplate,
    namespacePrefix: detectedPrefix ?? (isWPBoilerplate ? "WordPress_Plugin_Boilerplate" : null),
    bootstrapFile,
    header: pluginHeader,
    namespaceMap,
    buildArtifactsPresent,
  };

  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
}

main();
