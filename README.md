# PagBank for WooCommerce

WordPress plugin that adds PagBank payments to WooCommerce: credit card, debit card, Pix, boleto, Pay with PagBank, Checkout PagBank, Google Pay and Apple Pay, on both the classic and the Blocks checkout.

This README covers development. The store-facing description, installation guide and changelog live in [`readme.txt`](readme.txt), which is what WordPress.org publishes.

## Features

- Transparent checkout for every payment method, on classic (shortcode) and Blocks checkout
- Card tokenization, saved cards, installments and 3D Secure authentication
- Pix QR codes and boleto bank slips on the order page and in customer e-mails
- Full and partial refunds from the order screen
- Order status kept in sync through PagBank webhooks
- WooCommerce Subscriptions renewals
- Split payments for Dokan and WCFM marketplaces
- Brazilian checkout fields (CPF/CNPJ, number, neighborhood, cellphone) that defer to Brazilian Market on WooCommerce or LinkNacional's Calculadora de Frete when they are active

## Requirements

| | Version |
| --- | --- |
| PHP | 7.4 to 8.3 |
| WordPress | 6.7+ |
| WooCommerce | 9.9+ |

Development also needs Docker, Node 24 and pnpm (the version is pinned in `package.json`).

## Getting started

```bash
pnpm install
docker compose up -d
pnpm run setup
```

`pnpm run setup` installs the dependencies, builds the assets, installs WordPress with WooCommerce and Storefront, and activates the plugin. Use `pnpm run setup`, not `pnpm setup`, which is a built-in pnpm command.

| Service | URL |
| --- | --- |
| WordPress | http://wordpress.localhost (user `wordpress`, password `wordpress`) |
| phpMyAdmin | http://localhost:8080 |
| Mailpit | http://localhost:8025 |

The repository is mounted as `wp-content/plugins/pagbank-for-woocommerce`, so PHP changes apply on reload. HTTPS is served on port 443 with a self-signed certificate unless you place your own `.crt` and `.key` in `certs/`.

## Commands

| Command | What it does |
| --- | --- |
| `pnpm dev` | Builds the UI in watch mode |
| `pnpm build` | Type-checks and builds the production assets into `dist/` |
| `pnpm lint` | Runs PHPCS and Biome |
| `pnpm lint:fix:core` / `pnpm lint:fix:ui` | Fixes PHP / TypeScript style issues |
| `pnpm analyse` | Runs PHPStan |
| `pnpm test` | Runs PHPUnit (`pnpm test path/to/Test.php` for one file) |
| `pnpm make:pot` | Regenerates `languages/pagbank-for-woocommerce.pot` |
| `pnpm bump <major\|minor\|patch\|X.Y.Z>` | Sets the new version everywhere it is declared and opens its changelog entry |
| `pnpm check:version` | Checks that every version declaration matches `package.json` |
| `pnpm export` | Builds the production zip into `build/` and verifies its contents |
| `pnpm wp <command>` | Runs WP-CLI against the development site |

PHP tooling and Composer run inside Docker, so no local PHP install is needed.

## Project layout

```
src/core/        PHP code (PSR-4, namespace PagBank_WooCommerce\)
src/templates/   PHP templates for checkout, order and e-mails
src/ui/          TypeScript, React and SCSS sources
scripts/         Build, export and Docker wrapper scripts
tests/           PHPUnit tests
languages/       Translation template
wordpress_org_assets/  Banners, icons and screenshots for WordPress.org
```

## Continuous integration

Every pull request runs [`.github/workflows/ci.yml`](.github/workflows/ci.yml):

- PHPCS, PHPStan, and PHPUnit on PHP 7.4 to 8.3
- Biome and TypeScript
- Conventional Commits on every commit and on the PR title (the squash commit on `main`)
- A package build that checks version consistency, fails when the `.pot` is outdated, and verifies that the zip has every asset the PHP enqueues and nothing from development
- [Plugin Check](https://wordpress.org/plugins/plugin-check/), the WordPress.org review tool, on the built zip: errors fail the job, warnings are annotated
- A smoke test that installs the zip with WooCommerce on the oldest supported stack (PHP 7.4, WordPress 6.7, WooCommerce 9.9) and on the latest one, then checks that every gateway registers, the storefront and the gateway settings screens render, and the plugin logs no PHP errors. `scripts/smoke-test.sh` runs the same checks against any local wp-env site

The zip built for each PR is attached to the workflow run, so it can be installed on a test store.

## Releasing

1. On a `vX.Y.Z` branch, run `pnpm bump patch` (or `minor`, `major`, an exact `X.Y.Z`). It updates `package.json`, `composer.json` and the `composer.lock` hash, the plugin header and `PAGBANK_WOOCOMMERCE_VERSION`, `phpstan-bootstrap.php`, the `Stable tag` in `readme.txt` and the `.pot` header, and adds the `= X.Y.Z - YYYY-MM-DD =` changelog heading.
2. List the changes under that heading in `readme.txt`, and run `pnpm make:pot` if translatable strings changed.
3. Open a pull request to `main` and merge it once CI passes.
4. Run the **Release** workflow from `main` (Actions → Release → Run workflow).

The workflow reads the version from `package.json`, reruns CI, tags the commit as `X.Y.Z`, and publishes a GitHub release with the production zip and the changelog entry as notes. The zip carries a build provenance attestation, which `gh attestation verify <zip> --repo <owner>/<repo>` checks.

## Contributing

Commits follow [Conventional Commits](https://www.conventionalcommits.org/), enforced locally by Husky and commitlint and again in CI. `main` is protected: changes land through pull requests only.

## License

GPL-2.0, see the plugin header in `pagbank-for-woocommerce.php`.
