#!/usr/bin/env bash
set -euo pipefail

site_root="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$site_root/../../packages/node" && pwd)"
packs="$site_root/.packs"
mkdir -p "$packs"

(cd "$root" && bun run build >/dev/null && bun pm pack --quiet --filename "$packs/tbtop-cms.tgz" >/dev/null)
(cd "$root/.tbtop/packages/node" && bun pm pack --quiet --filename "$packs/tbtop-admin.tgz" >/dev/null)
echo "packed CMS tarballs into $packs"
