#!/usr/bin/env bash
# Prints the public webhook URL that the quick tunnel gave this stack.
set -euo pipefail

if ! url=$(docker compose exec -T -u www-data wordpress wp config get PAGBANK_WEBHOOK_SITE_URL 2>/dev/null); then
	echo "No webhook tunnel running; see logs with: docker compose logs webhook-tunnel webhook-url" >&2
	exit 1
fi

echo "$url/wc-api/pagbank_woocommerce_handler"
