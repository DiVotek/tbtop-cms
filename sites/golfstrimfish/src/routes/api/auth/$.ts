import { createFileRoute } from "@tanstack/react-router";
import { auth, site } from "../../../server/cms";

async function handle({ request }: { request: Request }) {
	await site();
	return auth.handler(request);
}

export const Route = createFileRoute("/api/auth/$")({
	server: { handlers: { GET: handle, POST: handle } },
});
