import { readFile } from "node:fs/promises";
import { extname, resolve, sep } from "node:path";
import { createFileRoute } from "@tanstack/react-router";
import { STORAGE } from "../../server/cms";

const TYPES: Record<string, string> = { ".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".webp": "image/webp", ".svg": "image/svg+xml", ".gif": "image/gif" };

// The public upload disk, read-only. A real host serves it from its CDN or static server.
export const Route = createFileRoute("/storage/$")({
	server: {
		handlers: {
			GET: async ({ params }) => {
				const root = resolve(STORAGE);
				const file = resolve(root, params._splat ?? "");
				if (!file.startsWith(root + sep)) {
					return new Response(null, { status: 404 });
				}
				const body = await readFile(file).catch(() => null);
				return body === null
					? new Response(null, { status: 404 })
					: new Response(body, { headers: { "content-type": TYPES[extname(file)] ?? "application/octet-stream" } });
			},
		},
	},
});
