#!/bin/bash

# Script para ejecutar todos los tests de la arquitectura en dos fases
# Uso: ./run-all-tests.sh [test_name]
# Si no se especifica test_name, ejecuta todos los tests

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Directorio base
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}🧪 Ejecutando Tests - Arquitectura en Dos Fases${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""

# Función para ejecutar un test
run_test() {
    local test_file=$1
    local test_name=$2
    
    echo -e "${YELLOW}📋 Ejecutando: $test_name${NC}"
    echo "─────────────────────────────────────"
    
    if [ ! -f "$test_file" ]; then
        echo -e "${RED}❌ Archivo de test no encontrado: $test_file${NC}"
        return 1
    fi
    
    # Ejecutar test
    cd "$PROJECT_DIR"
    php "$test_file" 2>&1
    
    local exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✅ Test completado exitosamente: $test_name${NC}"
        echo ""
        return 0
    else
        echo -e "${RED}❌ Test falló: $test_name (código de salida: $exit_code)${NC}"
        echo ""
        return 1
    fi
}

# Tests disponibles
declare -A TESTS=(
    ["cache_improvements"]="tests/CacheImprovementsTest.php"
    ["image_sync_manager"]="tests/ImageSyncManagerTest.php"
    ["cache_getnumarticulosws"]="tests/TestCacheGetNumArticulosWS.php"
    ["two_phase_integration"]="tests/TwoPhaseIntegrationTest.php"
    ["rollback"]="tests/RollbackTest.php"
)

# Si se especifica un test específico
if [ $# -gt 0 ]; then
    test_key=$1
    if [ -z "${TESTS[$test_key]}" ]; then
        echo -e "${RED}❌ Test no encontrado: $test_key${NC}"
        echo ""
        echo "Tests disponibles:"
        for key in "${!TESTS[@]}"; do
            echo "  - $key"
        done
        exit 1
    fi
    
    test_file="${TESTS[$test_key]}"
    run_test "$test_file" "$test_key"
    exit $?
fi

# Ejecutar todos los tests
echo -e "${BLUE}Ejecutando todos los tests...${NC}"
echo ""

total_tests=0
passed_tests=0
failed_tests=0

for test_key in "${!TESTS[@]}"; do
    test_file="${TESTS[$test_key]}"
    total_tests=$((total_tests + 1))
    
    if run_test "$test_file" "$test_key"; then
        passed_tests=$((passed_tests + 1))
    else
        failed_tests=$((failed_tests + 1))
    fi
done

# Resumen final
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}📊 RESUMEN FINAL${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "Total de tests: ${BLUE}$total_tests${NC}"
echo -e "✅ Pasados: ${GREEN}$passed_tests${NC}"
echo -e "❌ Fallidos: ${RED}$failed_tests${NC}"
echo ""

if [ $failed_tests -eq 0 ]; then
    echo -e "${GREEN}🎉 Todos los tests pasaron exitosamente!${NC}"
    exit 0
else
    echo -e "${RED}⚠️  Algunos tests fallaron${NC}"
    exit 1
fi

