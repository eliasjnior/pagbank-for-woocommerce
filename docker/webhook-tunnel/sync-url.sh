#!/bin/sh
# Keeps PAGBANK_WEBHOOK_SITE_URL pointed at the running quick tunnel, whose hostname changes on
# every start, and removes it once the tunnel is gone so orders don't get a dead notification URL.
set -u

CONFIG=/var/www/html/wp-config.php
METRICS=http://webhook-tunnel:2000
# Unknown until the first check, which also drops a value left by a stack that did not stop cleanly.
current=unknown

wp() {
	command wp --path=/var/www/html --quiet "$@"
}

tunnel_url() {
	curl -fsS --max-time 3 "$METRICS/ready" >/dev/null 2>&1 || return 1
	host=$(curl -fsS --max-time 3 "$METRICS/quicktunnel" | sed -n 's/.*"hostname":"\([^"]*\)".*/\1/p')
	[ -n "$host" ] && echo "https://$host"
}

clear_url() {
	if [ -n "$current" ] && [ -f "$CONFIG" ] && wp config has PAGBANK_WEBHOOK_SITE_URL; then
		wp config delete PAGBANK_WEBHOOK_SITE_URL && echo "Tunnel down: PAGBANK_WEBHOOK_SITE_URL removed."
	fi
	current=
}

trap 'clear_url; exit 0' TERM INT

while :; do
	if [ -f "$CONFIG" ]; then
		if url=$(tunnel_url); then
			if [ "$url" != "$current" ] && wp config set PAGBANK_WEBHOOK_SITE_URL "$url"; then
				current=$url
				echo "PagBank webhooks: $url/wc-api/pagbank_woocommerce_handler"
			fi
		else
			clear_url
		fi
	fi

	sleep 3 &
	wait $!
done
