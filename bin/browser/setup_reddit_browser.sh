#!/usr/bin/env bash
# One-time setup for the Reddit browser auto poster ("share-button method").
# Installs Playwright + headless Chromium under storage/reddit-browser/ and
# verifies the worker scripts parse. Run on the server that runs the auto
# poster (the prod VPS):  sudo bash bin/browser/setup_reddit_browser.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
DIR="$ROOT/storage/reddit-browser"
mkdir -p "$DIR"

command -v node >/dev/null 2>&1 || { echo "node is required (Node 18+). Install nodejs first." >&2; exit 1; }

if [ ! -d "$DIR/node_modules/playwright" ]; then
    echo ">> installing playwright in $DIR ..."
    (cd "$DIR" && npm init -y >/dev/null 2>&1 && npm install playwright --no-audit --no-fund)
else
    echo ">> playwright already installed"
fi

echo ">> installing headless chromium (+ system deps) ..."
(cd "$DIR" && npx playwright install chromium --with-deps)

node --check "$ROOT/bin/browser/reddit-post.mjs"
node --check "$ROOT/bin/browser/reddit-login.mjs"
php -l "$ROOT/bin/reddit_post.php" >/dev/null

echo ""
echo "OK. Now log in once so a session is saved:"
echo "  cd $DIR && xvfb-run -a node $ROOT/bin/browser/reddit-login.mjs --headless"
echo "(or on a machine with a display: node $ROOT/bin/browser/reddit-login.mjs)"
echo "Then tick 'Browser post' in Admin -> Auto Poster -> Reddit."