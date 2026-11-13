#!/usr/bin/env bash
# Se ejecuta EN el servidor de Hostinger (vía SSH) desde GitHub Actions.
# Variables: APP_DOMAIN (obligatoria), PHP_BIN (por defecto: PHP 8.4 de Hostinger)
#
# Estructura (compatible con el paquete de despliegue manual):
#   ~/domains/<dominio>/public_html/
#       index.html, assets/, favicon.svg   frontend React
#       laravel.php, .htaccess             entrada de la API y reglas de reescritura
#       appsanzac/                         Laravel (bloqueado para la web): .env y storage se conservan
set -euo pipefail

: "${APP_DOMAIN:?Falta APP_DOMAIN}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
BASE="$HOME/domains/$APP_DOMAIN"
WEB="$BASE/public_html"
APP="$WEB/appsanzac"
REL="$BASE/.release"

echo "==> Desplegando en $WEB con PHP $($PHP_BIN -r 'echo PHP_VERSION;')"
mkdir -p "$WEB"

# Migración desde la estructura anterior (Laravel fuera de public_html)
if [ ! -d "$APP" ] && [ -d "$BASE/appsanzac" ]; then
  echo "==> Moviendo la instalación existente a public_html/appsanzac"
  mv "$BASE/appsanzac" "$APP"
fi
mkdir -p "$APP"

# ---------- Paquetes ----------
rm -rf "$REL" && mkdir -p "$REL/backend" "$REL/frontend"
tar -xzf "$BASE/backend.tar.gz" -C "$REL/backend"
tar -xzf "$BASE/frontend.tar.gz" -C "$REL/frontend"

# ---------- Backend Laravel (se conservan .env y storage) ----------
for item in app bootstrap config database resources routes vendor public artisan composer.json composer.lock; do
  rm -rf "${APP:?}/$item"
  mv "$REL/backend/$item" "$APP/$item"
done
cp "$REL/frontend/appsanzac.htaccess" "$APP/.htaccess"
rm -f "$REL/frontend/appsanzac.htaccess"

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

# Carga inicial solo si la base está vacía (p. ej. no se importó el SQL del paquete manual)
if [ ! -f storage/app/.seeded ]; then
  USERS="$("$PHP_BIN" artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n1 | tr -dc '0-9')"
  if [ "${USERS:-0}" = "0" ]; then
    echo "==> Base vacía: carga inicial de datos"
    "$PHP_BIN" artisan db:seed --force
  else
    echo "==> La base ya tiene $USERS usuarios: se omite la carga inicial"
  fi
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
