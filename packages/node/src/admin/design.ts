import { type ActionCtx, Column, definePage, Effects, HttpError, S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { SLOTS, type Slot } from "../content/layouts";
import { RedirectError } from "../content/redirects";
import { SharedBlockConflictError, SharedBlockInUseError } from "../content/sharedBlocks";
import { type Dict, isDict } from "../support/values";
import { bindCmsFields } from "../blocks/cmsFields";
import { fieldBinding } from "./linkFields";
import { adminPrefix, blockRecord, blockRows, blockTabs, NAV, prefixedForm, rowProblems, rowsFromForm, siteOf, trimmedForm } from "./support";

const SLOT_LABELS: Record<Slot, string> = { header: "Header", footer: "Footer" };

/** Layout (LayoutPage + LayoutEditPage): the header and footer block lists. */
export function layoutIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "layout",
		title: "Layout",
		nav: { group: NAV.design, label: "Layout", icon: "panels-top-left", order: 20 },
		view: async (s, { request }) => {
			const content = await cms.site(request).layouts.content();
			return s.stack(
				SLOTS.map((slot) =>
					s.section({ title: SLOT_LABELS[slot] }, [
						s.displayText(`${content[slot].length} block(s)`).variant("muted"),
						s.row([s.action(`edit_${slot}`).label(`Edit ${SLOT_LABELS[slot].toLowerCase()}`).url(`${prefix}/layout/${slot}`)]),
					]),
				),
			);
		},
	});
}

export function layoutEdit(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "layout/$slot",
		title: "Edit layout",
		breadcrumbs: [{ label: "Design" }, { label: "Layout", url: `${prefix}/layout` }, { label: "Edit" }],
		view: async (s, { params, request }) => {
			const slot = params.slot as Slot;
			if (!(SLOTS as readonly string[]).includes(slot)) {
				throw new HttpError(404, "No such layout slot.");
			}
			const site = cms.site(request);
			const rows = blockRows((await site.layouts.content())[slot]);
			const back = `${prefix}/layout/${slot}`;
			const save = async (ctx: ActionCtx, content: Dict[]) => siteOf(cms, ctx).layouts.save(slot, content);
			const sharedNames = new Map((await site.sharedBlocks.all()).map((block) => [block.id, block.name]));
			const problems = await rowProblems(site, rows);
			return s
				.form(`layout_${slot}`, [
					s.displayText(SLOT_LABELS[slot]).variant("heading"),
					s.tabs(blockTabs(s, cms, rows, { placement: "layout", save, back, sharedNames, problems, skipsValidation: false })),
					s.actionsRow([s.action("save").label("Save").color("primary").keybinding("mod+s").submit()]),
				])
				.record(blockRecord(cms.config.blocks, rows))
				.onSubmit(async (submitted) => {
					const ctx = trimmedForm(submitted);
					await save(ctx, rowsFromForm(cms.config.blocks, rows, ctx.form as Dict));
					return Effects.make().notify("Saved").redirect(back);
				});
		},
	});
}

/** Shared blocks (SharedBlocksPage + SharedBlockEditPage). */
export function sharedBlocksIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	// Every registered kind, as SharedBlockForm::kindOptions: a shared block may serve any placement.
	const kinds = [...cms.config.blocks.all()].sort((a, b) => a.label.localeCompare(b.label));
	return definePage({
		path: "shared-blocks",
		title: "Shared blocks",
		nav: { group: NAV.design, label: "Shared blocks", icon: "copy", order: 45 },
		view: async (s, { request }) => {
			const usage = await cms.site(request).sharedBlocks.usageCounts();
			return s.stack([
				s.row([
					s
						.action("new")
						.label("New shared block")
						.icon("plus")
						.color("primary")
						.modal(
							"New shared block",
							s
								.form("sharedBlockCreate", [
									s.text("name").label("Name").required().rules("max:255"),
									s
										.select("kind")
										.label("Block kind")
										.required()
										.options(kinds.map((block) => ({ value: block.kind, label: block.label }))),
									s.actionsRow([s.action("create").label("Create").color("primary").submit()]),
								])
								.onSubmit(async (submitted) => {
									const ctx = trimmedForm(submitted);
									const form = ctx.form as Dict;
									const kind = String(form.kind ?? "");
									if (!cms.config.blocks.has(kind)) {
										return Effects.make().notify("Unknown block kind.", "error");
									}
									const id = await siteOf(cms, ctx).sharedBlocks.create({ name: String(form.name), kind, data: {} });
									return `${prefix}/shared-blocks/${id}/edit`;
								}),
						),
				]),
				s
					.table("sharedBlocks")
					.columns([
						Column.make("name").label("Name").emphasized().searchable().sortable(),
						Column.make("kind")
							.label("Kind")
							.formatUsing((value) => (cms.config.blocks.has(String(value)) ? cms.config.blocks.get(String(value)).label : String(value))),
						Column.make("id")
							.label("Usage")
							.formatUsing((value) => placedIn(usage.get(Number(value)) ?? 0)),
						Column.make("updated_at").label("Updated").datetime().sortable(),
					])
					.defaultSort("name", "asc")
					.recordUrl((row) => `${prefix}/shared-blocks/${String(row.id)}/edit`)
					.rowActions([
						s
							.action("delete")
							.label("Delete")
							.color("danger")
							.confirm("Delete this shared block?", "A block still placed on a page or layout cannot be deleted.")
							.handle(async (ctx) => {
								try {
									await siteOf(cms, ctx).sharedBlocks.delete(Number(ctx.key()));
								} catch (error) {
									if (error instanceof SharedBlockInUseError) {
										return Effects.make().notify(error.message, "error");
									}
									throw error;
								}
								return Effects.make().notify("Deleted").refreshTable("sharedBlocks");
							}, ["row"]),
					])
					.query(() => cms.site(request).config.db.selectFrom("tbtop_shared_blocks")),
			]);
		},
	});
}

/** The usage line (shared-blocks.usage_count). */
function placedIn(count: number): string {
	return count === 0 ? "Not placed anywhere" : `Placed in ${count} ${count === 1 ? "place" : "places"}`;
}

export function sharedBlockEdit(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "shared-blocks/$block/edit",
		title: "Edit shared block",
		breadcrumbs: [{ label: "Design" }, { label: "Shared blocks", url: `${prefix}/shared-blocks` }, { label: "Edit" }],
		view: async (s, { params, request }) => {
			const block = await cms.site(request).sharedBlocks.find(Number(params.block));
			if (block === null) {
				throw new HttpError(404, "Shared block not found.");
			}
			if (!cms.config.blocks.has(block.kind)) {
				return s.displayText(`Block kind ${block.kind} is not registered.`);
			}
			const names = cms.config.blocks
				.get(block.kind)
				.form(new S())
				.map((field) => (field as { name?: string }).name)
				.filter((name): name is string => typeof name === "string");
			return s
				.form("sharedBlock", [
					s.number("lock_version").meta("hidden", true),
					s.text("shared_name").label("Name").required().rules("max:255"),
					s.section({ title: cms.config.blocks.get(block.kind).label }, bindCmsFields(prefixedForm(cms.config.blocks, block.kind, "shared", s), fieldBinding(cms))),
					s.actionsRow([
						s.action("save").label("Save").color("primary").keybinding("mod+s").submit(),
						s.action("back").label("Back").url(`${prefix}/shared-blocks`),
					]),
				])
				.record({
					lock_version: block.lockVersion,
					shared_name: block.name,
					...Object.fromEntries(names.filter((name) => name in block.data).map((name) => [`block_shared_${name}`, block.data[name]])),
				})
				.onSubmit(async (submitted) => {
					const ctx = trimmedForm(submitted);
					const form = ctx.form as Dict;
					const data: Dict = {};
					for (const name of names) {
						if (`block_shared_${name}` in form) {
							data[name] = form[`block_shared_${name}`];
						}
					}
					try {
						await siteOf(cms, ctx).sharedBlocks.update(
							block.id,
							{ name: String(form.shared_name ?? block.name), data: isDict(data) ? data : {} },
							{ lockVersion: typeof form.lock_version === "number" ? form.lock_version : Number(form.lock_version ?? block.lockVersion) },
						);
					} catch (error) {
						if (error instanceof SharedBlockConflictError) {
							return Effects.make().notify("This shared block was changed by someone else while you were editing; your edits were not applied. Reload to see the latest version.", "error");
						}
						throw error;
					}
					return Effects.make().notify("Saved");
				});
		},
	});
}

/** Redirects (RedirectsPage): from path, target, status. */
export function redirectsIndex(cms: Cms) {
	return definePage({
		path: "redirects",
		title: "Redirects",
		nav: { group: NAV.content, label: "Redirects", icon: "corner-up-right", order: 20 },
		view: async (s, { request }) => {
			const locales = (await cms.site(request).languages.frontendActive()).map((row) => ({ value: row.locale, label: row.name }));
			return s.stack([
				s.row([
					s
						.action("new")
						.label("New redirect")
						.icon("plus")
						.color("primary")
						.modal(
							"New redirect",
							s
								.form("redirectCreate", [
									s.text("from_path").label("From path").required().placeholder("old/path").rules("max:600"),
									s.text("to_path").label("To path or URL").required().placeholder("new/path").rules("max:2048"),
									s
										.select("status_code")
										.label("Status")
										.options([
											{ value: "301", label: "301 Moved permanently" },
											{ value: "302", label: "302 Found" },
										])
										.default("301"),
									s.select("locale").label("Language").options(locales).helperText("Empty: every language."),
									s.actionsRow([s.action("create").label("Create").color("primary").submit()]),
								])
								.onSubmit(async (submitted) => {
									const ctx = trimmedForm(submitted);
									const form = ctx.form as Dict;
									try {
										await siteOf(cms, ctx).redirects.create({
											fromPath: String(form.from_path ?? ""),
											toPath: String(form.to_path ?? ""),
											statusCode: Number(form.status_code ?? 301),
											locale: typeof form.locale === "string" && form.locale !== "" ? form.locale : null,
										});
									} catch (error) {
										if (error instanceof RedirectError) {
											return Effects.make().haltModal(error.message);
										}
										throw error;
									}
									return Effects.make().notify("Redirect created").closeModal().refreshTable("redirects");
								}),
						),
				]),
				s
					.table("redirects")
					.columns([
						Column.make("from_path").label("From").searchable().sortable(),
						Column.make("to_path").label("To").searchable(),
						Column.make("status_code").label("Status"),
						Column.make("locale").label("Language"),
						Column.make("is_auto").label("Automatic").boolean(),
					])
					.defaultSort("from_path", "asc")
					.paginate(25, [25, 50, 100])
					.rowActions([
						s
							.action("delete")
							.label("Delete")
							.color("danger")
							.confirm("Delete this redirect?")
							.handle(async (ctx) => {
								await cms.config.db.deleteFrom("tbtop_redirects").where("id", "=", Number(ctx.key())).execute();
								return Effects.make().notify("Deleted").refreshTable("redirects");
							}, ["row"]),
					])
					.query(() => cms.config.db.selectFrom("tbtop_redirects")),
			]);
		},
	});
}
