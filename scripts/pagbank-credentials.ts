import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { rootDir } from "./helpers/vite-config.ts";

// Every worktree's setup reads the connection from the main checkout's .env (gitignored).
const mainEnvFile = join(
	dirname(
		execFileSync(
			"git",
			["-C", rootDir, "rev-parse", "--path-format=absolute", "--git-common-dir"],
			{
				encoding: "utf8",
			},
		).trim(),
	),
	".env",
);

function readConnection(environment: string) {
	try {
		return execFileSync(
			"docker",
			[
				"compose",
				"exec",
				"-T",
				"-u",
				"www-data",
				"wordpress",
				"wp",
				"option",
				"get",
				`woocommerce_pagbank_connect_data_${environment}`,
				"--format=json",
			],
			{ cwd: rootDir, encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] },
		).trim();
	} catch {
		return undefined;
	}
}

let env = existsSync(mainEnvFile) ? readFileSync(mainEnvFile, "utf8") : "";
const saved: string[] = [];

for (const environment of ["sandbox", "production"]) {
	const connection = readConnection(environment);

	if (!connection) {
		continue;
	}

	const line = `PAGBANK_CONNECT_DATA_${environment.toUpperCase()}='${connection}'`;
	const pattern = new RegExp(`^PAGBANK_CONNECT_DATA_${environment.toUpperCase()}=.*$`, "m");

	env = pattern.test(env)
		? env.replace(pattern, () => line)
		: `${env}${env === "" || env.endsWith("\n") ? "" : "\n"}${line}\n`;
	saved.push(environment);
}

if (saved.length === 0) {
	console.error("No PagBank connection found; connect the plugin on this site and run again.");
	process.exit(1);
}

writeFileSync(mainEnvFile, env);
console.log(`Saved the ${saved.join(" and ")} PagBank connection to ${mainEnvFile}.`);
