/** Str::slug(): transliterate to ASCII, lowercase, dashes between words. */

const CYRILLIC: Record<string, string> = {
	а: "a", б: "b", в: "v", г: "g", ґ: "g", д: "d", е: "e", є: "ye", ё: "yo", ж: "zh", з: "z",
	и: "i", і: "i", ї: "yi", й: "y", к: "k", л: "l", м: "m", н: "n", о: "o", п: "p", р: "r",
	с: "s", т: "t", у: "u", ф: "f", х: "h", ц: "ts", ч: "ch", ш: "sh", щ: "shch", ъ: "",
	ы: "y", ь: "", э: "e", ю: "yu", я: "ya",
};

const SPECIAL: Record<string, string> = { "@": "-at-", ß: "ss", æ: "ae", ø: "o", œ: "oe", ł: "l", đ: "d" };

export function slugify(title: string, separator = "-"): string {
	const ascii = [...title.toLowerCase()]
		.map((char) => CYRILLIC[char] ?? SPECIAL[char] ?? char)
		.join("")
		.normalize("NFKD")
		.replace(/[̀-ͯ]/g, "");
	return ascii
		.replace(/[^a-z0-9\s_-]+/g, "")
		.replace(/[\s_-]+/g, separator)
		.replace(new RegExp(`^${separator}+|${separator}+$`, "g"), "");
}

/** mb_substr to `max` characters, trailing dashes trimmed. */
export const capSlug = (slug: string, max = 120): string => [...slug].slice(0, max).join("").replace(/-+$/, "");
