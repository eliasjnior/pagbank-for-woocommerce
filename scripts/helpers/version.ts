import { createHash } from "node:crypto";
import Fs from "node:fs";
import Path, { dirname } from "node:path";
import { fileURLToPath } from "node:url";

export const rootDir = Path.resolve(dirname(fileURLToPath(import.meta.url)), "../..");

export const readFile = (file: string): string => Fs.readFileSync(Path.join(rootDir, file), "utf8");

export const writeFile = (file: string, contents: string): void =>
	Fs.writeFileSync(Path.join(rootDir, file), contents);

/**
 * Every place that declares the plugin version. The first capture group is the version.
 */
export const versionDeclarations: Array<{ label: string; file: string; pattern: RegExp }> = [
	{ label: "package.json version", file: "package.json", pattern: /^\t"version": "([^"]+)"/m },
	{
		label: "composer.json version",
		file: "composer.json",
		pattern: /^ {4}"version": "([^"]+)"/m,
	},
	{
		label: "plugin header Version",
		file: "pagbank-for-woocommerce.php",
		pattern: /^ \* Version: (\S+)$/m,
	},
	{
		label: "PAGBANK_WOOCOMMERCE_VERSION",
		file: "pagbank-for-woocommerce.php",
		pattern: /define\( 'PAGBANK_WOOCOMMERCE_VERSION', '([^']+)' \)/,
	},
	{
		label: "phpstan-bootstrap.php constant",
		file: "phpstan-bootstrap.php",
		pattern: /define\( 'PAGBANK_WOOCOMMERCE_VERSION', '([^']+)' \)/,
	},
	{ label: "readme.txt Stable tag", file: "readme.txt", pattern: /^Stable tag: (\S+)$/m },
	{
		label: "translation template Project-Id-Version",
		file: "languages/pagbank-for-woocommerce.pot",
		pattern: /^"Project-Id-Version: PagBank for WooCommerce (\S+)\\n"$/m,
	},
];

export const currentVersion = (): string => JSON.parse(readFile("package.json")).version;

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

export const changelogHeading = (version: string): RegExp =>
	new RegExp(`^= ${escapeRegExp(version)} - \\d{4}-\\d{2}-\\d{2} =$`, "m");

/**
 * Mirrors PHP's json_encode() without flags: escaped slashes, \uXXXX for non-ASCII
 * and empty objects as [] (Composer decodes composer.json into arrays).
 */
const phpJsonEncode = (value: unknown): string => {
	if (Array.isArray(value)) {
		return `[${value.map(phpJsonEncode).join(",")}]`;
	}

	if (value !== null && typeof value === "object") {
		const entries = Object.entries(value);

		if (entries.length === 0) {
			return "[]";
		}

		return `{${entries.map(([key, item]) => `${phpJsonEncode(key)}:${phpJsonEncode(item)}`).join(",")}}`;
	}

	if (typeof value === "string") {
		return JSON.stringify(value)
			.replace(/\//g, "\\/")
			.replace(
				/[^\x20-\x7e]/g,
				(char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, "0")}`,
			);
	}

	return JSON.stringify(value);
};

/**
 * Composer's Locker::getContentHash(), so the lock follows composer.json without running Composer.
 */
export const composerContentHash = (composerJson: string): string => {
	const content = JSON.parse(composerJson);
	const relevantKeys = [
		"name",
		"version",
		"require",
		"require-dev",
		"conflict",
		"replace",
		"provide",
		"minimum-stability",
		"prefer-stable",
		"repositories",
		"extra",
	];
	const relevant: Record<string, unknown> = {};

	for (const key of relevantKeys) {
		if (key in content) {
			relevant[key] = content[key];
		}
	}

	if (content.config?.platform) {
		relevant.config = { platform: content.config.platform };
	}

	const sorted = Object.fromEntries(
		Object.keys(relevant)
			.sort()
			.map((key) => [key, relevant[key]]),
	);

	return createHash("md5").update(phpJsonEncode(sorted)).digest("hex");
};
