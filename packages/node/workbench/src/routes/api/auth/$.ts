import { createFileRoute } from "@tanstack/react-router";
import { auth, site } from "../../../server/cms";

// Better Auth's own HTTP API (a host's client-side sign-in, OAuth callbacks).
// The admin panel signs in through its own page and does not need it.
async function handle({ request }: { request: Request }) {
	await site();
	return auth.handler(request);
}

export const Route = createFileRoute("/api/auth/$")({
	server: { handlers: { GET: handle, POST: handle } },
});
