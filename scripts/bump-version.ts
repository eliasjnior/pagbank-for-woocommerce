import {
	changelogHeading,
	composerContentHash,
	currentVersion,
	readFile,
	versionDeclarations,
	writeFile,
} from "./helpers/version.ts";

const usage = "Usage: pnpm bump <major|minor|patch|X.Y.Z>";

const parse = (version: string): number[] | null =>
	/^\d+\.\d+\.\d+$/.test(version) ? version.split(".").map(Number) : null;

const isNewer = (next: number[], current: number[]): boolean => {
	const index = next.findIndex((part, i) => part !== current[i]);

	return index !== -1 && next[index] > current[index];
};

const resolveNext = (current: string, argument: string | undefined): string => {
	const [major, minor, patch] = parse(current) ?? [];

	switch (argument) {
		case "major":
			return `${major + 1}.0.0`;
		case "minor":
			return `${major}.${minor + 1}.0`;
		case "patch":
			return `${major}.${minor}.${patch + 1}`;
		default:
			return argument ?? "";
	}
};

const current = currentVersion();
const next = resolveNext(current, process.argv[2]);
const nextParts = parse(next);
const currentParts = parse(current);

if (!nextParts || !currentParts) {
	console.error(usage);
	process.exit(1);
}

if (!isNewer(nextParts, currentParts)) {
	console.error(`${next} must be newer than the current version ${current}.`);
	process.exit(1);
}

for (const { label, file, pattern } of versionDeclarations) {
	const contents = readFile(file);
	const match = contents.match(pattern);

	if (!match) {
		console.error(`Could not find the ${label} in ${file}.`);
		process.exit(1);
	}

	writeFile(file, contents.replace(pattern, match[0].replace(match[1], next)));
}

const lock = readFile("composer.lock");
writeFile(
	"composer.lock",
	lock.replace(
		/"content-hash": "[0-9a-f]+"/,
		`"content-hash": "${composerContentHash(readFile("composer.json"))}"`,
	),
);

// The changelog runs oldest to newest and is the last section of readme.txt.
const readme = readFile("readme.txt");

if (!changelogHeading(next).test(readme)) {
	const today = new Date().toLocaleDateString("sv-SE");
	writeFile("readme.txt", `${readme.trimEnd()}\n\n= ${next} - ${today} =\n`);
}

console.log(`Bumped ${current} to ${next}.`);
console.log(
	"Next: list the changes under the new readme.txt changelog entry; the release notes come from it.",
);
