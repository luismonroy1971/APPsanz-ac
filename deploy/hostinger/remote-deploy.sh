#!/usr/bin/env bash
# Se ejecuta EN el servidor de Hostinger (vía SSH) desde GitHub Actions.
# Variables: APP_DOMAIN (obligatoria), PHP_BIN (por defecto: php), SEED_DEMO (true/false)
set -euo pipefail

: "${APP_DOMAIN:?Falta APP_DOMAIN}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
BASE="$HOME/domains/$APP_DOMAIN"
APP="$BASE/appsanzac"
WEB="$BASE/public_html"
REL="$BASE/.release"

echo "==> Desplegando en $BASE con $($PHP_BIN -r 'echo PHP_VERSION;')"
mkdir -p "$APP" "$WEB"

# ---------- Backend Laravel ----------
rm -rf "$REL" && mkdir -p "$REL/backend" "$REL/frontend"
tar -xzf "$BASE/backend.tar.gz" -C "$REL/backend"
tar -xzf "$BASE/frontend.tar.gz" -C "$REL/frontend"

for item in app bootstrap config database resources routes vendor public artisan composer.json composer.lock; do
  rm -rf "${APP:?}/$item"
  mv "$REL/backend/$item" "$APP/$item"
done

mkdir -p "$APP"/storage/app/private "$APP"/storage/app/public "$APP"/storage/fonts \
         "$APP"/storage/framework/{cache/data,sessions,views,testing} "$APP"/storage/logs "$APP"/bootstrap/cache
chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"

# .env: generado por GitHub Actions; se conserva el APP_KEY existente
KEY=""
if [ -f "$APP/.env" ]; then KEY="$(grep -E '^APP_KEY=' "$APP/.env" | cut -d= -f2- || true)"; fi
mv "$BASE/.env.production" "$APP/.env"
chmod 600 "$APP/.env"
if [ -n "$KEY" ]; then
  sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" "$APP/.env"
fi

cd "$APP"
"$PHP_BIN" artisan down --retry=15 || true
"$PHP_BIN" artisan config:clear
if [ -z "$KEY" ]; then "$PHP_BIN" artisan key:generate --force; fi

"$PHP_BIN" artisan migrate --force

# Carga inicial (roles, plan contable y, si SEED_DEMO=true, datos de demostración) una sola vez
if [ ! -f storage/app/.seeded ]; then
  echo "==> Carga inicial de datos"
  "$PHP_BIN" artisan db:seed --force
  touch storage/app/.seeded
fi

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up

# ---------- Frontend React ----------
rm -rf "$WEB/assets"
cp -a "$REL/frontend/." "$WEB/"
rm -f "$WEB/default.php"   # página por defecto de Hostinger

rm -rf "$REL" "$BASE/backend.tar.gz" "$BASE/frontend.tar.gz"
echo "==> Despliegue completado: https://$APP_DOMAIN"
