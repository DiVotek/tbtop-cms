import type { CollectionDef } from "./collections";
import { safeHrefRule } from "./safeHref";

/** schema.org day names, as the opening hours store them (SiteIdentity::DAYS). */
export const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

export const OFFICES = "offices";
export const SOCIALS = "socials";

/**
 * The site's offices (BuiltIn/Offices.php): the first published one is the
 * main office, whose phones, emails, address, hours and coordinates are the
 * site's and its identity's; the others are the identity's departments.
 */
export const officesCollection: CollectionDef = {
	key: OFFICES,
	label: "Offices",
	singularLabel: "office",
	icon: "map-pin",
	navigationGroup: "settings",
	navigationOrder: 21,
	deletable: false,
	form: (s) => [
		s.text("name").label("Name").translatable().required().rules("max:255"),
		s.text("street").label("Street address").translatable().rules("nullable|max:255"),
		s.text("locality").label("City").translatable().rules("nullable|max:255"),
		s.text("region").label("Region").translatable().rules("nullable|max:255"),
		s.text("postal_code").label("Postal code").rules("nullable|string|max:20"),
		s.text("country").label("Country (ISO code, e.g. UA)").placeholder("UA").regex("/^[A-Za-z]{2}$/"),
		s
			.repeater("phones")
			.label("Phones")
			.fields([s.text("number").label("Number").placeholder("+380 44 000 00 00").required().rules("max:40")]),
		s
			.repeater("emails")
			.label("Emails")
			.fields([s.text("address").label("Address").required().rules("email|max:190")]),
		s
			.repeater("opening_hours")
			.label("Opening hours")
			.fields([
				s
					.select("days")
					.label("Days")
					.multiple()
					.options(DAYS.map((day) => ({ value: day, label: day })))
					.rules(`required|in:${DAYS.join(",")}`),
				s.time("opens").label("Opens").rules("required|date_format:H:i"),
				s.time("closes").label("Closes").rules("required|date_format:H:i"),
			]),
		s.number("latitude").label("Latitude").rules("nullable|numeric|between:-90,90"),
		s.number("longitude").label("Longitude").rules("nullable|numeric|between:-180,180"),
	],
};

/** Social profile links (BuiltIn/Socials.php): the identity's sameAs and the site's social icons. */
export const socialsCollection: CollectionDef = {
	key: SOCIALS,
	label: "Social links",
	singularLabel: "social link",
	titleField: "name",
	icon: "share-2",
	navigationGroup: "settings",
	navigationOrder: 22,
	deletable: false,
	form: (s) => [
		s.text("name").label("Name").placeholder("Telegram").rules("nullable|max:40"),
		s.text("label").label("Short label").placeholder("TG").required().rules("max:8"),
		s.text("href").label("Link").required().rules(["url", "max:255", safeHrefRule]),
	],
};

export const BUILT_IN_COLLECTIONS: readonly CollectionDef[] = [officesCollection, socialsCollection];
