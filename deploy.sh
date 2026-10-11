#!/bin/zsh
# Deploys the committed state of public/ to amor.kimiyu.ch (Hostpoint) over SSH,
# using your own SSH key. Run from your Mac: ./deploy.sh
#
# There is no build step: the app is the static files in public/. The test double
# (public/mock.js) is not uploaded.
set -euo pipefail
cd "$(dirname "$0")"

HOST=pumutuxu@pumutuxu.myhostpoint.ch
DEST=/home/pumutuxu/www/amor.kimiyu.ch/public  # the web root set up in the Hostpoint panel

if [[ -n $(git status --porcelain) ]]; then
  echo "You have uncommitted changes. Commit them first; only committed code is deployed."
  exit 1
fi

echo "Running tests…"
node --test tests/*.test.mjs

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
# mktemp creates the folder as 700; rsync would copy that onto the site folder and lock out the web server.
chmod 755 "$STAGE"

echo "Preparing $(git rev-parse --short HEAD)…"
git archive HEAD public | tar -x -C "$STAGE"

echo "Uploading…"
rsync -az --delete --exclude '/mock.js' "$STAGE/public/" "$HOST:$DEST/"

echo "Deployed $(git rev-parse --short HEAD) to https://amor.kimiyu.ch"
