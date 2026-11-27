#!/bin/sh
# Arranque del contenedor de la API (y del programador de tareas, que usa la misma imagen).
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "Falta APP_KEY. Créela con: php artisan key:generate --show (ver despliegue/README.md)" >&2
  exit 1
fi

# El volumen de las fotos empieza vacío la primera vez
if [ "$(id -u)" = "0" ]; then
  mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
  chown -R www-data:www-data storage
fi

# Solo el contenedor de la API actualiza la base de datos (el programador espera a que esté lista)
if [ "${CIERRE_MIGRAR:-1}" = "1" ]; then
  php artisan migrate --force
  # Crea la cuenta del admin si todavía no existe (si ya existe, no le cambia la contraseña)
  php artisan cierre:admin
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
