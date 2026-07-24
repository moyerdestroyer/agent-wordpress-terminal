#!/usr/bin/env bash
# Build a production install zip the same way GitHub Actions does.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

PLUGIN_SLUG="${PLUGIN_SLUG:-agent-wordpress-terminal}"
OUT_DIR="${OUT_DIR:-dist}"
ZIP_NAME="${ZIP_NAME:-${PLUGIN_SLUG}.zip}"

echo "==> Installing production Composer dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Installing npm dependencies"
if [[ -f package-lock.json ]]; then
  npm ci
else
  npm install
fi

echo "==> Building frontend assets"
npm run build

echo "==> Packaging ${ZIP_NAME}"
rm -rf "${OUT_DIR}/${PLUGIN_SLUG}"
mkdir -p "${OUT_DIR}/${PLUGIN_SLUG}"
rsync -a --delete --exclude-from=.distignore ./ "${OUT_DIR}/${PLUGIN_SLUG}/"

test -f "${OUT_DIR}/${PLUGIN_SLUG}/agent-wordpress-terminal.php"
test -f "${OUT_DIR}/${PLUGIN_SLUG}/vendor/autoload.php"
test -f "${OUT_DIR}/${PLUGIN_SLUG}/build/manifest.json"
test -d "${OUT_DIR}/${PLUGIN_SLUG}/build/assets"
test -n "$(find "${OUT_DIR}/${PLUGIN_SLUG}/build/assets" -maxdepth 1 -type f \( -name '*.js' -o -name '*.css' \) | head -1)"
test ! -d "${OUT_DIR}/${PLUGIN_SLUG}/node_modules"
test ! -d "${OUT_DIR}/${PLUGIN_SLUG}/tests"
test ! -d "${OUT_DIR}/${PLUGIN_SLUG}/assets"

rm -f "${ZIP_NAME}"
(
  cd "${OUT_DIR}"
  zip -r "../${ZIP_NAME}" "${PLUGIN_SLUG}"
)

echo "==> Done: ${ROOT}/${ZIP_NAME}"
ls -lh "${ZIP_NAME}"
