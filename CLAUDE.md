# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PagBank for WooCommerce is a WordPress plugin that integrates PagBank payment gateway with WooCommerce, supporting credit card, Pix, and boleto (bank slip) payment methods. The plugin is designed for Brazilian e-commerce with marketplace support (Dokan and WCFM) and includes split payment functionality.

## Technology Stack

- **Backend**: PHP 7.4-8.3 with WordPress/WooCommerce
- **Frontend**: TypeScript with Vite
- **Package Management**: pnpm for Node.js, Composer for PHP
- **Development Environment**: Docker Compose with WordPress, MariaDB, and PHPMyAdmin

## Development Commands

### Setup
```bash
# Initial project setup (after docker compose up)
pnpm setup

# Install PHP dependencies
pnpm composer install

# Start Docker environment
docker compose up
```

Access the development site at http://localhost

**Development Services:**
- WordPress: http://localhost
- PHPMyAdmin: http://localhost:8080
- Mailpit (Email testing): http://localhost:8025

### Build & Development
```bash
# Build production bundle (runs composer install --no-dev first)
pnpm build

# Development mode with watch
pnpm dev

# TypeScript compilation only
tsc
```

### Linting
```bash
# Lint both PHP and TypeScript
pnpm lint

# Lint PHP only (uses Docker)
pnpm lint:core

# Fix PHP code style issues
pnpm lint:fix:core

# Lint TypeScript/JavaScript only
pnpm lint:ui
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
│   ├── Gateways/                  # Payment gateway implementations
│   │   ├── CreditCardPaymentGateway.php
│   │   ├── PixPaymentGateway.php
│   │   └── BoletoPaymentGateway.php
│   ├── Presentation/              # WP hooks, AJAX handlers, field rendering
│   │   ├── PaymentGateways.php    # Gateway registration
│   │   ├── PaymentGatewaysFields.php
│   │   ├── Connect.php            # PagBank OAuth connection
│   │   ├── ConnectAjaxApi.php
│   │   ├── WebhookHandler.php
│   │   ├── Api.php                # PagBank API client
│   │   ├── ApiHelpers.php
│   │   └── Helpers.php
│   └── Marketplace/               # Marketplace integrations
│       └── WcfmIntegration.php
├── templates/                     # PHP template files for checkout/admin
└── ui/                           # TypeScript frontend code
    └── entries/
        ├── admin/
        │   └── admin-settings.ts  # Admin settings page scripts
        └── public/
            ├── checkout-credit-card.ts
            └── order.ts
```

### Key Architecture Patterns

1. **Payment Gateway Structure**: Three gateway classes extend `WC_Payment_Gateway_CC` or `WC_Payment_Gateway`:
   - `CreditCardPaymentGateway`: Supports tokenization, installments, recurring payments
   - `PixPaymentGateway`: Generates QR codes for instant payment
   - `BoletoPaymentGateway`: Generates bank slips

2. **Singleton Pattern**: Presentation layer classes use `get_instance()` static method for initialization

3. **API Integration**: `Api.php` handles all PagBank API communication with OAuth2 token management

4. **Webhook Processing**: `WebhookHandler.php` processes payment status updates from PagBank

5. **Frontend Build**: Vite bundles TypeScript into separate entry points for admin and public checkout pages

6. **Marketplace Split Payments**: Each vendor must configure their PagBank marketplace identifier in their store settings

### PSR-4 Autoloading

PHP classes use the namespace `PagBank_WooCommerce\` mapped to `src/core/`

### Plugin Entry Point

`pagbank-for-woocommerce.php` initializes all singleton instances and declares WooCommerce HPOS compatibility

## Code Standards

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
- ESLint with TypeScript plugin
- Prettier for formatting
- Import ordering enforced (import-helpers plugin)
- Configuration in `.eslintrc.cjs`

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
- No existing test suite structure visible; tests directory appears empty

## Build Output

- Vite outputs to `dist/` directory
- Three entry points: admin-settings, checkout-credit-card, order
- Shared chunks in `dist/ui/shared/`
- Auto-zip plugin creates distribution package

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

Third-party deference (per field group, checked in `LegacyCheckoutFields`):
- **Brazilian Market on WooCommerce** (`woocommerce-extra-checkout-fields-for-brazil`): detected via `class_exists( 'Extra_Checkout_Fields_For_Brazil' )` — never use `Extra_Checkout_Fields_For_Brazil_Front_End` (the LinkNacional plugin ships a stub of it). Settings option: `wcbcf_settings` (`person_type` 0=off/1=both/2=CPF/3=CNPJ, `cell_phone`). Classic checkout only (no Blocks support). Deprecated path: when active, a dismissible admin notice (`LegacyCheckoutFields::maybe_render_brazilian_market_deprecation_notice`) recommends deactivating it — the plugin is unmaintained (no alphanumeric CNPJ support) and compatibility will eventually be dropped. Dismissing stores a per-user timestamp (AJAX + user meta) that snoozes the notice for 24h; it reappears while the plugin stays active.
- **Calculadora de Frete e Campos Checkout para o Brasil** (LinkNacional, `woo-better-shipping-calculator-for-brazil`): detected via `defined( 'WC_BETTER_SHIPPING_CALCULATOR_FOR_BRAZIL_VERSION' )`. Option: `woo_better_calc_person_type_select` (`none|physical|legal|both`). Supports Blocks — when active with person type enabled, the `pagbank/*` Blocks fields are not registered.

When either plugin provides a field group, the native fields for that group are not inserted. `ApiHelpers` reads the Blocks additional fields first and falls back to the legacy `_billing_*` meta, so every combination resolves.

## Development Environment Details

- WordPress runs on PHP 8.3 with Xdebug automatically installed
- Database: MariaDB
- PHPMyAdmin available on port 8080
- Plugin mounted as volume for hot-reload
- Custom PHP settings in `wordpress.ini`
