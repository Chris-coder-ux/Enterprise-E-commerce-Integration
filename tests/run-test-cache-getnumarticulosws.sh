#!/bin/bash

# Script para ejecutar el test de caché de GetNumArticulosWS
# 
# Uso:
#   ./run-test-cache-getnumarticulosws.sh
#
# Requisitos:
#   - WordPress debe estar instalado
#   - Plugin debe estar activo
#   - PHP debe estar disponible

echo "═══════════════════════════════════════════════════════════"
echo "🧪 TEST FUNCIONAL: Caché para GetNumArticulosWS"
echo "═══════════════════════════════════════════════════════════"
echo ""

# Verificar que WordPress está disponible
if [ ! -f "../../../wp-load.php" ] && [ ! -f "../../../../wp-load.php" ]; then
    echo "⚠️  ADVERTENCIA: No se encontró wp-load.php"
    echo "   El test se ejecutará en modo standalone"
    echo ""
fi

# Ejecutar test
php -f tests/TestCacheGetNumArticulosWS.php

# Verificar código de salida
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ TODOS LOS TESTS PASARON"
    exit 0
else
    echo ""
    echo "❌ ALGUNOS TESTS FALLARON"
    exit 1
fi

