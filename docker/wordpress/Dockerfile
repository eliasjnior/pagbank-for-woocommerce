FROM wordpress:php8.3

# WP-CLI runs inside this container (`pnpm wp`), and msmtp hands mail() to Mailpit.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends msmtp \
	&& rm -rf /var/lib/apt/lists/* \
	&& curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp \
	&& a2enmod ssl \
	&& a2dissite 000-default \
	&& openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
		-keyout /etc/ssl/private/server.key -out /etc/ssl/certs/server.crt \
		-subj '/CN=localhost/O=PagBank Development/C=BR'

COPY entrypoint.sh /usr/local/bin/pagbank-entrypoint

ENTRYPOINT ["pagbank-entrypoint"]
CMD ["apache2-foreground"]
