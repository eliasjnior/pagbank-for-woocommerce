import Fs from "node:fs";
import Path, { dirname } from "node:path";
import { fileURLToPath } from "node:url";

const rootDir = Path.resolve(dirname(fileURLToPath(import.meta.url)), "..");

const read = (file: string): string => Fs.readFileSync(Path.join(rootDir, file), "utf8");

const match = (file: string, pattern: RegExp): string | null =>
	read(file).match(pattern)?.[1] ?? null;

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

const version: string = JSON.parse(read("package.json")).version;

const sources: Array<[label: string, value: string | null]> = [
	["composer.json version", JSON.parse(read("composer.json")).version ?? null],
	["plugin header Version", match("pagbank-for-woocommerce.php", /^\s*\*\s*Version:\s*(\S+)/m)],
	[
		"PAGBANK_WOOCOMMERCE_VERSION",
		match(
			"pagbank-for-woocommerce.php",
			/define\(\s*'PAGBANK_WOOCOMMERCE_VERSION',\s*'([^']+)'/,
		),
	],
	["readme.txt Stable tag", match("readme.txt", /^Stable tag:\s*(\S+)/m)],
	[
		"phpstan-bootstrap.php constant",
		match("phpstan-bootstrap.php", /define\(\s*'PAGBANK_WOOCOMMERCE_VERSION',\s*'([^']+)'/),
	],
];

const errors = sources
	.filter(([, value]) => value !== version)
	.map(([label, value]) => `${label} is ${value ?? "missing"}, expected ${version}`);

if (!new RegExp(`^= ${escapeRegExp(version)} - `, "m").test(read("readme.txt"))) {
	errors.push(`readme.txt has no changelog entry for ${version}`);
}

if (errors.length > 0) {
	for (const error of errors) {
		console.error(`::error::${error}`);
	}
	process.exit(1);
}

console.log(`Version ${version} is consistent across the plugin.`);
