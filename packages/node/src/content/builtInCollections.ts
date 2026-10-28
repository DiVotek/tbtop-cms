import type { CollectionDef } from "./collections";
import { safeHrefRule } from "./safeHref";

export const PHONES = "phones";
export const EMAILS = "emails";
export const SOCIALS = "socials";

/** The site's phone numbers in the editor's order (BuiltIn/Phones.php): the first published one is the identity's `telephone`. */
export const phonesCollection: CollectionDef = {
	key: PHONES,
	label: "Phones",
	singularLabel: "phone",
	form: (s) => [s.text("number").label("Number").placeholder("+380 44 000 00 00").required().rules("max:40")],
};

/** The site's email addresses in the editor's order (BuiltIn/Emails.php): the first published one is the identity's `email`. */
export const emailsCollection: CollectionDef = {
	key: EMAILS,
	label: "Emails",
	singularLabel: "email",
	form: (s) => [s.text("address").label("Address").placeholder("hello@example.com").required().rules("email|max:190")],
};

/** Social profile links (BuiltIn/Socials.php): the identity's sameAs and the site's social icons. */
export const socialsCollection: CollectionDef = {
	key: SOCIALS,
	label: "Social links",
	singularLabel: "social link",
	titleField: "name",
	form: (s) => [
		s.text("name").label("Name").placeholder("Telegram").rules("nullable|max:40"),
		s.text("label").label("Short label").placeholder("TG").required().rules("max:8"),
		s.text("href").label("Link").required().rules(["url", "max:255", safeHrefRule]),
	],
};

export const BUILT_IN_COLLECTIONS: readonly CollectionDef[] = [phonesCollection, emailsCollection, socialsCollection];
