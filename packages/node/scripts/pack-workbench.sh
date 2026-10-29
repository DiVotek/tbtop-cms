#!/usr/bin/env bash
# Packs @tbtop/cms and the fetched @tbtop/admin into workbench/.packs, so the
# workbench installs them the way a host would: from tarballs, through their
# `exports` and `files`, with no path into this source tree.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
packs="$root/workbench/.packs"
mkdir -p "$packs"

(cd "$root" && bun run build >/dev/null && bun pm pack --quiet --filename "$packs/tbtop-cms.tgz" >/dev/null)
(cd "$root/.tbtop/packages/node" && bun pm pack --quiet --filename "$packs/tbtop-admin.tgz" >/dev/null)
echo "packed into $packs"
