#!/usr/bin/env bash
# check-memory.sh — Lightweight memory hygiene check for CI or pre-commit hooks.
#
# Usage:
#   ./scripts/check-memory.sh <project_root>
#
# Checks:
#   1. Durable memory files and INDEX.md exist in docs/memory/
#   2. Active feature folders in specs/ have memory.md and memory-synthesis.md
#   3. memory-synthesis.md has all required sections
#
# Exit codes:
#   0 = all checks pass
#   1 = one or more checks failed

set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "Usage: ./scripts/check-memory.sh <project_root>"
  exit 1
fi

PROJECT_ROOT="$1"
ERRORS=0

# --- Colors (if terminal supports them) ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

pass() { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; ERRORS=$((ERRORS + 1)); }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }

# --- Check 1: Durable memory files exist ---
echo ""
echo "Checking durable memory files..."

REQUIRED_MEMORY_FILES=(
  "docs/memory/INDEX.md"
  "docs/memory/PROJECT_CONTEXT.md"
  "docs/memory/ARCHITECTURE.md"
  "docs/memory/DECISIONS.md"
  "docs/memory/BUGS.md"
  "docs/memory/WORKLOG.md"
)

for f in "${REQUIRED_MEMORY_FILES[@]}"; do
  if [ -f "$PROJECT_ROOT/$f" ]; then
    pass "$f exists"
  else
    fail "$f is missing"
  fi
done

# --- Check 2: Active feature folders have memory files ---
echo ""
echo "Checking active feature folders..."

SPECS_ROOT="$PROJECT_ROOT/specs"
FOUND_FEATURES=0

if [ ! -d "$SPECS_ROOT" ]; then
  warn "specs/ directory not found — skipping feature checks"
else
  for feature_dir in "$SPECS_ROOT"/*/; do
    [ -d "$feature_dir" ] || continue

    feature_name=$(basename "$feature_dir")

    # Skip the example feature template
    if [ "$feature_name" = "001-example-feature" ]; then
      continue
    fi

    # Only check folders that look like active features (have a spec.md or plan.md)
    if [ -f "$feature_dir/spec.md" ] || [ -f "$feature_dir/plan.md" ]; then
      FOUND_FEATURES=$((FOUND_FEATURES + 1))

      if [ -f "$feature_dir/memory.md" ]; then
        pass "$feature_name/memory.md exists"
      else
        fail "$feature_name/memory.md is missing"
      fi

      if [ -f "$feature_dir/memory-synthesis.md" ]; then
        pass "$feature_name/memory-synthesis.md exists"
      else
        fail "$feature_name/memory-synthesis.md is missing"
      fi
    fi
  done

  if [ "$FOUND_FEATURES" -eq 0 ]; then
    warn "No active feature folders found in specs/"
  fi
fi

# --- Check 3: Synthesis files have required sections ---
echo ""
echo "Checking synthesis structure..."

if [ -d "$SPECS_ROOT" ]; then
  for synthesis_file in "$SPECS_ROOT"/*/memory-synthesis.md; do
    [ -f "$synthesis_file" ] || continue

    feature_name=$(basename "$(dirname "$synthesis_file")")

    # Skip example template
    if [ "$feature_name" = "001-example-feature" ]; then
      continue
    fi

    REQUIRED_SECTIONS=(
      "Current Constraints"
      "Reused Decisions"
      "Relevant Bug Patterns"
      "Architecture Boundaries"
      "Feature-to-Memory Conflicts"
      "Assumptions Requiring Confirmation"
      "Implementation Watchpoints"
      "Verification Watchpoints"
    )

    for section in "${REQUIRED_SECTIONS[@]}"; do
      if grep -q "## $section" "$synthesis_file"; then
        pass "$feature_name synthesis has '$section'"
      else
        fail "$feature_name synthesis is missing section '$section'"
      fi
    done
  done
fi

# --- Check 4: Copilot instructions exist ---
echo ""
echo "Checking repo conventions..."

if [ -f "$PROJECT_ROOT/.github/copilot-instructions.md" ]; then
  pass ".github/copilot-instructions.md exists"
else
  warn ".github/copilot-instructions.md is missing (optional but recommended)"
fi

# --- Summary ---
echo ""
if [ "$ERRORS" -eq 0 ]; then
  echo -e "${GREEN}All checks passed.${NC}"
  exit 0
else
  echo -e "${RED}$ERRORS check(s) failed.${NC}"
  exit 1
fi
