#!/usr/bin/env bash
# test-install.sh — Basic smoke tests for install and check scripts.
#
# Usage:
#   ./scripts/test-install.sh
#
# Creates a temp project, runs install-into-project.sh, then check-memory.sh,
# and verifies the expected files exist.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUB_ROOT="$(dirname "$SCRIPT_DIR")"
TMP_PROJECT=$(mktemp -d)

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

TESTS=0
FAILURES=0

assert_file_exists() {
  TESTS=$((TESTS + 1))
  if [ -f "$1" ]; then
    echo -e "  ${GREEN}✓${NC} $2"
  else
    echo -e "  ${RED}✗${NC} $2 — file not found: $1"
    FAILURES=$((FAILURES + 1))
  fi
}

assert_dir_exists() {
  TESTS=$((TESTS + 1))
  if [ -d "$1" ]; then
    echo -e "  ${GREEN}✓${NC} $2"
  else
    echo -e "  ${RED}✗${NC} $2 — directory not found: $1"
    FAILURES=$((FAILURES + 1))
  fi
}

assert_command_succeeds() {
  TESTS=$((TESTS + 1))
  if eval "$1" > /dev/null 2>&1; then
    echo -e "  ${GREEN}✓${NC} $2"
  else
    echo -e "  ${RED}✗${NC} $2 — command failed: $1"
    FAILURES=$((FAILURES + 1))
  fi
}

# --- Test: Hub has required files ---
echo ""
echo "Test: Hub repo structure is valid"

assert_file_exists "$HUB_ROOT/extension.yml" "extension.yml exists at root"
assert_file_exists "$HUB_ROOT/config-template.yml" "config-template.yml exists at root"
assert_dir_exists "$HUB_ROOT/commands" "commands/ directory exists"
assert_dir_exists "$HUB_ROOT/templates" "templates/ directory exists"
assert_dir_exists "$HUB_ROOT/scripts" "scripts/ directory exists"

EXPECTED_COMMANDS=(
  "speckit.memory-md.bootstrap.md"
  "speckit.memory-md.plan-with-memory.md"
  "speckit.memory-md.capture.md"
  "speckit.memory-md.capture-from-diff.md"
  "speckit.memory-md.audit.md"
  "speckit.memory-md.log-finding.md"
)

for cmd in "${EXPECTED_COMMANDS[@]}"; do
  assert_file_exists "$HUB_ROOT/commands/$cmd" "command file: $cmd"
done

# --- Test: extension.yml references existing files ---
echo ""
echo "Test: extension.yml command files resolve"

while IFS= read -r line; do
  file_ref=$(echo "$line" | sed "s/.*file: *'\{0,1\}\([^']*\)'\{0,1\}/\1/")
  if [ -n "$file_ref" ]; then
    assert_file_exists "$HUB_ROOT/$file_ref" "extension.yml ref: $file_ref"
  fi
done < <(grep 'file:' "$HUB_ROOT/extension.yml")

# --- Test: Install into fresh project ---
echo ""
echo "Test: install-into-project.sh creates expected files"

"$HUB_ROOT/scripts/install-into-project.sh" "$HUB_ROOT" "$TMP_PROJECT" > /dev/null

assert_dir_exists "$TMP_PROJECT/docs/memory" "docs/memory/ created"
assert_dir_exists "$TMP_PROJECT/specs" "specs/ created"
assert_dir_exists "$TMP_PROJECT/.github" ".github/ created"
assert_file_exists "$TMP_PROJECT/docs/memory/INDEX.md" "INDEX.md installed"
assert_file_exists "$TMP_PROJECT/docs/memory/PROJECT_CONTEXT.md" "PROJECT_CONTEXT.md installed"
assert_file_exists "$TMP_PROJECT/docs/memory/ARCHITECTURE.md" "ARCHITECTURE.md installed"
assert_file_exists "$TMP_PROJECT/docs/memory/DECISIONS.md" "DECISIONS.md installed"
assert_file_exists "$TMP_PROJECT/docs/memory/BUGS.md" "BUGS.md installed"
assert_file_exists "$TMP_PROJECT/docs/memory/WORKLOG.md" "WORKLOG.md installed"
assert_file_exists "$TMP_PROJECT/.github/copilot-instructions.md" "copilot-instructions.md installed"
assert_file_exists "$TMP_PROJECT/specs/README.md" "specs/README.md installed"

# --- Test: Idempotent install (does not overwrite) ---
echo ""
echo "Test: re-install does not overwrite existing files"

echo "custom content" > "$TMP_PROJECT/docs/memory/PROJECT_CONTEXT.md"
"$HUB_ROOT/scripts/install-into-project.sh" "$HUB_ROOT" "$TMP_PROJECT" > /dev/null

TESTS=$((TESTS + 1))
if grep -q "custom content" "$TMP_PROJECT/docs/memory/PROJECT_CONTEXT.md"; then
  echo -e "  ${GREEN}✓${NC} existing PROJECT_CONTEXT.md was preserved"
else
  echo -e "  ${RED}✗${NC} existing PROJECT_CONTEXT.md was overwritten"
  FAILURES=$((FAILURES + 1))
fi

# --- Test: check-memory.sh runs on installed project ---
echo ""
echo "Test: check-memory.sh passes on installed project"

assert_command_succeeds "$HUB_ROOT/scripts/check-memory.sh $TMP_PROJECT" "check-memory.sh passes on fresh install"

# --- Test: sync-from-hub.sh only syncs copilot instructions ---
echo ""
echo "Test: sync-from-hub.sh syncs only shared standards"

echo "custom architecture" > "$TMP_PROJECT/docs/memory/ARCHITECTURE.md"
"$HUB_ROOT/scripts/sync-from-hub.sh" "$HUB_ROOT" "$TMP_PROJECT" > /dev/null

TESTS=$((TESTS + 1))
if grep -q "custom architecture" "$TMP_PROJECT/docs/memory/ARCHITECTURE.md"; then
  echo -e "  ${GREEN}✓${NC} ARCHITECTURE.md was not overwritten by sync"
else
  echo -e "  ${RED}✗${NC} ARCHITECTURE.md was overwritten by sync"
  FAILURES=$((FAILURES + 1))
fi

# --- Cleanup ---
rm -rf "$TMP_PROJECT"

# --- Summary ---
echo ""
if [ "$FAILURES" -eq 0 ]; then
  echo -e "${GREEN}All $TESTS tests passed.${NC}"
  exit 0
else
  echo -e "${RED}$FAILURES of $TESTS tests failed.${NC}"
  exit 1
fi
