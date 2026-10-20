#!/bin/sh
set -e

# A certificate in certs/ replaces the self-signed one baked into the image.
if ls /etc/ssl/custom-certs/*.crt >/dev/null 2>&1; then
	cp /etc/ssl/custom-certs/*.crt /etc/ssl/certs/server.crt
	cp /etc/ssl/custom-certs/*.key /etc/ssl/private/server.key
	chmod 600 /etc/ssl/private/server.key
fi

exec docker-entrypoint.sh "$@"
