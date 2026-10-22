#!/usr/bin/env bash
# Builds @tbtop/admin from the tbtop monorepo at the SHA pinned in ADMIN_SHA,
# into .tbtop/packages/node, which package.json installs as a file: dependency.
# Run it before `bun install`. TBTOP_REPO overrides the clone source.
set -euo pipefail
cd "$(dirname "$0")/.."
sha="$(tr -d '[:space:]' < ADMIN_SHA)"
repo="${TBTOP_REPO:-https://github.com/DiVotek/tbtop.git}"
if [ "$(git -C .tbtop rev-parse HEAD 2>/dev/null || true)" != "$sha" ]; then
	rm -rf .tbtop
	git init -q .tbtop
	git -C .tbtop fetch -q --depth 1 "$repo" "$sha"
	git -C .tbtop checkout -q FETCH_HEAD
fi
cd .tbtop/packages/node
bun install --frozen-lockfile
bun run build
