import { ASCII_EN } from "./asciiMap";

/** voku's REGEX_PRINTABLE_ASCII: a character outside printable ASCII. */
const NON_PRINTABLE = /[^\x20-\x7E]/;
const ALL_NON_PRINTABLE = /^[^\x20-\x7E]+$/;
const LONGEST_KEY = Math.max(...Object.keys(ASCII_EN).map((key) => [...key].length));

/**
 * ASCII::to_ascii($s, 'en') as Str::ascii calls it: a longest-match `strtr` over
 * the generated map, then everything outside ASCII dropped. Strings of at most 64
 * bytes take voku's short path, which only matches keys made of non-printable-ASCII
 * characters (unless a Latin letter carries a combining mark), so "Aι" stays apart there.
 */
export function toAscii(value: string): string {
	if (!NON_PRINTABLE.test(value)) {
		return value;
	}
	const short = new TextEncoder().encode(value).length <= 64 && !/[A-Za-z][̀-ͯ]/.test(value);
	const chars = [...value];
	let out = "";
	for (let i = 0; i < chars.length; ) {
		const length = matchLength(chars, i, short);
		out += length === 0 ? chars[i] : ASCII_EN[chars.slice(i, i + length).join("")];
		i += Math.max(length, 1);
	}
	return out.replace(/\r\n|\n|\r|\t/g, " ").replace(/[^\x09\x10\x13\x0A\x0D\x20-\x7E]/g, "");
}

function matchLength(chars: string[], at: number, short: boolean): number {
	for (let length = Math.min(LONGEST_KEY, chars.length - at); length > 0; length--) {
		const key = chars.slice(at, at + length).join("");
		if (Object.hasOwn(ASCII_EN, key) && (!short || ALL_NON_PRINTABLE.test(key))) {
			return length;
		}
	}
	return 0;
}

/** Str::slug($title, $separator, 'en', ['@' => 'at']): transliterate to ASCII, lowercase, separators between words. */
export function slugify(title: string, separator = "-"): string {
	const sep = escapeRegExp(separator);
	const flip = escapeRegExp(separator === "-" ? "_" : "-");
	return toAscii(title)
		.replace(new RegExp(`[${flip}]+`, "g"), separator)
		.replaceAll("@", `${separator}at${separator}`)
		.toLowerCase()
		.replace(new RegExp(`[^${sep}a-z0-9\\s]+`, "g"), "")
		.replace(new RegExp(`[${sep}\\s]+`, "g"), separator)
		.replace(new RegExp(`^(?:${sep})+|(?:${sep})+$`, "g"), "");
}

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\-]/g, "\\$&");

/** mb_substr to `max` characters, trailing dashes trimmed. */
export const capSlug = (slug: string, max = 120): string => [...slug].slice(0, max).join("").replace(/-+$/, "");
