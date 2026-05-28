/**
 * Deterministic test runner for the wp-plugin-development skill.
 *
 * Usage (run from repo root):
 *   node skills/wp-plugin-development/scripts/test-skill.mjs
 *
 * Exits 0 if all tests pass, exits 1 if any fail.
 * No third-party dependencies — uses only node:fs, node:path, node:child_process.
 */

import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";

const repoRoot = process.cwd();
const skillDir = path.join(repoRoot, "skills", "wp-plugin-development");

let passed = 0;
let failed = 0;

function pass(id, msg) {
  process.stdout.write(`PASS ${id} ${msg}\n`);
  passed++;
}

function fail(id, msg) {
  process.stdout.write(`FAIL ${id} ${msg}\n`);
  failed++;
}

function exists(p) {
  try {
    return fs.statSync(p).isFile();
  } catch {
    return false;
  }
}

function read(p) {
  try {
    return fs.readFileSync(p, "utf8");
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// T01 — SKILL.md exists
// ---------------------------------------------------------------------------
const skillMdPath = path.join(skillDir, "SKILL.md");
if (exists(skillMdPath)) {
  pass("T01", "SKILL.md exists");
} else {
  fail("T01", `SKILL.md not found at ${path.relative(repoRoot, skillMdPath)}`);
}

// ---------------------------------------------------------------------------
// T02 — YAML frontmatter present and well-formed
// ---------------------------------------------------------------------------
const skillMd = read(skillMdPath) ?? "";
const fmMatch = skillMd.match(/^---\n([\s\S]*?)\n---/);
if (fmMatch) {
  pass("T02", "YAML frontmatter present");
} else {
  fail("T02", "YAML frontmatter missing or malformed in SKILL.md");
}

// ---------------------------------------------------------------------------
// T03 — Frontmatter name matches directory name
// ---------------------------------------------------------------------------
if (fmMatch) {
  const nameMatch = fmMatch[1].match(/^\s*name\s*:\s*(.+)/m);
  const nameVal = nameMatch ? nameMatch[1].replace(/^["']|["']$/g, "").trim() : null;
  if (nameVal === "wp-plugin-development") {
    pass("T03", `Frontmatter name is correct ('${nameVal}')`);
  } else {
    fail("T03", `Frontmatter name is '${nameVal}', expected 'wp-plugin-development'`);
  }
}

// ---------------------------------------------------------------------------
// T04 — Required SKILL.md sections present
// ---------------------------------------------------------------------------
const requiredSections = [
  "## When to use",
  "## Inputs required",
  "## Procedure",
  "## Verification",
  "## Failure modes",
  "## Escalation",
];
const missingSections = requiredSections.filter((s) => !skillMd.includes(s));
if (missingSections.length === 0) {
  pass("T04", "All required sections present in SKILL.md");
} else {
  fail("T04", `Missing sections in SKILL.md: ${missingSections.join(", ")}`);
}

// ---------------------------------------------------------------------------
// T05 — All 6 reference files exist
// ---------------------------------------------------------------------------
const refFiles = [
  "references/structure.md",
  "references/boot-flow.md",
  "references/admin.md",
  "references/public.md",
  "references/lifecycle.md",
  "references/build-system.md",
];
const missingRefs = refFiles.filter((f) => !exists(path.join(skillDir, f)));
if (missingRefs.length === 0) {
  pass("T05", "All 6 reference files exist");
} else {
  fail("T05", `Missing reference files: ${missingRefs.join(", ")}`);
}

// ---------------------------------------------------------------------------
// T06 — Each reference file ends with an "Upstream reference:" link
// ---------------------------------------------------------------------------
const missingUpstream = refFiles.filter((f) => {
  const content = read(path.join(skillDir, f)) ?? "";
  return !content.includes("Upstream reference:");
});
if (missingUpstream.length === 0) {
  pass("T06", "All reference files contain an 'Upstream reference:' link");
} else {
  fail("T06", `Missing 'Upstream reference:' in: ${missingUpstream.join(", ")}`);
}

// ---------------------------------------------------------------------------
// T07 — Detector script exists and parses as valid JS
// ---------------------------------------------------------------------------
const detectorPath = path.join(skillDir, "scripts", "detect_wpboilerplate.mjs");
if (exists(detectorPath)) {
  const check = spawnSync("node", ["--input-type=module", "--check"], {
    input: read(detectorPath),
    encoding: "utf8",
  });
  if (check.status === 0) {
    pass("T07", "Detector script exists and is valid JS");
  } else {
    fail("T07", `Detector script has syntax errors: ${check.stderr.trim()}`);
  }
} else {
  fail("T07", `Detector script not found at ${path.relative(repoRoot, detectorPath)}`);
}

// ---------------------------------------------------------------------------
// T08 — Detector returns isWPBoilerplate: true on the actual boilerplate
// ---------------------------------------------------------------------------
// Look for a local clone of the boilerplate. Try common locations.
const boilerplateCandidates = [
  // Local WP site plugin directory
  path.join(
    repoRoot,
    "..",
    "..",
    "..",
    "plugins",
    "wordpress-plugin-boilerplate"
  ),
  // Sibling directory
  path.join(repoRoot, "..", "wordpress-plugin-boilerplate"),
];

const boilerplateRoot = boilerplateCandidates.find((p) => {
  try {
    return (
      fs.statSync(path.join(p, "includes", "Main.php")).isFile() &&
      fs.statSync(path.join(p, "includes", "Loader.php")).isFile()
    );
  } catch {
    return false;
  }
});

if (boilerplateRoot) {
  const out = spawnSync("node", [detectorPath], {
    cwd: boilerplateRoot,
    encoding: "utf8",
  });
  if (out.status !== 0) {
    fail("T08", `Detector failed on boilerplate dir: ${out.stderr.trim()}`);
  } else {
    try {
      const report = JSON.parse(out.stdout);
      if (report.isWPBoilerplate === true && report.namespacePrefix && report.bootstrapFile) {
        pass("T08", `Detector correctly identified boilerplate (prefix: ${report.namespacePrefix})`);
      } else {
        fail(
          "T08",
          `Detector returned isWPBoilerplate=${report.isWPBoilerplate}, prefix=${report.namespacePrefix}, bootstrap=${report.bootstrapFile}`
        );
      }
    } catch {
      fail("T08", `Detector output is not valid JSON: ${out.stdout.slice(0, 200)}`);
    }
  }
} else {
  process.stdout.write(`SKIP T08 No local boilerplate clone found (checked: ${boilerplateCandidates.map(p => path.relative(repoRoot, p)).join(", ")})\n`);
}

// ---------------------------------------------------------------------------
// T09 — Detector returns isWPBoilerplate: false on this repo (agent-skills)
// ---------------------------------------------------------------------------
if (exists(detectorPath)) {
  const out = spawnSync("node", [detectorPath], {
    cwd: repoRoot,
    encoding: "utf8",
  });
  if (out.status !== 0) {
    fail("T09", `Detector crashed on non-boilerplate dir: ${out.stderr.trim()}`);
  } else {
    try {
      const report = JSON.parse(out.stdout);
      if (report.isWPBoilerplate === false) {
        pass("T09", "Detector correctly returned false for non-boilerplate repo");
      } else {
        fail("T09", `Detector returned isWPBoilerplate=true for non-boilerplate repo`);
      }
    } catch {
      fail("T09", `Detector output is not valid JSON: ${out.stdout.slice(0, 200)}`);
    }
  }
}

// ---------------------------------------------------------------------------
// T10 — All 5 eval scenario JSON files exist
// ---------------------------------------------------------------------------
const scenarioFiles = [
  "wpboilerplate-add-admin-page.json",
  "wpboilerplate-add-frontend-feature.json",
  "wpboilerplate-add-constant.json",
  "wpboilerplate-activation-cleanup.json",
  "wpboilerplate-add-block.json",
];
const scenariosDir = path.join(repoRoot, "eval", "scenarios");
const missingScenarios = scenarioFiles.filter((f) => !exists(path.join(scenariosDir, f)));
if (missingScenarios.length === 0) {
  pass("T10", "All 5 eval scenario JSON files exist");
} else {
  fail("T10", `Missing scenario files: ${missingScenarios.join(", ")}`);
}

// ---------------------------------------------------------------------------
// T11 — Scenario schema: name, skills[], query, expected_behavior[], success_criteria[]
// ---------------------------------------------------------------------------
const schemaErrors = [];
for (const f of scenarioFiles) {
  const fPath = path.join(scenariosDir, f);
  const txt = read(fPath);
  if (!txt) { schemaErrors.push(`${f}: file not readable`); continue; }
  let obj;
  try { obj = JSON.parse(txt); } catch (e) { schemaErrors.push(`${f}: invalid JSON`); continue; }
  if (!obj.name) schemaErrors.push(`${f}: missing 'name'`);
  if (!Array.isArray(obj.skills) || obj.skills.length === 0) schemaErrors.push(`${f}: missing 'skills' array`);
  if (!obj.query) schemaErrors.push(`${f}: missing 'query'`);
  if (!Array.isArray(obj.expected_behavior) || obj.expected_behavior.length < 3)
    schemaErrors.push(`${f}: 'expected_behavior' must have ≥ 3 items`);
  if (!Array.isArray(obj.success_criteria) || obj.success_criteria.length < 3)
    schemaErrors.push(`${f}: 'success_criteria' must have ≥ 3 items`);
}
if (schemaErrors.length === 0) {
  pass("T11", "All scenario files have valid schema");
} else {
  fail("T11", `Schema errors:\n  ${schemaErrors.join("\n  ")}`);
}

// ---------------------------------------------------------------------------
// T12 — Skill registered in docs/skill-set-v1.md
// ---------------------------------------------------------------------------
const skillSetPath = path.join(repoRoot, "docs", "skill-set-v1.md");
const skillSet = read(skillSetPath) ?? "";
if (skillSet.includes("wp-plugin-development")) {
  pass("T12", "Skill is registered in docs/skill-set-v1.md");
} else {
  fail("T12", "Skill name not found in docs/skill-set-v1.md");
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
const total = passed + failed;
process.stdout.write(`\n${total} tests, ${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);
