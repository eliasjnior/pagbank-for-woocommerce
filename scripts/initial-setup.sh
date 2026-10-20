# Install dependencies
pnpm install

# Reserve this worktree's ports before Docker uses them; the main checkout keeps the defaults
npx tsx scripts/worktree-env.ts
if [ -f .env ]; then
	set -a
	. ./.env
	set +a
fi

./scripts/composer.sh install

# Build assets
pnpm run build

# Install WordPress
docker compose run --rm wordpress-cli wp core install --url="${WP_URL:-wordpress.localhost}" --title="WordPress Demo" --admin_name=wordpress --admin_password=wordpress --admin_email=you@example.com

# The cached image may ship an older WordPress than the latest WooCommerce requires
docker compose run --rm wordpress-cli wp core update
docker compose run --rm wordpress-cli wp core update-db

# Setup permalinks
docker compose run --rm wordpress-cli wp rewrite structure '/%postname%/'

# Install plugins
docker compose run --rm wordpress-cli wp plugin install woocommerce woocommerce-extra-checkout-fields-for-brazil --activate

# Activate plugin
docker compose run --rm wordpress-cli wp plugin activate pagbank-for-woocommerce

# Delete plugins
docker compose run --rm wordpress-cli wp plugin delete akismet hello

# Install theme
docker compose run --rm wordpress-cli wp theme install storefront --activate

# Remove themes
docker compose run --rm wordpress-cli wp theme delete twentynineteen twentytwenty twentytwentyone twentytwentytwo twentytwentythree
