#!/bin/bash

# AUXIO - Script de configuración de cron
# 
# Este script configura actualización automática de alertas cada 5 minutos
# 
# Uso:
#   bash cron-setup.sh

SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PHP_BIN="/usr/bin/php"

# Verifica que PHP esté disponible
if ! command -v $PHP_BIN &> /dev/null; then
    PHP_BIN="php"
fi

# Cron job: ejecuta generate.php cada 5 minutos
CRON_CMD="*/5 * * * * $PHP_BIN $SCRIPT_PATH/generate.php >> $SCRIPT_PATH/logs/cron.log 2>&1"

# Crear directorio de logs
mkdir -p "$SCRIPT_PATH/logs"

# Agregar a crontab (requiere interacción)
echo "Agregando a crontab:"
echo "$CRON_CMD"
echo ""
echo "C-c para cancelar, Enter para continuar..."
read

# Agregar a crontab (crear temporal para edición)
(crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -

echo "✓ Cron configurado!"
echo ""
echo "Para verificar:"
echo "  crontab -l"
echo ""
echo "Para ver logs:"
echo "  tail -f $SCRIPT_PATH/logs/cron.log"
