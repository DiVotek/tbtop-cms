import { createFileRoute } from "@tanstack/react-router";
import { site } from "../../server/cms";

// The admin is one fetch handler: every method under /admin goes to it.
async function handle({ request }: { request: Request }) {
	return (await (await site()).admin.handle(request)) ?? new Response("Not found", { status: 404 });
}

export const Route = createFileRoute("/admin/$")({
	server: { handlers: { GET: handle, POST: handle, PUT: handle, PATCH: handle, DELETE: handle } },
});
