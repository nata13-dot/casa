#!/usr/bin/env bash
# Sube la API al servidor y actualiza la base de datos, en un solo paso.
# Se ejecuta desde TU computadora (no en el servidor):
#
#   ./api/deploy/subir_api.sh usuario@servidor [/var/www/casa/api]
#
# Qué hace:
#   1. Copia api/ a una carpeta temporal en el servidor (sin .env, fotos, caché ni SQL con contraseñas).
#   2. En el servidor (pide tu contraseña de sudo):
#        - respaldo de la BD en ~/casa_respaldos/
#        - copia los archivos a la carpeta de la API (NO toca .env, fotos ni caché)
#        - corre database/actualizar.sql (se puede repetir sin problema)
#        - instala/actualiza el timer de recordatorios de los perritos
#        - recarga PHP-FPM
#   3. Comprueba que la API pública responda con la versión nueva.
set -euo pipefail

HOST="${1:-}"
DEST="${2:-/var/www/casa/api}"
PUBLIC_API="https://casa.espinazodeldiablo.site/api"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="casa-api-subida"

if [ -z "$HOST" ]; then
  echo "Uso: $0 usuario@servidor [/ruta/de/la/api]   (por defecto $DEST)" >&2
  exit 1
fi

EXPECTED=$(sed -n "s/^const API_VERSION = '\(.*\)';/\1/p" "$SRC/src/bootstrap.php")
echo "▶ Subiendo API v$EXPECTED a $HOST:$DEST"

rsync -az --delete \
  --exclude '.env' \
  --exclude 'database/instalar_servidor.sql' \
  --exclude 'public/uploads/dogs/*' \
  --exclude 'storage/cache/*' \
  "$SRC/" "$HOST:$STAGE/"

# shellcheck disable=SC2087  # las variables locales se expanden a propósito
ssh -t "$HOST" bash -s <<EOF
set -euo pipefail
if [ ! -f "$DEST/.env" ]; then
  echo "✗ No encontré $DEST/.env en el servidor. Revisa la ruta (2º parámetro)." >&2
  exit 1
fi
mkdir -p ~/casa_respaldos
echo "▶ Respaldo de la base de datos"
sudo mariadb-dump casa > ~/casa_respaldos/casa_\$(date +%Y%m%d_%H%M).sql
echo "▶ Copiando archivos (sin tocar .env, fotos ni caché)"
sudo rsync -a --no-owner --no-group --chmod=D755,F644 \
  --exclude '.env' --exclude 'public/uploads/' --exclude 'storage/' \
  ~/$STAGE/ "$DEST/"
echo "▶ Actualizando la base de datos"
sudo mariadb casa < "$DEST/database/actualizar.sql"
echo "▶ Recordatorios de los perritos (cada 5 min)"
sudo cp "$DEST/deploy/casa-recordatorios.service" "$DEST/deploy/casa-recordatorios.timer" /etc/systemd/system/
sudo sed -i "s#/var/www/casa/api#$DEST#" /etc/systemd/system/casa-recordatorios.service
sudo systemctl daemon-reload
sudo systemctl enable --now casa-recordatorios.timer
echo "▶ Recargando PHP-FPM"
sudo systemctl reload php8.2-fpm
rm -rf ~/$STAGE
EOF

echo "▶ Comprobando la API pública"
HEALTH=$(curl -s -m 15 "$PUBLIC_API/health" || true)
if echo "$HEALTH" | grep -q "\"version\":\"$EXPECTED\""; then
  echo "✓ Listo: la API responde con la versión $EXPECTED"
else
  echo "✗ La API no reporta la versión $EXPECTED. Respuesta: $HEALTH" >&2
  exit 1
fi
