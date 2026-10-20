# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PagBank for WooCommerce is a WordPress plugin that integrates PagBank payment gateway with WooCommerce, supporting credit card, debit card, Pix, boleto (bank slip), Pay with PagBank and Checkout PagBank, plus Google Pay and Apple Pay behind the `PAGBANK_FEATURE_FLAG_GOOGLE_PAY_ENABLED` / `PAGBANK_FEATURE_FLAG_APPLE_PAY_ENABLED` constants. The plugin is designed for Brazilian e-commerce with marketplace support (Dokan and WCFM) and includes split payment functionality.

## Technology Stack

- **Backend**: PHP 7.4-8.3 with WordPress/WooCommerce
- **Frontend**: TypeScript with Vite
- **Package Management**: pnpm for Node.js, Composer for PHP
- **Development Environment**: Docker Compose with WordPress, MariaDB, and PHPMyAdmin

## Development Commands

### Setup
```bash
# Full, idempotent setup: dependencies, Docker stack, build, WordPress, store and PagBank connection
pnpm run setup  # `pnpm setup` is a built-in pnpm command

# Install PHP dependencies
pnpm composer install

# Start Docker environment
docker compose up -d --wait

# Save the PagBank connection of the current site to the main checkout's .env for future setups
pnpm pagbank:save-credentials
```

Access the development site at http://wordpress.localhost (`wordpress` / `wordpress`); the setup installs WordPress under that URL.

**Development Services:**
- WordPress: http://wordpress.localhost
- PHPMyAdmin: http://localhost:8080
- Mailpit (Email testing): http://localhost:8025

**Worktrees:** those URLs belong to the main checkout. A worktree runs its own stack (Compose project, `wp/`, database volume) on the ports `pnpm worktree:env` reserved in its `.env`: read `WP_URL`, `PHPMYADMIN_PORT` and `MAILPIT_PORT` from there (`wt links` lists them for every worktree), never assume port 80, and only run `docker compose` from the worktree's own directory so you don't stop or reset another one's stack. `pnpm run setup` writes the `.env` itself; run `pnpm worktree:env` before `docker compose up` if you start the stack first.

A worktree's stack never starts on its own (`wt switch --create` only copies the dependencies and reserves the ports). Start it only when the task needs WordPress: `pnpm run setup` the first time, which ends with `Setup complete: <url>`, and `docker compose up -d --wait` after that. Run `docker compose stop` when you are done, so stacks don't pile up. Unit tests, linting and builds need no stack. PHP notices and errors land in `logs/debug.log`, and e-mails in Mailpit.

### Build & Development
```bash
# Build production bundle (runs composer install --no-dev first)
pnpm build

# Development mode with watch
pnpm dev

# TypeScript compilation only
tsc

# Production zip into build/, verified by scripts/verify-export.ts
pnpm export
```

### Versioning & Translations
```bash
# Set a new version in every declaration and open its changelog entry
pnpm bump <major|minor|patch|X.Y.Z>

# Check that every version declaration matches package.json
pnpm check:version

# Regenerate languages/pagbank-for-woocommerce.pot (needs a production build in dist/)
pnpm make:pot
```

### Linting
```bash
# Lint both PHP and TypeScript
pnpm lint

# Lint PHP only (uses Docker)
pnpm lint:core

# Fix PHP code style issues
pnpm lint:fix:core

# Lint TypeScript/JavaScript only (Biome)
pnpm lint:ui

# Static analysis (PHPStan, baseline in phpstan-baseline.neon)
pnpm analyse
```

### Testing
```bash
# Run PHP unit tests (uses Docker)
pnpm test

# Run specific test
./scripts/phpunit.sh path/to/test.php
```

### WordPress CLI
```bash
# Execute WP-CLI commands
pnpm wp <command>
```

## Architecture

### Directory Structure

```
src/
├── core/                          # PHP backend code
│   ├── Gateways/                  # One class per payment method, shared traits in Traits/
│   ├── Presentation/              # WP hooks, REST/AJAX handlers, checkout fields, API client
│   │   ├── PaymentGateways.php    # Gateway registration and shared script enqueues
│   │   ├── Connect.php            # PagBank OAuth connection
│   │   ├── Api.php                # PagBank API client
│   │   ├── ApiHelpers.php         # Order → API payload builders
│   │   ├── WebhookHandler.php
│   │   ├── LegacyCheckoutFields.php
│   │   └── Blocks/                # Checkout Blocks payment methods and fields
│   ├── Validators/                # CPF, CNPJ and alphanumeric CNPJ validation
│   └── Marketplace/               # WCFM integration
├── templates/                     # PHP templates for checkout, orders and e-mails
└── ui/                            # TypeScript/React frontend code
    ├── entries/                   # One Vite entry per enqueued script (admin/, public/blocks, public/legacy, ...)
    ├── shared/                    # Code shared between entries
    └── styles/                    # SCSS, compiled one file per stylesheet; _partials are only @use'd
```

### Key Architecture Patterns

1. **Payment Gateway Structure**: Each payment method is a class in `src/core/Gateways/` extending `WC_Payment_Gateway_CC` or `WC_Payment_Gateway`, registered in `PaymentGateways::add_gateways()`:
   - `CreditCardPaymentGateway`: Supports tokenization, installments, recurring payments
   - `DebitCardPaymentGateway`: Extends the credit card gateway, with 3DS authentication
   - `GooglePayPaymentGateway`, `ApplePayPaymentGateway`: Wallet payments
   - `PixPaymentGateway`: Generates QR codes for instant payment
   - `BoletoPaymentGateway`: Generates bank slips
   - `PayWithPagBankGateway`, `CheckoutPaymentGateway`: redirect to PagBank

2. **Singleton Pattern**: Presentation layer classes use `get_instance()` static method for initialization

3. **API Integration**: `Api.php` handles all PagBank API communication with OAuth2 token management

4. **Webhook Processing**: `WebhookHandler.php` processes payment status updates from PagBank

5. **Frontend Build**: `scripts/build-ui.ts` builds every entry listed in `scripts/helpers/vite-config.ts` as its own IIFE in `dist/`, with WordPress/WooCommerce packages mapped to their globals (`externals`) instead of bundled

6. **Marketplace Split Payments**: Each vendor must configure their PagBank marketplace identifier in their store settings

### PSR-4 Autoloading

PHP classes use the namespace `PagBank_WooCommerce\` mapped to `src/core/`

### Plugin Entry Point

`pagbank-for-woocommerce.php` initializes all singleton instances and declares WooCommerce HPOS compatibility

## Code Standards

### Comments

Keep them sparse. A comment earns its place only by explaining a non-obvious
*why* — an asymmetry between branches, why a format or constant was chosen over
the obvious alternative, a constraint the code cannot show. One or two lines, not
a paragraph: state the reason once, without also adding a worked example and a
restatement. Drop anything that narrates the next line, and keep docblocks to the
summary plus the tags PHPCS demands.

### PHP
- WordPress Coding Standards (WPCS) enforced via PHPCS
- WooCommerce Coding Standards
- Configuration in `.phpcs.xml`
- Target PHP 7.4 compatibility (defined in composer.json platform config)

#### Hook callbacks on loosely-typed hooks

PHP checks parameter types when binding the arguments, *before* the first line of
the body runs — so a guard like `if ( ! is_object( $email ) ) { return; }` can never
save a typed signature. When a hook's contract is loose, a typed callback turns a
third party's sloppy call into a fatal error that takes down the whole request, even
on stores that never used PagBank.

Treat a hook as loose when WooCommerce/WordPress themselves say so:

- core's own callback is untyped, or defaults to another type —
  `WC_Emails::order_details( $order, $sent_to_admin = false, $plain_text = false, $email = '' )`
- core documents a union — `WC_Email::$object` is `@var object|bool`
- callers register different `accepted_args`, so `WP_Hook` forwards fewer arguments
  than the signature expects (`WC_Structured_Data` registers
  `woocommerce_email_order_details` for three)
- plugins re-fire the hook from their own templates with whatever they have at hand

On those, declare no parameter types, give every parameter a default, type the
return, document with `@param mixed`, and validate in the body.
`Hooks::resolve_order()` and `Hooks::resolve_email_id()` normalise the usual
arguments; keep the guard that identifies the payment method first so the callback
is a cheap no-op for other gateways. The email hooks
(`woocommerce_email_order_details`, `woocommerce_email_attachments`,
`woocommerce_email_sent`) are the ones that bit us in practice.

Everywhere else the types stay. Hooks whose only caller is core passing a fixed type
(`woocommerce_get_order_item_totals`, `script_loader_tag`, `woocommerce_payment_gateways`,
…) keep their parameter types, and so do the internal `pagbank_*` hooks whose
`do_action` / `apply_filters` we own. Don't relax a signature without a call site
that proves the contract is loose.

### TypeScript/JavaScript
- Biome for linting, formatting and import ordering (`biome.json`)
- Strict TypeScript (`tsconfig.json`), checked with `tsc`

## Important Dependencies

### PHP
- `jakeasmith/http_build_url`: URL manipulation
- `giggsey/libphonenumber-for-php`: Phone validation
- `nesbot/carbon`: Date handling
- `wilkques/pkce-php`: OAuth PKCE flow for PagBank connection

### TypeScript
- `autonumeric`: Currency input formatting
- `card-validator`: Credit card validation
- `axios`: HTTP client
- `date-fns`: Date utilities

## Testing Notes

- PHPUnit configured in `phpunit.xml` with bootstrap at `tests/bootstrap.php`
- Tests run inside Docker container via `scripts/phpunit.sh`
- Unit tests live in `tests/Validators` and `tests/Presentation`. They run without WordPress: `tests/bootstrap.php` shims the few WordPress/WooCommerce functions and classes the code under test touches, so extend it when a test needs more
- `scripts/smoke-test.sh` exercises the built plugin on a running wp-env site (see Continuous Integration)

## Build Output

- Vite outputs to `dist/` (scripts by entry name, `dist/styles/`, `dist/images/`)
- `scripts/export.ts` zips only `dist/`, `languages/`, `src/core/`, `src/templates/`, `vendor/` (installed with `--no-dev`), `composer.json`, the main plugin file and `readme.txt`, dropping dotfiles and `vendor/bin`
- `scripts/verify-export.ts` then rejects development files, sources, source maps and dev dependencies, and requires every `dist/...` path the shipped PHP references to exist in the zip

## Continuous Integration & Releases

`.github/workflows/ci.yml` runs on every pull request (and is reused by the release). The `changes` job maps the changed files to the php, ui and plugin areas and pull requests skip the jobs of untouched areas; `CI result` aggregates every job and is the only required check, so a new job must be added to its `needs`, and a new path that affects a job must be added to the `changes` patterns:

- PHPCS (errors fail, warnings are annotated), PHPStan, PHPUnit on PHP 7.4–8.3, Biome, `tsc`
- commitlint on every commit and on the PR title, which becomes the squash commit on `main`; commit body lines are limited to 100 characters
- Branch name: pull requests into a `vX.Y.Z` release branch must come from `<type>/<name>` (Conventional Commits types, lowercase) or `renovate/...`, and pull requests into `main` only from a `vX.Y.Z` branch. Name new branches that way
- Package: `check:version`, a production build, the `.pot` compared with a fresh `make-pot` (source references ignored, so only string changes matter), then the zip and its verification
- Plugin Check on the zip. It also scans `src/templates/`, which `.phpcs.xml` leaves out
- Smoke test on the oldest supported stack (PHP 7.4, WordPress 6.7, WooCommerce 9.9) and the latest one

What this means when changing code:

- Changing a translatable string requires `pnpm make:pot` and committing the `.pot`
- A new gateway needs its id in `GATEWAYS` in `scripts/smoke-test.sh`; a new enqueued asset must be produced by the build
- Do not bump versions by hand; `pnpm bump` also fixes the `composer.lock` content-hash, which depends on the `composer.json` version

Releases: create `vX.Y.Z` from `main`, bump and write the `readme.txt` changelog entry on a `chore/` branch merged into it, merge `vX.Y.Z` into `main`, then run the **Release** workflow from `main`. It tags `X.Y.Z` (no `v`, matching the WordPress.org SVN tags and avoiding clashes with release branches) and publishes the zip with a build provenance attestation and the changelog entry as notes.

## WooCommerce Integration Points

- **Subscriptions Support**: Handles recurring payments for WooCommerce Subscriptions
- **HPOS Compatibility**: Declared compatible with High-Performance Order Storage
- **Payment Tokens**: Supports saving credit cards for future use
- **Refunds**: Online refund support (total and partial)
- **Order Status Sync**: Webhook automatically updates order status

## Marketplace Support

The plugin supports multi-vendor marketplaces (Dokan/WCFM) with payment splitting. Each vendor must:
1. Access their PagBank account → Vendas → Identificador para Marketplace
2. Configure the identifier in WooCommerce vendor settings
3. Products from vendors without identifiers won't be available at checkout

## Checkout Fields & Third-Party Interop

The plugin ships its own Brazil-specific checkout fields — no external plugin is required:

- **Classic checkout**: `LegacyCheckoutFields` registers a person type selector (`billing_persontype`, '1' = CPF / '2' = CNPJ) toggling between `billing_cpf` and `billing_cnpj` (the core `billing_company` is relabeled "Razão Social" and required for legal persons), plus address number, neighborhood and cellphone via `woocommerce_billing_fields`/`woocommerce_shipping_fields`. Because the field keys match the interop contract, WooCommerce persists order/customer meta automatically.
- **Blocks checkout**: `CheckoutBlocksFields` registers `pagbank/persontype` (select, '1'/'2'), `pagbank/cpf`, `pagbank/cnpj` (conditionally visible via Opis JSON Schema rules referencing `customer.address`), `pagbank/company` (Razão Social — only when the store hides the core company field), `pagbank/address-number`, `pagbank/neighborhood` and `pagbank/cellphone` additional checkout fields. `pagbank/tax-id` is the pre-split legacy field, still read as fallback in `ApiHelpers`.

Interop meta contract (shared with third-party plugins): order meta `_billing_persontype` ('1' = CPF, '2' = CNPJ), `_billing_cpf`, `_billing_cnpj`, `_billing_number`, `_billing_neighborhood`, `_billing_cellphone`; customer meta uses the same keys without the leading underscore.

Third-party deference is resolved **per field group**, never per plugin: `LegacyCheckoutFields::resolve_provides()` is a pure function mapping the detected plugin state to `{document, number, neighborhood, cellphone}`, and `plugin_state()` reads that state from the options. Both checkouts consume the same map, so classic and Blocks always agree. Deferring on a whole plugin would leave the checkout without the groups that plugin does not actually add, which is how an address could reach PagBank with no number.

Per group, per plugin:

| Group | Brazilian Market | LinkNacional |
| --- | --- | --- |
| document (persontype/CPF/CNPJ) | `wcbcf_settings['person_type'] !== 0` | `woo_better_calc_person_type_select !== 'none'` |
| address number | always, while active | `woo_better_calc_number_required === 'yes'` (default `no`) |
| neighborhood | always, while active | `woo_better_calc_enable_neighborhood_field === 'yes'` (default `no`) |
| cellphone | `wcbcf_settings['cell_phone']` in `1`/`2` (own field) or `-1` (relabels the core phone) | no field of its own; it manages the core `billing_phone` as the contact field, so our Celular duplicates the role whenever it is active |

Plugin detection:
- **Brazilian Market on WooCommerce** (`woocommerce-extra-checkout-fields-for-brazil`): detected via `class_exists( 'Extra_Checkout_Fields_For_Brazil' )` — never use `Extra_Checkout_Fields_For_Brazil_Front_End` (the LinkNacional plugin ships a stub of it). Settings option: `wcbcf_settings` (`person_type` 0=off/1=both/2=CPF/3=CNPJ, `cell_phone`). Classic checkout only (no Blocks support). Deprecated path: when active, a dismissible admin notice (`LegacyCheckoutFields::maybe_render_brazilian_market_deprecation_notice`) recommends deactivating it — the plugin is unmaintained (no alphanumeric CNPJ support) and compatibility will eventually be dropped. Dismissing stores a per-user timestamp (AJAX + user meta) that snoozes the notice for 24h; it reappears while the plugin stays active.
- **Calculadora de Frete e Campos Checkout para o Brasil** (LinkNacional, `woo-better-shipping-calculator-for-brazil`): detected via `defined( 'WC_BETTER_SHIPPING_CALCULATOR_FOR_BRAZIL_VERSION' )`. Option: `woo_better_calc_person_type_select` (`none|physical|legal|both`). Supports Blocks via its own Store API `extensions` namespaces (`woo_better_person_type`, `woo_better_number_validation`, `woo_better_neighborhood`), writing the same `_billing_*` interop meta, gated by the same options as the classic checkout. It registers no cellphone field on either checkout.

When either plugin provides a field group, the native fields for that group are not inserted. `ApiHelpers` reads the Blocks additional fields first and falls back to the legacy `_billing_*` meta, so every combination resolves.

My Account address pages: WooCommerce adds every address-location Blocks field to the edit address form, next to the classic fields that `woocommerce_billing_fields` inserts there too. `CheckoutBlocksFields::maybe_deregister_on_my_account()` deregisters the `pagbank/*` fields on the `edit-address` endpoint, so the classic fields (ours or a third-party plugin's) are the only editor. Deregistering, not filtering the form, matters: WooCommerce saves every registered additional field missing from the POST as empty. The two customer meta stores stay in sync both ways: Blocks writes also go to the interop keys (`save_field_to_legacy_meta`), a My Account save is mirrored back to `_wc_{group}/pagbank/*`, and each side falls back to the other when empty (`get_customer_meta()`, `default_from_legacy_meta()`).

## Development Environment Details

- WordPress runs on PHP 8.3 from the image in `docker/wordpress/` (WP-CLI and msmtp, no Xdebug); `pnpm wp` runs WP-CLI inside it as `www-data`
- Development mode: `WP_ENVIRONMENT_TYPE=development`, `WP_DEBUG`, `WP_DEBUG_DISPLAY` and `SCRIPT_DEBUG` on, `WP_DEBUG_LOG` at `logs/debug.log`
- Database: MariaDB; PHPMyAdmin on port 8080 (or `PHPMYADMIN_PORT` in a worktree)
- Plugin mounted as volume for hot-reload
- PHP settings in `docker/wordpress/php.ini`; Apache vhosts in `docker/wordpress/apache.conf` and `apache-ports.conf`, which listen inside the container on the published ports so loopback requests to `WP_URL` work
- `mail()` goes to Mailpit; the WP-CLI download cache in `~/.wp-cli/cache` is shared by every worktree
