#!/usr/bin/env bash
# PreToolUse hook: block edits to migrations that already exist in origin/main.
# Modifying a tracked migration leaves deployed databases on the old schema —
# Laravel records migrations by filename so a re-run is a silent no-op.
# Add a forward migration instead.
#
# Bypass: ALLOW_MIGRATION_EDITS=1 (intended for the pre-launch test-data
# window where a fresh-and-reseed is acceptable on every consumer).
set -euo pipefail

if [ "${ALLOW_MIGRATION_EDITS:-}" = "1" ]; then
  exit 0
fi

# Pull file_path out of the hook's stdin JSON. Python3 stays portable
# without requiring jq to be installed.
file_path=$(python3 -c '
import json, sys
try:
    print(json.load(sys.stdin).get("tool_input", {}).get("file_path", ""))
except Exception:
    print("")
')

if [ -z "$file_path" ]; then
  exit 0
fi

# Match both layouts:
#   in-other-worlds: database/migrations/*.php
#   in-other-shops:  src/<Domain>/Database/Migrations/*.php
case "$file_path" in
  */database/migrations/*.php|*/Database/Migrations/*.php) ;;
  *) exit 0 ;;
esac

abs_path=$(realpath "$file_path" 2>/dev/null || echo "$file_path")
git_root=$(cd "$(dirname "$abs_path")" 2>/dev/null && git rev-parse --show-toplevel 2>/dev/null || echo "")

if [ -z "$git_root" ]; then
  exit 0
fi

rel_path="${abs_path#"$git_root"/}"

cd "$git_root"

# No origin/main locally? Nothing has been deployed via this remote — allow.
if ! git rev-parse --verify --quiet origin/main >/dev/null; then
  exit 0
fi

# File never appeared in origin/main? Brand-new migration — allow.
# (Capture instead of piping to grep -q so pipefail + SIGPIPE doesn't lie.)
matches=$(git log --oneline origin/main -- "$rel_path" 2>/dev/null || true)
if [ -z "$matches" ]; then
  exit 0
fi

reason="Modifying a tracked migration drifts deployed schemas — Laravel skips re-running migrations by filename, so the live DB stays on the old shape until a manual fresh-migrate.

File: $rel_path

Add a forward migration (e.g. an add_x_to_y_table migration with up/down deltas) and leave the original alone.

Bypass (only safe pre-launch on test data): set ALLOW_MIGRATION_EDITS=1 in the shell that launched Claude Code."

python3 -c '
import json, sys
print(json.dumps({
    "hookSpecificOutput": {
        "hookEventName": "PreToolUse",
        "permissionDecision": "deny",
        "permissionDecisionReason": sys.argv[1],
    }
}))
' "$reason"
