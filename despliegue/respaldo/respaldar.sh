#!/bin/sh
# Guarda una copia de la base de datos todos los días a la hora RESPALDO_HORA (hora de Colombia)
# en /respaldos y borra las de más de RESPALDO_DIAS días.
#
# Ese volumen ("respaldos") es el que Dokploy sube a S3 con "Volume Backups": así la copia queda
# también fuera del servidor. No se respalda directamente el volumen de MySQL porque copiar sus
# archivos mientras la base está andando puede dar una copia dañada.
#
# Para sacar una copia ya (por ejemplo, antes de una actualización), en la terminal del
# contenedor "respaldo" de Dokploy:  respaldar ahora
set -eu

HORA="${RESPALDO_HORA:-02:30}"
DIAS="${RESPALDO_DIAS:-14}"
CARPETA=/respaldos

# La contraseña va en un archivo y no en el comando (no queda a la vista en la lista de procesos)
CREDENCIALES=$(mktemp)
chmod 600 "$CREDENCIALES"
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USERNAME" "$DB_PASSWORD" > "$CREDENCIALES"

respaldar() {
  archivo="$CARPETA/cierre_caja-$(date +%Y-%m-%d_%H%M).sql"
  if mysqldump --defaults-extra-file="$CREDENCIALES" --single-transaction --no-tablespaces \
      --routines --triggers "$DB_DATABASE" > "$archivo.parcial"; then
    mv "$archivo.parcial" "$archivo"
    echo "Copia guardada: $archivo ($(du -h "$archivo" | cut -f1))"
  else
    rm -f "$archivo.parcial"
    echo "ERROR: no se pudo sacar la copia de la base de datos" >&2
  fi
  find "$CARPETA" -name 'cierre_caja-*.sql' -mtime +"$DIAS" -delete
}

mkdir -p "$CARPETA"

if [ "${1:-}" = "ahora" ]; then
  respaldar
  exit 0
fi

echo "Copias diarias a las $HORA; se guardan $DIAS días en $CARPETA"
while true; do
  ahora=$(date +%s)
  siguiente=$(date -d "today $HORA" +%s)
  [ "$siguiente" -le "$ahora" ] && siguiente=$(date -d "tomorrow $HORA" +%s)
  sleep $((siguiente - ahora))
  respaldar
done
