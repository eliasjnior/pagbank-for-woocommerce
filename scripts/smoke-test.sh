#!/usr/bin/env bash
# Smoke-tests the plugin on a running wp-env site with WooCommerce and the plugin active.
# Run it from the folder that holds the site's .wp-env.json.
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8888}"
GATEWAYS=(
	pagbank_credit_card
	pagbank_debit_card
	pagbank_pix
	pagbank_boleto
	pagbank_pay_with_pagbank
	pagbank_checkout
	pagbank_google_pay
	pagbank_apple_pay
)

cookies=$(mktemp)
trap 'rm -f "$cookies"' EXIT

wp() {
	npx --yes "@wordpress/env@${WP_ENV_VERSION:-latest}" run cli wp "$@" 2>/dev/null
}

fail() {
	echo "::error::$*"
	exit 1
}

# A marker proves the page under test rendered, not a login screen or redirect that also answers 200.
check_page() {
	local url=$1 marker=$2 response code
	response=$(curl -sSL -b "$cookies" -w '\n%{http_code}' "$url")
	code=${response##*$'\n'}

	if [ "$code" != 200 ]; then
		fail "$url answered HTTP $code"
	fi

	if grep -qiE 'critical error|fatal error|parse error' <<<"$response"; then
		fail "$url rendered a PHP error"
	fi

	if ! grep -qF "$marker" <<<"$response"; then
		fail "$url did not render $marker"
	fi

	echo "OK $url"
}

wp plugin is-active woocommerce || fail "WooCommerce is not active"
wp plugin is-active pagbank-for-woocommerce || fail "PagBank for WooCommerce is not active"

# Activation hooks run again, as they do on every update.
wp plugin deactivate pagbank-for-woocommerce >/dev/null
wp plugin activate pagbank-for-woocommerce >/dev/null

registered=$(wp eval 'echo implode( "\n", array_keys( WC()->payment_gateways()->payment_gateways() ) );')
for gateway in "${GATEWAYS[@]}"; do
	grep -qx "$gateway" <<<"$registered" || fail "Payment gateway $gateway is not registered"
done
echo "OK ${#GATEWAYS[@]} payment gateways registered"

# Keeps WooCommerce's onboarding redirect from replacing the admin pages under test.
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp transient delete _wc_activation_redirect >/dev/null || true

curl -sS -o /dev/null -c "$cookies" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
	--data-urlencode 'log=admin' --data-urlencode 'pwd=password' -d 'testcookie=1' \
	"$BASE_URL/wp-login.php"

check_page "$BASE_URL/" '</html>'
check_page "$(wp eval 'echo get_permalink( wc_get_page_id( "checkout" ) );')" '</html>'
check_page "$BASE_URL/wp-admin/plugins.php" 'data-slug="pagbank-for-woocommerce"'
check_page "$BASE_URL/wp-admin/admin.php?page=wc-settings&tab=checkout" 'id="wpadminbar"'
for gateway in "${GATEWAYS[@]}"; do
	check_page "$BASE_URL/wp-admin/admin.php?page=wc-settings&tab=checkout&section=$gateway" \
		"data-gateway-id=\"$gateway\""
done

# shellcheck disable=SC2016 # PHP source, expanded by PHP rather than the shell.
errors=$(wp eval '
	$log = WP_CONTENT_DIR . "/debug.log";
	if ( file_exists( $log ) ) {
		echo implode( "\n", preg_grep( "/PHP (Fatal|Parse|Warning|Notice|Deprecated).*pagbank-for-woocommerce/", file( $log, FILE_IGNORE_NEW_LINES ) ) );
	}
')
if [ -n "$errors" ]; then
	echo "$errors"
	fail "The plugin logged PHP errors"
fi
echo "OK no PHP errors logged by the plugin"
