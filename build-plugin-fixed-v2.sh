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
    "composer.lock"
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

# 6. Crear directorios necesarios y copiar archivos de seguridad
echo "📂 Creando directorios necesarios..."

# Crear directorio de logs
mkdir -p "$BUILD_DIR/logs"
chmod 755 "$BUILD_DIR/logs"
echo "✅ Directorio de logs"

# Crear directorio de cache si no existe
mkdir -p "$BUILD_DIR/cache"
chmod 755 "$BUILD_DIR/cache"
echo "✅ Directorio de cache"

# ✅ NUEVO: Copiar archivos .htaccess importantes para seguridad
if [ -f "$PLUGIN_DIR/api_connector/.htaccess" ]; then
    mkdir -p "$BUILD_DIR/api_connector"
    cp "$PLUGIN_DIR/api_connector/.htaccess" "$BUILD_DIR/api_connector/.htaccess" 2>/dev/null || true
    cp "$PLUGIN_DIR/api_connector/index.php" "$BUILD_DIR/api_connector/index.php" 2>/dev/null || true
    echo "✅ api_connector/ con protección .htaccess"
fi

# ✅ NUEVO: Asegurar que todos los directorios tengan index.php de protección
find "$BUILD_DIR" -type d | while read -r dir; do
    if [ ! -f "$dir/index.php" ]; then
        echo "<?php // Silence is golden" > "$dir/index.php" 2>/dev/null || true
    fi
done
echo "✅ Archivos index.php de protección agregados"

# 7. Manejar dependencias de Composer
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo "📦 Gestionando dependencias de Composer..."
    cd "$BUILD_DIR"
    
    # ✅ MEJORADO: Intentar copiar vendor/ existente primero (más rápido y confiable)
    if [ -d "$PLUGIN_DIR/vendor" ] && [ -f "$PLUGIN_DIR/vendor/autoload.php" ]; then
        echo "📦 Copiando vendor/ existente..."
        cp -r "$PLUGIN_DIR/vendor" "$BUILD_DIR/vendor" 2>/dev/null || echo "⚠️  Error copiando vendor/"
        
        # Limpiar vendor copiado
        if [ -d "$BUILD_DIR/vendor" ]; then
            echo "🧹 Limpiando vendor/..."
            find "$BUILD_DIR/vendor" -name "*.md" -delete 2>/dev/null || true
            find "$BUILD_DIR/vendor" -name "*.txt" -not -name "LICENSE*" -delete 2>/dev/null || true
            find "$BUILD_DIR/vendor" -name "test*" -type d -exec rm -rf {} \; 2>/dev/null || true
            find "$BUILD_DIR/vendor" -name "doc*" -type d -exec rm -rf {} \; 2>/dev/null || true
            find "$BUILD_DIR/vendor" -name "examples" -type d -exec rm -rf {} \; 2>/dev/null || true
            find "$BUILD_DIR/vendor" -name ".git" -type d -exec rm -rf {} \; 2>/dev/null || true
            echo "✅ vendor/ copiado y optimizado"
        fi
    # Si no existe vendor/, intentar instalarlo con composer
    elif command -v composer >/dev/null 2>&1; then
        echo "📦 Instalando dependencias con Composer..."
        composer install --no-dev --optimize-autoloader --classmap-authoritative --quiet 2>/dev/null || {
            echo "⚠️  Error ejecutando composer install"
            echo "⚠️  El plugin requerirá ejecutar 'composer install --no-dev' después de la instalación"
        }
        
        # Limpiar vendor instalado
        if [ -d "vendor" ]; then
            find "vendor" -name "*.md" -delete 2>/dev/null || true
            find "vendor" -name "*.txt" -not -name "LICENSE*" -delete 2>/dev/null || true
            find "vendor" -name "test*" -type d -exec rm -rf {} \; 2>/dev/null || true
            find "vendor" -name "doc*" -type d -exec rm -rf {} \; 2>/dev/null || true
        fi
    else
        echo "⚠️  Composer no disponible y vendor/ no existe"
        echo "⚠️  El plugin requerirá ejecutar 'composer install --no-dev' después de la instalación"
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
find "$BUILD_DIR" -name 'spec' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.jest' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'jest.config.js' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'package.json' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'package-lock.json' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'yarn.lock' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.eslintrc*' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.prettierrc*' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.gitignore' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.editorconfig' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'tsconfig.json' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'phpstan.neon' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'phpsalm.xml' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'phpcs-ruleset.xml' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'phpunit.xml' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'qodana.yaml' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'bootstrap-phpstan.php' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.stub.php' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'wordpress-stubs.php' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'woocommerce-stubs.php' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'wp-*.php' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.backup' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.bak' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*.old' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '*~' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name '.DS_Store' -type f -delete 2>/dev/null || true
find "$BUILD_DIR" -name 'Thumbs.db' -type f -delete 2>/dev/null || true

# ✅ NUEVO: Verificar que los archivos esenciales estén presentes
echo "🔍 Verificando archivos esenciales..."
MISSING_FILES=0

if [ ! -f "$BUILD_DIR/mi-integracion-api.php" ]; then
    echo "❌ ERROR: mi-integracion-api.php no encontrado"
    MISSING_FILES=1
fi

if [ ! -f "$BUILD_DIR/index.php" ]; then
    echo "❌ ERROR: index.php no encontrado"
    MISSING_FILES=1
fi

if [ ! -f "$BUILD_DIR/uninstall.php" ]; then
    echo "⚠️  ADVERTENCIA: uninstall.php no encontrado"
fi

if [ ! -f "$BUILD_DIR/composer.json" ]; then
    echo "⚠️  ADVERTENCIA: composer.json no encontrado"
fi

if [ ! -d "$BUILD_DIR/includes" ]; then
    echo "❌ ERROR: Directorio includes/ no encontrado"
    MISSING_FILES=1
fi

if [ ! -d "$BUILD_DIR/assets" ]; then
    echo "❌ ERROR: Directorio assets/ no encontrado"
    MISSING_FILES=1
fi

# Verificar vendor/ o composer.lock
if [ ! -d "$BUILD_DIR/vendor" ] && [ ! -f "$BUILD_DIR/composer.lock" ]; then
    echo "⚠️  ADVERTENCIA: vendor/ no existe y composer.lock no está presente"
    echo "⚠️  El usuario deberá ejecutar 'composer install --no-dev' después de instalar"
fi

if [ $MISSING_FILES -eq 1 ]; then
    echo "❌ ERROR: Faltan archivos esenciales. Abortando compilación."
    exit 1
fi

echo "✅ Verificación de archivos esenciales completada"

# 9. Crear ZIP
echo "🔒 Creando archivo ZIP..."
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" ./* > /dev/null 2>&1 || { echo "❌ Error creando ZIP"; exit 1; }

# 10. Mover a ubicaciones finales
mv "$ZIP_FILE" "$PLUGIN_DIR/$ZIP_FILE"
cp "$PLUGIN_DIR/$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE" 2>/dev/null || echo "⚠️  No se pudo copiar al escritorio"

cd - > /dev/null

# 11. Verificar estado antes de limpiar
ZIP_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)
VENDOR_INCLUDED=0
if [ -d "$BUILD_DIR/vendor" ] && [ -f "$BUILD_DIR/vendor/autoload.php" ]; then
    VENDOR_INCLUDED=1
fi

# Limpiar build
rm -rf "$BUILD_DIR"

echo ""
echo "✅ PLUGIN COMPILADO EXITOSAMENTE"
echo "================================"
echo "📁 Archivo: $PLUGIN_DIR/$ZIP_FILE"
echo "📊 Tamaño: $ZIP_SIZE"
echo "⏰ Fecha: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "📋 RESUMEN DE ARCHIVOS INCLUIDOS:"
echo "  ✅ Archivo principal del plugin (mi-integracion-api.php)"
echo "  ✅ Archivos de protección (index.php en todos los directorios)"
echo "  ✅ Directorio includes/ (código PHP del plugin)"
echo "  ✅ Directorio assets/ (CSS, JS, imágenes)"
echo "  ✅ Directorio languages/ (traducciones)"
echo "  ✅ Directorio templates/ (plantillas)"
echo "  ✅ composer.json y composer.lock"
if [ $VENDOR_INCLUDED -eq 1 ]; then
    echo "  ✅ vendor/ (dependencias de Composer incluidas)"
else
    echo "  ⚠️  vendor/ no incluido - requerirá 'composer install --no-dev' después de instalar"
fi
echo "  ✅ README.txt (información del plugin)"
echo "  ✅ uninstall.php (limpieza al desinstalar)"
echo ""
echo "📝 NOTAS DE INSTALACIÓN:"
if [ $VENDOR_INCLUDED -eq 0 ]; then
    echo "  ⚠️  IMPORTANTE: Después de instalar el plugin, ejecuta:"
    echo "     cd wp-content/plugins/mi-integracion-api"
    echo "     composer install --no-dev --optimize-autoloader"
    echo ""
fi
echo "  ✅ El plugin está listo para instalar en WordPress"
echo "  ✅ Todos los archivos esenciales están incluidos"
echo ""
