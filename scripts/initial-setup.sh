#!/usr/bin/env bash
# Installs and configures the development site. Safe to run again: every step skips what is done.
set -euo pipefail

pnpm install --frozen-lockfile

# Reserve this worktree's ports before Docker uses them; the main checkout keeps the defaults
npx tsx scripts/worktree-env.ts
if [ -f .env ]; then
	set -a
	. ./.env
	set +a
fi
WP_URL=${WP_URL:-http://wordpress.localhost}

# The stack boots while the dependencies install and the assets build
mkdir -p logs
docker compose up -d --wait >logs/docker-up.log 2>&1 &
docker_up=$!
./scripts/composer.sh install
pnpm run build
if ! wait "$docker_up"; then
	cat logs/docker-up.log
	exit 1
fi

wp() {
	docker compose exec -T -u www-data wordpress wp "$@"
}

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$WP_URL" --title="WordPress Demo" --admin_user=wordpress --admin_password=wordpress --admin_email=you@example.com --skip-email
fi

# The image may ship an older WordPress than the latest WooCommerce requires
wp core update
wp core update-db

# Development mode: report everything, log it to logs/debug.log and load unminified scripts
wp config set WP_ENVIRONMENT_TYPE development
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG /var/log/debug.log
wp config set WP_DEBUG_DISPLAY true --raw
wp config set SCRIPT_DEBUG true --raw

wp rewrite structure '/%postname%/'

wp plugin install woocommerce woocommerce-extra-checkout-fields-for-brazil --activate
wp plugin activate pagbank-for-woocommerce
wp plugin delete akismet hello 2>/dev/null || true

wp theme install storefront --activate
for theme in $(wp theme list --status=inactive --field=name); do
	wp theme delete "$theme"
done

# A Brazilian store that is open and ready to check out
wp option update woocommerce_default_country 'BR:SP'
wp option update woocommerce_currency BRL
wp option update woocommerce_store_address 'Rua de Teste, 100'
wp option update woocommerce_store_city 'São Paulo'
wp option update woocommerce_store_postcode '01001-000'
wp option update woocommerce_coming_soon no
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json

# WooCommerce's own sample catalogue: simple, variable, grouped and downloadable products with images
if [ "$(wp post list --post_type=product --format=count)" = 0 ]; then
	wp plugin install wordpress-importer --activate
	wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=skip --skip=image_resize --quiet
	wp plugin uninstall wordpress-importer --deactivate
fi

# The shop is the home page, and the cart and checkout use Storefront's template without a sidebar
wp option update show_on_front page
wp option update page_on_front "$(wp option get woocommerce_shop_page_id)"
for page in cart checkout; do
	wp post meta update "$(wp option get "woocommerce_${page}_page_id")" _wp_page_template template-fullwidth.php
done

# Checkout is the Blocks one; the shortcode checkout stays reachable for testing the classic flow
if [ -z "$(wp post list --post_type=page --name=legacy-checkout --field=ID)" ]; then
	wp post create --post_type=page --post_status=publish --post_title='Legacy checkout' --post_name=legacy-checkout \
		--post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' \
		--meta_input='{"_wp_page_template":"template-fullwidth.php"}'
fi

# The PagBank connection saved with `pnpm pagbank:save-credentials` spares a new OAuth per worktree
main_env="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.env"
for environment in sandbox production; do
	key="PAGBANK_CONNECT_DATA_$(echo "$environment" | tr '[:lower:]' '[:upper:]')"
	line=$(grep -E "^$key=" "$main_env" 2>/dev/null || true)
	if [ -n "$line" ]; then
		value=${line#*=}
		value=${value#\'}
		value=${value%\'}
		wp option update "woocommerce_pagbank_connect_data_$environment" "$value" --format=json
	elif [ "$environment" = sandbox ]; then
		echo "No sandbox PagBank connection in $main_env; connect the plugin once and run pnpm pagbank:save-credentials."
	fi
done

# A new site starts with every PagBank gateway enabled on sandbox, even when production is
# connected too; settings saved later are left alone.
for gateway in $(wp wc payment_gateway list --user=wordpress --field=id | grep '^pagbank_'); do
	if ! wp option get "woocommerce_${gateway}_settings" >/dev/null 2>&1; then
		wp option update "woocommerce_${gateway}_settings" '{"enabled":"yes","environment":"sandbox"}' --format=json
	fi
done

echo "Setup complete: $WP_URL (wordpress / wordpress)"
