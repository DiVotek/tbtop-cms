import type { Settings } from "../content/settings";
import type { SiteContacts } from "../content/siteContacts";
import { localizedWithFallback } from "./localized";

export const ORGANISATION_TYPES = ["organization", "online_store", "local_business"];
export const IDENTITY_TYPES = ["none", "person", ...ORGANISATION_TYPES];
export const BUSINESS_SUBTYPES = [
	"LocalBusiness",
	"AutoRepair",
	"ElectronicsStore",
	"Store",
	"ProfessionalService",
	"HomeAndConstructionBusiness",
	"MedicalBusiness",
	"Dentist",
	"LegalService",
	"FinancialService",
	"BeautySalon",
	"Restaurant",
	"CafeOrCoffeeShop",
	"LodgingBusiness",
	"SportsActivityLocation",
];
const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

const isRecord = (value: unknown): value is Record<string, unknown> =>
	typeof value === "object" && value !== null && !Array.isArray(value);

/** The site's schema.org identity from `seo.identity.*`; telephone, email and sameAs from the contact collections (SiteIdentity.php). */
export class SiteIdentity {
	constructor(
		private readonly settings: Settings,
		private readonly contacts: SiteContacts,
	) {}

	async type(): Promise<string> {
		return this.settings.string("seo.identity.type", "none");
	}

	async isOrganisation(): Promise<boolean> {
		return ORGANISATION_TYPES.includes(await this.type());
	}

	static schemaTypeFor(type: string): string | null {
		switch (type) {
			case "none":
				return null;
			case "person":
				return "Person";
			case "online_store":
				return "OnlineStore";
			case "local_business":
				return "LocalBusiness";
			default:
				return "Organization";
		}
	}

	async schemaType(): Promise<string | null> {
		const type = await this.type();
		return type === "local_business" ? this.subtype() : SiteIdentity.schemaTypeFor(type);
	}

	async properties(locale: string, fallback: string): Promise<Record<string, unknown>> {
		if (!(await this.isOrganisation())) {
			return {};
		}
		const out: Record<string, unknown> = {};
		const legalName = await this.text("seo.identity.legal_name");
		const telephone = (await this.contacts.phones(locale))[0] ?? "";
		const email = (await this.contacts.emails(locale))[0] ?? "";
		for (const [key, value] of Object.entries({ legalName, telephone, email })) {
			if (value !== "") {
				out[key] = value;
			}
		}
		const address = await this.address(locale, fallback);
		if (address !== null) {
			out.address = address;
		}
		if ((await this.type()) !== "local_business") {
			return out;
		}
		const geo = await this.geo();
		if (geo !== null) {
			out.geo = geo;
		}
		const hours = await this.openingHours();
		if (hours.length > 0) {
			out.openingHoursSpecification = hours;
		}
		return out;
	}

	/** Profile links of the Socials collection that are absolute http(s) URLs. */
	async sameAs(): Promise<string[]> {
		return (await this.contacts.socials()).map((social) => social.href).filter((href) => /^https?:\/\//i.test(href) && URL.canParse(href));
	}

	async name(): Promise<string> {
		const name = await this.settings.string("seo.identity.name");
		return name !== "" ? name : this.settings.string("site.name");
	}

	private async subtype(): Promise<string> {
		let subtype = await this.text("seo.identity.subtype");
		if (subtype === "other") {
			subtype = await this.text("seo.identity.subtype_custom");
		}
		return BUSINESS_SUBTYPES.includes(subtype) || /^[A-Z][A-Za-z]+$/.test(subtype) ? subtype : "LocalBusiness";
	}

	private async address(locale: string, fallback: string): Promise<Record<string, unknown> | null> {
		const translated = async (key: string) => localizedWithFallback(await this.settings.get(key), locale, fallback);
		const street = await translated("seo.identity.address.street");
		const locality = await translated("seo.identity.address.locality");
		if (street === "" && locality === "") {
			return null;
		}
		const country = (await this.text("seo.identity.address.country")).toUpperCase();
		const fields: Record<string, string> = {
			streetAddress: street,
			addressLocality: locality,
			addressRegion: await translated("seo.identity.address.region"),
			postalCode: await this.text("seo.identity.address.postal_code"),
			addressCountry: /^[A-Z]{2}$/.test(country) ? country : "",
		};
		return { "@type": "PostalAddress", ...Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== "")) };
	}

	private async geo(): Promise<Record<string, unknown> | null> {
		const [lat, lng] = [await this.settings.get("seo.identity.geo.latitude"), await this.settings.get("seo.identity.geo.longitude")].map(
			(value) => (typeof value === "number" || (typeof value === "string" && value.trim() !== "") ? Number(value) : Number.NaN),
		) as [number, number];
		if (Number.isNaN(lat) || Number.isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
			return null;
		}
		return { "@type": "GeoCoordinates", latitude: lat, longitude: lng };
	}

	private async openingHours(): Promise<Record<string, unknown>[]> {
		const rows = await this.settings.get("seo.identity.opening_hours", []);
		const out: Record<string, unknown>[] = [];
		for (const row of Array.isArray(rows) ? rows : []) {
			if (!isRecord(row)) {
				continue;
			}
			const picked = Array.isArray(row.days) ? row.days : [];
			const days = DAYS.filter((day) => picked.includes(day));
			const opens = typeof row.opens === "string" ? row.opens : "";
			const closes = typeof row.closes === "string" ? row.closes : "";
			if (days.length === 0 || !TIME.test(opens) || !TIME.test(closes)) {
				continue;
			}
			out.push({
				"@type": "OpeningHoursSpecification",
				dayOfWeek: days.map((day) => `https://schema.org/${day}`),
				opens,
				closes,
			});
		}
		return out;
	}

	private async text(key: string): Promise<string> {
		const value = await this.settings.get(key);
		return typeof value === "string" ? value.trim() : "";
	}
}
