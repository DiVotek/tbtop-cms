/**
 * PHP's filter_var() validators, for values PHP stores and Node must judge
 * the same way (LinkTarget::isWellFormed).
 */

/** FILTER_VALIDATE_EMAIL without FILTER_FLAG_EMAIL_UNICODE: php-src's regex, verbatim. */
const EMAIL =
	/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/i;

/** FILTER_VALIDATE_EMAIL: at most 320 characters, matching php-src's regex. */
export function isPhpEmail(value: string): boolean {
	return value.length <= 320 && EMAIL.test(value);
}

/** What FILTER_SANITIZE_URL keeps: a URL holding anything else fails validation. */
const URL_CHARS = /^[A-Za-z0-9$\-_.+!*'(),{}|\\^~[\]`<>#%";/?:@&=]+$/;
const USERINFO = /^(?:[A-Za-z0-9\-._~!$&'()*+,;=:]|%[0-9A-Fa-f]{2})*$/;

/**
 * FILTER_VALIDATE_URL for an http(s) URL: ASCII only (no IDN, as in PHP),
 * an authority with a hostname or a bracketed IPv6 host, a numeric port.
 */
export function isPhpHttpUrl(value: string): boolean {
	if (!URL_CHARS.test(value)) {
		return false;
	}
	const authority = /^https?:\/\/([^/?#]*)/i.exec(value)?.[1];
	if (authority === undefined) {
		return false;
	}
	const at = authority.lastIndexOf("@");
	if (at !== -1 && !userinfoIsValid(authority.slice(0, at))) {
		return false;
	}
	const hostPort = authority.slice(at + 1);
	const bracketed = /^\[([^\]]*)\](?::(.*))?$/.exec(hostPort);
	if (bracketed !== null) {
		return portIsValid(bracketed[2]) && isIpv6(bracketed[1] ?? "");
	}
	const [host = "", port, ...rest] = hostPort.split(":");
	return rest.length === 0 && portIsValid(port) && isHostname(host);
}

function userinfoIsValid(userinfo: string): boolean {
	const colon = userinfo.indexOf(":");
	const parts = colon === -1 ? [userinfo] : [userinfo.slice(0, colon), userinfo.slice(colon + 1)];
	return parts.every((part) => USERINFO.test(part));
}

/** parse_url: up to five characters, read by strtol, so trailing junk after the digits is ignored. */
function portIsValid(port: string | undefined): boolean {
	if (port === undefined || port === "") {
		return true;
	}
	const digits = /^[+-]?\d+/.exec(port)?.[0];
	return port.length <= 5 && digits !== undefined && Number(digits) >= 0 && Number(digits) <= 65535;
}

function isIpv6(host: string): boolean {
	try {
		return new URL(`http://[${host}]/`).hostname !== "";
	} catch {
		return false;
	}
}

/** _php_filter_validate_domain with FILTER_FLAG_HOSTNAME. */
function isHostname(host: string): boolean {
	const name = host.endsWith(".") ? host.slice(0, -1) : host;
	if (name === "" || name.length > 253) {
		return false;
	}
	const labels = name.split(".");
	const last = labels.pop() ?? "";
	// php-src never checks the name's final character, so only a trailing hyphen slips through.
	return labels.every((label) => /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/.test(label)) && /^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/.test(last);
}
