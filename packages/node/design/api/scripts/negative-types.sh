#!/usr/bin/env bash
# The typed-fields proof must be able to fail: in each variant's Node entry,
# claim `price` is a string and expect tsc to refuse exactly there.
set -u
cd "$(dirname "$0")/../../.."
status=0
for file in design/api/v1-compose/site.ts design/api/v2-config/node.ts design/api/v3-plugins/node.ts design/api/v4-builder/node.ts design/api/v5-zero/node.ts; do
	cp "$file" "$file.bak"
	sed -i 's/props.page.fields.price satisfies number | null/props.page.fields.price satisfies string/' "$file"
	if bunx tsc --noEmit -p design/api 2>&1 | grep -q "$file.*TS1360"; then
		echo "ok   $file: a wrong field type fails tsc"
	else
		echo "FAIL $file: page.fields is not typed"
		status=1
	fi
	mv "$file.bak" "$file"
done
exit $status
