import Fs from "node:fs";
import JSZip from "jszip";

import data from "../package.json";

const zipPath = process.argv[2] ?? `build/${data.name}-${data.version}.zip`;
const root = `${data.name}/`;

/**
 * Anything matching these must never reach a store: sources, tooling and dev dependencies.
 */
const forbidden: Array<[pattern: RegExp, reason: string]> = [
	[/(^|\/)\.[^/]+/, "hidden file"],
	[/\.map$/, "source map"],
	[/\.(ts|tsx|scss)$/, "UI source"],
	[/(^|\/)node_modules\//, "node_modules"],
	[/^(tests|scripts|docs|wp|build|src\/ui)\//, "development folder"],
	[/^dist\/.*(^|\/)_[^/]+\.css$/, "compiled SCSS partial"],
	[/^vendor\/bin\//, "Composer binaries"],
	[
		/^vendor\/(phpunit|squizlabs|phpstan|szepeviktor|php-stubs|wp-coding-standards|woocommerce\/woocommerce-sniffs|slevomat|dealerdirect|phpcsstandards)\//,
		"dev dependency",
	],
	[
		/^(composer\.lock|package\.json|pnpm-lock\.yaml|phpunit\.xml|phpstan.*|README.*\.md|CLAUDE\.md)$/,
		"dev file",
	],
];

const required = [
	"pagbank-for-woocommerce.php",
	"readme.txt",
	"vendor/autoload.php",
	"composer.json",
	`languages/${data.name}.pot`,
];

async function verify() {
	if (!Fs.existsSync(zipPath)) {
		throw new Error(`Zip not found: ${zipPath}`);
	}

	const zip = await JSZip.loadAsync(Fs.readFileSync(zipPath));
	const errors: string[] = [];
	const files = new Map<string, JSZip.JSZipObject>();

	for (const entry of Object.values(zip.files)) {
		if (!entry.name.startsWith(root)) {
			errors.push(`${entry.name} is outside the ${root} folder`);
			continue;
		}

		if (!entry.dir) {
			files.set(entry.name.slice(root.length), entry);
		}
	}

	for (const path of files.keys()) {
		const rule = forbidden.find(([pattern]) => pattern.test(path));

		if (rule) {
			errors.push(`${path}: ${rule[1]}`);
		}
	}

	for (const path of required) {
		if (!files.has(path)) {
			errors.push(`${path} is missing`);
		}
	}

	// Every asset the shipped PHP enqueues must be in the bundle, or it 404s on the storefront.
	const referencedAssets = new Set<string>();

	for (const [path, entry] of files) {
		if (path.endsWith(".php") && !path.startsWith("vendor/")) {
			const source = await entry.async("string");

			for (const [, asset] of source.matchAll(/['"](dist\/[^'"]+\.[a-z0-9]+)['"]/g)) {
				referencedAssets.add(asset);
			}
		}
	}

	for (const asset of referencedAssets) {
		if (!files.has(asset)) {
			errors.push(`${asset} is referenced by the PHP code but missing from the bundle`);
		}
	}

	const installed = files.get("vendor/composer/installed.json");

	if (installed) {
		const manifest = JSON.parse(await installed.async("string"));

		if (manifest.dev !== false || (manifest["dev-package-names"] ?? []).length > 0) {
			errors.push(
				"vendor/ was installed with dev dependencies (run composer install --no-dev)",
			);
		}
	} else {
		errors.push("vendor/composer/installed.json is missing");
	}

	if (errors.length > 0) {
		for (const error of errors) {
			console.error(`::error::${error}`);
		}
		process.exit(1);
	}

	console.log(
		`${zipPath}: ${files.size} files, ${referencedAssets.size} referenced assets present, no development files.`,
	);
}

verify().catch((err) => {
	console.error(err);
	process.exit(1);
});
