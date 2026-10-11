#!/bin/zsh
# Deploys the committed state of this repository to amor.kimiyu.ch (Hostpoint) over SSH,
# using your own SSH key. Run from your Mac: ./deploy.sh
#
# Frontend assets are built locally and committed (npm run build). The server's .env, the
# SQLite database and storage/ (row strips, logs) are never overwritten or deleted.
set -euo pipefail
cd "$(dirname "$0")"

HOST=pumutuxu@pumutuxu.myhostpoint.ch
DEST=/home/pumutuxu/www/amor.kimiyu.ch   # the web root is $DEST/public
PHP=/usr/local/php83/bin/php

if [[ -n $(git status --porcelain) ]]; then
  echo "You have uncommitted changes. Commit them first; only committed code is deployed."
  exit 1
fi
if [[ ! -f public/build/manifest.json ]]; then
  echo "public/build is missing. Run 'npm run build' and commit the result."
  exit 1
fi

echo "Running tests…"
php artisan test

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
# mktemp creates the folder as 700; rsync would copy that onto the site folder and lock out the web server.
chmod 755 "$STAGE"

echo "Preparing $(git rev-parse --short HEAD)…"
git archive HEAD | tar -x -C "$STAGE"
composer install --working-dir="$STAGE" --no-interaction --no-progress --no-dev --optimize-autoloader --quiet
# Hostpoint picks the PHP version from the first line of public/.htaccess.
{ echo "Use php-fpm php83"; echo; cat "$STAGE/public/.htaccess"; } > "$STAGE/public/.htaccess.new"
mv "$STAGE/public/.htaccess.new" "$STAGE/public/.htaccess"

echo "Uploading…"
rsync -az --delete \
  --exclude '/.github' --exclude '/node_modules' --exclude '/tests' --exclude '/docs' --exclude '/scripts' \
  --exclude '/.env' --exclude '/public/hot' --exclude '/deploy.sh' \
  --filter 'protect /.env' \
  --filter 'protect /storage/**' \
  --filter 'protect /bootstrap/cache/*.php' \
  --filter 'protect /database/database.sqlite' \
  "$STAGE/" "$HOST:$DEST/"

echo "Migrating and refreshing caches…"
ssh "$HOST" "cd '$DEST' && touch database/database.sqlite && $PHP artisan migrate --force >/dev/null && $PHP artisan optimize:clear >/dev/null && $PHP artisan optimize >/dev/null"

echo "Deployed $(git rev-parse --short HEAD) to https://amor.kimiyu.ch"
