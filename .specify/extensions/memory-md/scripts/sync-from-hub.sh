#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 2 ]; then
  echo "Usage: ./scripts/sync-from-hub.sh <hub_repo_path> <target_project_path>"
  exit 1
fi

HUB_ROOT="$1"
TARGET_ROOT="$2"

sync_file() {
  SRC="$1"
  DST="$2"
  cp "$SRC" "$DST"
  echo "[synced] $DST"
}

mkdir -p "$TARGET_ROOT/.github"

sync_file "$HUB_ROOT/templates/.github/copilot-instructions.md" "$TARGET_ROOT/.github/copilot-instructions.md"

echo
echo "Only shared standards files were synced."
echo "Project-specific memory files were intentionally not overwritten."
