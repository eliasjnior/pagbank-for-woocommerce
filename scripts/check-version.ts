import {
	changelogHeading,
	composerContentHash,
	currentVersion,
	readFile,
	versionDeclarations,
} from "./helpers/version.ts";

const version = currentVersion();
const errors: string[] = [];

for (const { label, file, pattern } of versionDeclarations) {
	const declared = readFile(file).match(pattern)?.[1];

	if (declared !== version) {
		errors.push(`${label} is ${declared ?? "missing"}, expected ${version}`);
	}
}

if (!changelogHeading(version).test(readFile("readme.txt"))) {
	errors.push(`readme.txt has no changelog entry for ${version}`);
}

if (
	JSON.parse(readFile("composer.lock"))["content-hash"] !==
	composerContentHash(readFile("composer.json"))
) {
	errors.push("composer.lock is out of date with composer.json");
}

if (errors.length > 0) {
	for (const error of errors) {
		console.error(`::error::${error}`);
	}
	process.exit(1);
}

console.log(`Version ${version} is consistent across the plugin.`);
