#!/usr/bin/env bash
# Builds dist/election-shield-shared-hosting.zip for cPanel / DirectAdmin
# hosting without SSH: production dependencies included, a public_html front
# controller, and a .env with freshly generated secrets.
#
#   scripts/build-shared-hosting.sh            first install (includes .env)
#   scripts/build-shared-hosting.sh --update   update zip without .env, so
#                                              extracting it keeps your settings
#
# Mail settings can be pre-filled from the environment when building:
#   MAIL_HOST=... MAIL_PORT=465 MAIL_SCHEME=smtps MAIL_USERNAME=... \
#   MAIL_PASSWORD=... MAIL_FROM_ADDRESS=... NOTIFY_EMAILS=... scripts/build-shared-hosting.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build/shared-hosting"
DIST="$ROOT/dist"
UPDATE=false
[ "${1:-}" = "--update" ] && UPDATE=true
SUFFIX=""
if $UPDATE; then SUFFIX="-update"; fi
ZIP="$DIST/election-shield-shared-hosting$SUFFIX.zip"

rm -rf "$BUILD" && mkdir -p "$BUILD/election-shield" "$BUILD/public_html" "$DIST"

# Application code (tracked files only, so local .env / databases never leak).
git -C "$ROOT" ls-files -z --cached --others --exclude-standard \
  | grep -zv -E '^(tests/|\.github/|deploy/|scripts/|public/|phpunit\.xml|package\.json|vite\.config\.js|resources/(css|js)/)' \
  | (cd "$ROOT" && while IFS= read -r -d '' file; do
      [ -f "$file" ] && cp --parents "$file" "$BUILD/election-shield"
    done)

(cd "$BUILD/election-shield" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --prefer-dist --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --quiet)
(cd "$BUILD/election-shield" && php artisan package:discover --ansi >/dev/null)

# Packages installed from source carry their git history; it is not needed to run.
find "$BUILD/election-shield/vendor" -depth -type d \( -name .git -o -name .github \) -exec rm -rf {} +

mkdir -p "$BUILD/election-shield/storage/"{app/private,framework/{cache/data,sessions,views},logs}
rm -f "$BUILD/election-shield/bootstrap/cache/"*.php

cp "$ROOT/deploy/shared-hosting/"{index.php,.htaccess,robots.txt} "$BUILD/public_html/"
touch "$BUILD/public_html/favicon.ico"

# .env with generated secrets (first install only).
$UPDATE || php -r '
    $env = file_get_contents($argv[1]);
    $random = fn ($bytes) => bin2hex(random_bytes($bytes));
    $values = [
        "APP_KEY" => "base64:".base64_encode(random_bytes(32)),
        "ADMIN_PASSWORD" => $random(12),
        "USSD_CALLBACK_SECRET" => $random(24),
        "ELECTION_API_TOKEN" => $random(24),
        "MAIL_SCHEME" => getenv("MAIL_SCHEME") ?: "smtps",
        "MAIL_HOST" => getenv("MAIL_HOST") ?: "CHANGE-ME",
        "MAIL_PORT" => getenv("MAIL_PORT") ?: "465",
        "MAIL_USERNAME" => getenv("MAIL_USERNAME") ?: "CHANGE-ME",
        "MAIL_PASSWORD" => getenv("MAIL_PASSWORD") ?: "CHANGE-ME",
        "MAIL_FROM_ADDRESS" => getenv("MAIL_FROM_ADDRESS") ?: "CHANGE-ME",
        "NOTIFY_EMAILS" => getenv("NOTIFY_EMAILS") ?: "CHANGE-ME",
    ];
    foreach ($values as $key => $value) {
        $env = str_replace("{{".$key."}}", $value, $env);
    }
    file_put_contents($argv[2], $env);
' "$ROOT/deploy/shared-hosting/env.template" "$BUILD/election-shield/.env"

rm -f "$ZIP"
(cd "$BUILD" && zip -qr "$ZIP" election-shield public_html)

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
$UPDATE || echo "Admin password: $(grep '^ADMIN_PASSWORD=' "$BUILD/election-shield/.env" | cut -d= -f2)"
