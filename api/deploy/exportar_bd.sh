#!/usr/bin/env bash
# Exporta la BD "casa" (estructura + datos) a un .sql listo para importar en el servidor.
#
#   ./api/deploy/exportar_bd.sh              → backups/casa_AAAAMMDD_HHMM.sql
#
# Importar en el servidor (crea la BD y el usuario primero si es la primera vez):
#   sudo mariadb < instalar_servidor.sql
#   sudo mariadb casa < casa_AAAAMMDD_HHMM.sql
#
# Funciona igual en el servidor para sacar respaldos (usa "sudo mariadb-dump" automáticamente).
set -euo pipefail

DB="${DB_NAME:-casa}"
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
OUT_DIR="$ROOT_DIR/backups"
OUT="$OUT_DIR/${DB}_$(date +%Y%m%d_%H%M).sql"

if command -v mariadb-dump >/dev/null 2>&1; then
  DUMP=(mariadb-dump)
else
  # mysqldump de MySQL 8: sin GTID ni tablespaces, que MariaDB no entiende.
  DUMP=(mysqldump --set-gtid-purged=OFF --column-statistics=0)
fi
# En el servidor, root de MariaDB entra por unix_socket: hace falta sudo.
CLIENT=$(command -v mariadb || command -v mysql)
if [ "$(id -u)" -ne 0 ] && ! "$CLIENT" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
  DUMP=(sudo "${DUMP[@]}")
fi

mkdir -p "$OUT_DIR"
chmod 700 "$OUT_DIR"

"${DUMP[@]}" -uroot \
  --single-transaction \
  --no-tablespaces \
  --skip-comments \
  --default-character-set=utf8mb4 \
  --databases "$DB" \
  --add-drop-table=false \
  --replace \
  > "$OUT"

# MySQL 8 a veces escribe la colación utf8mb4_0900_ai_ci, que MariaDB no tiene.
sed -i 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' "$OUT"
# Al restaurar sobre una BD existente, no fallar si las tablas ya existen.
sed -i 's/^CREATE TABLE `/CREATE TABLE IF NOT EXISTS `/; s/^CREATE DATABASE \/\*!32312 IF NOT EXISTS\*\//CREATE DATABASE IF NOT EXISTS/' "$OUT"

chmod 600 "$OUT"
echo "Exportado: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Incluye teléfonos de la familia: no lo compartas. Las fotos están en api/public/uploads/dogs/."
