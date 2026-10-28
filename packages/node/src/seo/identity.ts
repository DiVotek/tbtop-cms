import { DAYS } from "../content/builtInCollections";
import type { Settings } from "../content/settings";
import { column, type SiteContacts } from "../content/siteContacts";

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
const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

const isRecord = (value: unknown): value is Record<string, unknown> =>
	typeof value === "object" && value !== null && !Array.isArray(value);

/** The site's schema.org identity from `seo.identity.*` and the Offices and Socials collections (SiteIdentity.php). */
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

	/**
	 * legalName, then the main office's telephone, email, address and (for a
	 * local business) geo and opening hours; the other offices as `department`.
	 */
	async properties(locale: string): Promise<Record<string, unknown>> {
		if (!(await this.isOrganisation())) {
			return {};
		}
		const [main, ...others] = await this.contacts.offices(locale);
		const legalName = await this.text("seo.identity.legal_name");
		const out: Record<string, unknown> = { ...(legalName === "" ? {} : { legalName }), ...(main === undefined ? {} : await this.office(main)) };
		const departmentType = (await this.schemaType()) ?? "Organization";
		const departments: Record<string, unknown>[] = [];
		for (const office of others) {
			const name = typeof office.name === "string" ? office.name.trim() : "";
			departments.push({ "@type": departmentType, ...(name === "" ? {} : { name }), ...(await this.office(office)) });
		}
		if (departments.length > 0) {
			out.department = departments;
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

	private async office(office: Record<string, unknown>): Promise<Record<string, unknown>> {
		const telephone = column(office.phones, "number")[0] ?? "";
		const email = column(office.emails, "address")[0] ?? "";
		const out: Record<string, unknown> = { ...(telephone === "" ? {} : { telephone }), ...(email === "" ? {} : { email }) };
		const address = SiteIdentity.address(office);
		if (address !== null) {
			out.address = address;
		}
		if ((await this.type()) !== "local_business") {
			return out;
		}
		const geo = SiteIdentity.geo(office.latitude, office.longitude);
		if (geo !== null) {
			out.geo = geo;
		}
		const hours = SiteIdentity.openingHours(office.opening_hours);
		if (hours.length > 0) {
			out.openingHoursSpecification = hours;
		}
		return out;
	}

	/** An office's PostalAddress; null without a street and a city. */
	static address(office: Record<string, unknown>): Record<string, unknown> | null {
		const text = (key: string) => (typeof office[key] === "string" ? (office[key] as string).trim() : "");
		if (text("street") === "" && text("locality") === "") {
			return null;
		}
		const country = text("country").toUpperCase();
		const fields: Record<string, string> = {
			streetAddress: text("street"),
			addressLocality: text("locality"),
			addressRegion: text("region"),
			postalCode: text("postal_code"),
			addressCountry: /^[A-Z]{2}$/.test(country) ? country : "",
		};
		return { "@type": "PostalAddress", ...Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== "")) };
	}

	static geo(latitude: unknown, longitude: unknown): Record<string, unknown> | null {
		const [lat, lng] = [latitude, longitude].map((value) => (typeof value === "number" || (typeof value === "string" && value.trim() !== "") ? Number(value) : Number.NaN)) as [number, number];
		if (Number.isNaN(lat) || Number.isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
			return null;
		}
		return { "@type": "GeoCoordinates", latitude: lat, longitude: lng };
	}

	static openingHours(rows: unknown): Record<string, unknown>[] {
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
			out.push({ "@type": "OpeningHoursSpecification", dayOfWeek: days.map((day) => `https://schema.org/${day}`), opens, closes });
		}
		return out;
	}

	private async text(key: string): Promise<string> {
		const value = await this.settings.get(key);
		return typeof value === "string" ? value.trim() : "";
	}
}
