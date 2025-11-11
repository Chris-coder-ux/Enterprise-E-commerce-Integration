#!/bin/bash

# build-plugin-optimized.sh
# Script optimizado para compilar el plugin Mi Integración API

set -e

# Definición de variables
PLUGIN_SLUG="mi-integracion-api"
PLUGIN_DIR=$(pwd)
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
ZIP_FILE="${PLUGIN_SLUG}.zip"

echo "🚀 Iniciando compilación optimizada del plugin ${PLUGIN_SLUG}..."

# Verificar directorio correcto
if [ ! -f "${PLUGIN_DIR}/mi-integracion-api.php" ]; then
    echo "❌ Error: No se encuentra el archivo principal del plugin."
    exit 1
fi

# Limpiar build anterior
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "📂 Copiando solo archivos necesarios..."

# 1. Copiar archivos raíz esenciales
ESSENTIAL_ROOT_FILES=(
    "index.php"
    "mi-integracion-api.php"
    "verialconfig.php"
    "uninstall.php"
    "composer.json"
    ".env.example"
    "README.txt"
    "changelog.txt"
    "LICENSE"
    "LICENSE.txt"
)

for file in "${ESSENTIAL_ROOT_FILES[@]}"; do
    if [ -f "$PLUGIN_DIR/$file" ]; then
        cp "$PLUGIN_DIR/$file" "$BUILD_DIR/$file"
        echo "✅ $file"
    fi
done

# 2. Copiar directorios esenciales con filtro
echo "📂 Copiando directorios esenciales..."

# Función para copiar directorio con exclusión
copy_directory_filtered() {
    local src_dir="$1"
    local dest_dir="$2"
    local dir_name="$3"
    
    if [ -d "$src_dir" ]; then
        mkdir -p "$dest_dir"
        
        # Copiar todo primero
        cp -r "$src_dir"/* "$dest_dir/" 2>/dev/null || true
        
        # Eliminar archivos de desarrollo específicos
        find "$dest_dir" -name '*.bak' -delete 2>/dev/null || true
        find "$dest_dir" -name '*.backup' -delete 2>/dev/null || true
        find "$dest_dir" -name '*.old' -delete 2>/dev/null || true
        find "$dest_dir" -name '*~' -delete 2>/dev/null || true
        find "$dest_dir" -name '.DS_Store' -delete 2>/dev/null || true
        find "$dest_dir" -name 'Thumbs.db' -delete 2>/dev/null || true
        
        echo "✅ $dir_name (${dest_dir#$BUILD_DIR/})"
    else
        echo "⚠️  $dir_name no encontrado"
    fi
}

# Copiar directorios esenciales
copy_directory_filtered "$PLUGIN_DIR/assets" "$BUILD_DIR/assets" "Assets"
copy_directory_filtered "$PLUGIN_DIR/includes" "$BUILD_DIR/includes" "Includes"
copy_directory_filtered "$PLUGIN_DIR/languages" "$BUILD_DIR/languages" "Languages"
copy_directory_filtered "$PLUGIN_DIR/templates" "$BUILD_DIR/templates" "Templates"

# Verificar y copiar estructura modular del dashboard refactorizado
if [ -d "$PLUGIN_DIR/assets/js/dashboard" ]; then
    echo "📂 Verificando estructura modular del dashboard..."
    
    # Verificar subdirectorios del dashboard
    DASHBOARD_SUBDIRS=(
        "config"
        "core"
        "managers"
        "components"
        "sync"
        "utils"
        "ui"
        "controllers"
    )
    
    for subdir in "${DASHBOARD_SUBDIRS[@]}"; do
        if [ -d "$PLUGIN_DIR/assets/js/dashboard/$subdir" ]; then
            echo "  ✅ dashboard/$subdir/"
        else
            echo "  ⚠️  dashboard/$subdir/ no encontrado"
        fi
    done
    
    # Verificar archivos principales del dashboard
    DASHBOARD_FILES=(
        "dashboard.js"
        "REFACTORING_PLAN.md"
        "README.md"
    )
    
    for file in "${DASHBOARD_FILES[@]}"; do
        if [ -f "$PLUGIN_DIR/assets/js/dashboard/$file" ]; then
            echo "  ✅ dashboard/$file"
        fi
    done
fi

# 3. Copiar documentación esencial (sin archivos MD)
if [ -d "$PLUGIN_DIR/docs" ]; then
    echo "📚 Copiando documentación esencial (excluyendo .md)..."
    mkdir -p "$BUILD_DIR/docs"
    
    # Copiar solo manual de usuario completo (HTML)
    if [ -d "$PLUGIN_DIR/docs/manual-usuario" ]; then
        cp -r "$PLUGIN_DIR/docs/manual-usuario" "$BUILD_DIR/docs/"
        echo "📚 Manual de usuario completo"
    fi
    
    # Copiar otros archivos de documentación (excepto .md)
    find "$PLUGIN_DIR/docs" -type f ! -name "*.md" ! -path "*/manual-usuario/*" | while read doc; do
        rel_path="${doc#$PLUGIN_DIR/docs/}"
        mkdir -p "$(dirname "$BUILD_DIR/docs/$rel_path")"
        cp "$doc" "$BUILD_DIR/docs/$rel_path"
        echo "📄 $rel_path"
    done
fi

# 4. Copiar certificados si existen
if [ -d "$PLUGIN_DIR/certs" ]; then
    mkdir -p "$BUILD_DIR/certs"
    cp -r "$PLUGIN_DIR/certs"/* "$BUILD_DIR/certs/" 2>/dev/null || true
    echo "🔒 Certificados SSL"
fi

# 5. Copiar librerías si existen
if [ -d "$PLUGIN_DIR/lib" ]; then
    mkdir -p "$BUILD_DIR/lib"
    cp -r "$PLUGIN_DIR/lib"/* "$BUILD_DIR/lib/" 2>/dev/null || true
    echo "📦 Librerías externas"
fi

# 6. Crear directorio de logs
mkdir -p "$BUILD_DIR/logs"
chmod 755 "$BUILD_DIR/logs"
echo "📂 Directorio de logs"

# 7. Optimizar vendor si existe composer.json
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo "📦 Optimizando dependencias..."
    cd "$BUILD_DIR"
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --classmap-authoritative --quiet 2>/dev/null || echo "⚠️  Composer no disponible"
        # Limpiar vendor
        if [ -d "vendor" ]; then
            find "vendor" -name "*.md" -delete 2>/dev/null || true
            find "vendor" -name "*.txt" -not -name "LICENSE*" -delete 2>/dev/null || true
            find "vendor" -name "test*" -type d -exec rm -rf {} \; 2>/dev/null || true
            find "vendor" -name "doc*" -type d -exec rm -rf {} \; 2>/dev/null || true
        fi
    fi
    cd - > /dev/null
fi

# 8. Eliminar archivos de desarrollo restantes
echo "🧹 Limpieza final..."
find "$BUILD_DIR" -name '.git' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.vscode' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.idea' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'node_modules' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '*.md' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.test.js' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.spec.js' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'tests' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '__tests__' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.jest' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'jest.config.js' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'package.json' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'package-lock.json' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'yarn.lock' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.eslintrc*' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.prettierrc*' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.gitignore' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.editorconfig' -type f -delete 2>/dev/null || true

# 9. Crear ZIP
echo "🔒 Creando archivo ZIP..."
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" ./* > /dev/null 2>&1 || { echo "❌ Error creando ZIP"; exit 1; }

# 10. Mover a ubicaciones finales
mv "$ZIP_FILE" "$PLUGIN_DIR/$ZIP_FILE"
cp "$PLUGIN_DIR/$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE" 2>/dev/null || echo "⚠️  No se pudo copiar al escritorio"

cd - > /dev/null

# 11. Limpiar y mostrar resultados
ZIP_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)
rm -rf "$BUILD_DIR"

echo ""
echo "✅ PLUGIN COMPILADO EXITOSAMENTE"
echo "================================"
echo "📁 Archivo: $PLUGIN_DIR/$ZIP_FILE"
echo "📊 Tamaño: $ZIP_SIZE"
echo "⏰ Fecha: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
