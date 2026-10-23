const PREFIX = "enc:v1:";

/**
 * AES-256-GCM over Web Crypto, so it runs on Workers as well as Node and Bun.
 * The AES key is the SHA-256 of the host's secret; a stored value is
 * `enc:v1:` + base64(iv ‖ ciphertext).
 */
export class SecretBox {
	private key: Promise<CryptoKey> | null = null;

	constructor(private readonly secret: string) {}

	static isSealed(stored: string): boolean {
		return stored.startsWith(PREFIX);
	}

	async seal(plain: string): Promise<string> {
		const iv = crypto.getRandomValues(new Uint8Array(12));
		const sealed = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await this.cryptoKey(), new TextEncoder().encode(plain)));
		const out = new Uint8Array(iv.length + sealed.length);
		out.set(iv);
		out.set(sealed, iv.length);
		return PREFIX + toBase64(out);
	}

	/** The plain text, or null when the value was sealed with another key or was tampered with. */
	async open(stored: string): Promise<string | null> {
		if (!SecretBox.isSealed(stored)) {
			return null;
		}
		try {
			const bytes = fromBase64(stored.slice(PREFIX.length));
			const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: bytes.slice(0, 12) }, await this.cryptoKey(), bytes.slice(12));
			return new TextDecoder().decode(plain);
		} catch {
			return null;
		}
	}

	private cryptoKey(): Promise<CryptoKey> {
		this.key ??= crypto.subtle
			.digest("SHA-256", new TextEncoder().encode(this.secret))
			.then((raw) => crypto.subtle.importKey("raw", raw, { name: "AES-GCM" }, false, ["encrypt", "decrypt"]));
		return this.key;
	}
}

function toBase64(bytes: Uint8Array): string {
	let binary = "";
	for (const byte of bytes) {
		binary += String.fromCharCode(byte);
	}
	return btoa(binary);
}

function fromBase64(text: string): Uint8Array {
	return Uint8Array.from(atob(text), (char) => char.charCodeAt(0));
}
