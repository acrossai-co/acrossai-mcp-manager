#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 2 ]; then
  echo "Usage: ./scripts/install-into-project.sh <hub_repo_path> <target_project_path>"
  exit 1
fi

HUB_ROOT="$1"
TARGET_ROOT="$2"

mkdir -p "$TARGET_ROOT/docs/memory"
mkdir -p "$TARGET_ROOT/specs"
mkdir -p "$TARGET_ROOT/.github"

copy_if_missing() {
  SRC="$1"
  DST="$2"
  if [ ! -e "$DST" ]; then
    cp "$SRC" "$DST"
    echo "[added] $DST"
  else
    echo "[kept]  $DST"
  fi
}

copy_if_missing "$HUB_ROOT/templates/.github/copilot-instructions.md" "$TARGET_ROOT/.github/copilot-instructions.md"

for f in INDEX.md PROJECT_CONTEXT.md ARCHITECTURE.md DECISIONS.md BUGS.md WORKLOG.md; do
  copy_if_missing "$HUB_ROOT/templates/docs/memory/$f" "$TARGET_ROOT/docs/memory/$f"
done

copy_if_missing "$HUB_ROOT/templates/specs/README.md" "$TARGET_ROOT/specs/README.md"

echo
echo "Project starter installed."
echo "Next steps:"
echo "1. Customize docs/memory/PROJECT_CONTEXT.md"
echo "2. Add compact routing rows to docs/memory/INDEX.md"
echo "3. Customize docs/memory/ARCHITECTURE.md"
echo "4. Create your first spec folder in specs/"
