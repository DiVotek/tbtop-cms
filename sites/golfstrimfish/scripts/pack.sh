#!/usr/bin/env bash
set -euo pipefail

node_pkg="$(cd "$(dirname "$0")/../../../packages/node" && pwd)"
packs="$(cd "$(dirname "$0")/.." && pwd)/.packs"
mkdir -p "$packs"

if [[ ! -d "$node_pkg/.tbtop/packages/node" ]]; then
	echo "Run from repo root: packages/node/scripts/fetch-admin.sh" >&2
	exit 1
fi

(cd "$node_pkg" && bun run build >/dev/null && bun pm pack --quiet --filename "$packs/tbtop-cms.tgz" >/dev/null)
(cd "$node_pkg/.tbtop/packages/node" && bun pm pack --quiet --filename "$packs/tbtop-admin.tgz" >/dev/null)
echo "packed into $packs"
