#!/bin/bash
# build-plugin-fixed.sh
# Script corregido para compilar el plugin Mi Integración API en un archivo ZIP con la estructura correcta

set -e

# Definición de variables
PLUGIN_SLUG="mi-integracion-api"
PLUGIN_DIR=$(pwd)
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
ZIP_FILE="${PLUGIN_SLUG}.zip"

# Verificar que estamos en el directorio correcto
if [ ! -f "${PLUGIN_DIR}/mi-integracion-api.php" ]; then
    echo "❌ Error: No se encuentra el archivo principal del plugin. Asegúrate de ejecutar este script desde el directorio raíz del plugin."
    exit 1
fi

# Ejecutar verificación pre-compilación
echo "🔍 Ejecutando verificación pre-compilación..."

# Ejecutar verificación del sistema unificado primero
if [ -f "${PLUGIN_DIR}/tools/verify-unified-system.sh" ]; then
    echo "✅ Verificando sistema unificado de configuración..."
    bash "${PLUGIN_DIR}/tools/verify-unified-system.sh"
    if [ $? -ne 0 ]; then
        echo "❌ Error: El sistema unificado tiene problemas. Por favor, revisa la implementación antes de compilar."
        exit 1
    fi
    echo "✅ Sistema unificado verificado correctamente."
fi

# Ejecutar verificación general si existe
if [ -f "${PLUGIN_DIR}/check-before-build.sh" ]; then
    echo "✅ Ejecutando script de verificación completo..."
    bash "${PLUGIN_DIR}/check-before-build.sh"
    if [ $? -ne 0 ]; then
        echo "❌ Error: La verificación pre-compilación ha encontrado errores. Por favor, resuelve los problemas antes de compilar."
        exit 1
    fi
    echo "✅ Verificación pre-compilación completada con éxito."
else
    echo "⚠️ Script de verificación no encontrado. Ejecutando verificaciones básicas..."
    
    # Verificación básica de archivos críticos del sistema unificado (ACTUALIZADO 2024)
    CRITICAL_FILES=(
        # Core - Clases principales del sistema
        "includes/Core/ApiConnector.php"
        "includes/Core/Sync_Manager.php"
        "includes/Core/REST_API_Handler.php"
        "includes/Core/ConfigManager.php"
        "includes/Core/MiIntegracionApi.php"
        "includes/Core/LogManager.php"
        "includes/Core/BatchProcessor.php"
        "includes/Core/Installer.php"
        "includes/Core/SyncLock.php"
        "includes/Core/SyncTransientsMigrator.php"
        
        # Autoloaders - Sistema de carga de clases
        "includes/Core/AutoloaderManager.php"
        "includes/Core/ComposerAutoloader.php"
        "includes/Core/SmartAutoloader.php"
        "includes/Core/EmergencyLoader.php"
        
        # SSL - Sistema de seguridad SSL
        "includes/Core/SSLAdvancedSystemsTrait.php"
        
        # Sync - Sistema de sincronización
        "includes/Sync/SyncClientes.php"
        "includes/Sync/SyncPedidos.php"
        "includes/Sync/WooCommerceLoader.php"
        
        # Deteccion - Sistema de detección automática
        "includes/Deteccion/StockDetector.php"
        "includes/Deteccion/StockDetectorIntegration.php"
        "includes/Deteccion/WooCommerceProductNotifier.php"
        "includes/Deteccion/init.php"
        
        # Cache - Sistema de caché
        "includes/Cache/PriceCache.php"
        "includes/Cache/HTTP_Cache_Manager.php"
        "includes/Cache/Cache_Admin_Panel.php"
        "includes/CacheManager.php"
        
        # WooCommerce - Integración con WooCommerce
        "includes/Helpers/MapProduct.php"
        "includes/WooCommerce/SyncHelper.php"
        "includes/WooCommerce/WooCommerceHooks.php"
        
        # Hooks - Sistema de hooks de WordPress
        "includes/Hooks/SyncHooks.php"
        
        # Helpers - Utilidades y helpers
        "includes/Helpers/MapProduct.php"
        "includes/Helpers/BatchSizeHelper.php"
        "includes/Helpers/Logger.php"
        "includes/Helpers/TransientCompatibility.php"
        "includes/Helpers/ILogger.php"
        "includes/Helpers/IndexHelper.php"
        "includes/Helpers/IdGenerator.php"
        "includes/Helpers/Utils.php"
        "includes/Helpers/WooCommerceHelper.php"
        "includes/Helpers/VerificationPerformanceTracker.php"
        "includes/Helpers/BatchSizeDebug.php"
        "includes/Helpers/Crypto.php"
        "includes/Helpers/DataSanitizer.php"
        "includes/Helpers/DbLogs.php"
        "includes/Helpers/FilterCustomers.php"
        "includes/Helpers/FilterOrders.php"
        "includes/Helpers/FilterProducts.php"
        "includes/Helpers/Formatting.php"
        "includes/Helpers/HposCompatibility.php"
        
        #Helper
        "includes/Helper/ParallelApiCaller.php"
        
        # Functions - Funciones globales
        "includes/functions.php"
        "includes/functions_safe.php"
        
        # Admin - Panel de administración
        "includes/Admin/Assets.php"
        "includes/Admin/DetectionDashboard.php"
        "includes/Admin/OrderSyncDashboard.php"
        "includes/Admin/NotificationConfig.php"
        "includes/Assets.php"
        
        # Endpoints - API REST
        "includes/Endpoints/Base.php"
        "includes/Endpoints/GetArticulosWS.php"
        
        # Archivos de configuración
        "verialconfig.php"
        "mi-integracion-api.php"
        "composer.json"
        
        # Archivos de compatibilidad y utilidades
        "includes/compatibility.php"
        "includes/alias.php"
        "includes/index.php"
        
        # Assets - Recursos estáticos
        "assets/css/admin.css"
        "assets/css/admin-dashboard.css"
        "assets/js/dashboard/dashboard.js"
        "assets/js/admin-dashboard.js"
    )

    for file in "${CRITICAL_FILES[@]}"; do
        if [ ! -f "${PLUGIN_DIR}/$file" ]; then
            echo "⚠️ Advertencia: No se encuentra el archivo crítico $file"
        else
            # Verificar sintaxis según el tipo de archivo
            if [[ "$file" == *.php ]]; then
                php -l "${PLUGIN_DIR}/$file" > /dev/null 2>&1
                if [ $? -eq 0 ]; then
                    echo "✅ $file - Sintaxis PHP OK"
                else
                    echo "❌ $file - Error de sintaxis PHP"
                    exit 1
                fi
            elif [[ "$file" == *.js ]]; then
                # Verificar sintaxis JavaScript con Node.js si está disponible
                if command -v node > /dev/null 2>&1; then
                    node -c "${PLUGIN_DIR}/$file" > /dev/null 2>&1
                    if [ $? -eq 0 ]; then
                        echo "✅ $file - Sintaxis JavaScript OK"
                    else
                        echo "❌ $file - Error de sintaxis JavaScript"
                        exit 1
                    fi
                else
                    echo "✅ $file - Archivo encontrado (Node.js no disponible para verificar sintaxis)"
                fi
            else
                echo "✅ $file - Archivo encontrado"
            fi
        fi
    done
fi

# Verificar si existen los templates
if [ ! -d "${PLUGIN_DIR}/templates" ]; then
    echo "⚠️ Advertencia: No se encuentra el directorio de templates. Los estilos podrían no aplicarse correctamente."
elif [ ! -f "${PLUGIN_DIR}/templates/admin/header.php" ] || [ ! -f "${PLUGIN_DIR}/templates/admin/footer.php" ]; then
    echo "⚠️ Advertencia: Faltan templates de cabecera o pie. Los estilos podrían no aplicarse correctamente."
fi

# Verificar templates del dashboard de detección automática
if [ ! -f "${PLUGIN_DIR}/templates/admin/detection-dashboard.php" ]; then
    echo "⚠️ Advertencia: No se encuentra el template del dashboard de detección automática."
fi

# Verificar archivos del sistema de detección automática
echo "🔍 Verificando sistema de detección automática..."
DETECTION_FILES=(
    "includes/Deteccion/StockDetector.php"
    "includes/Deteccion/StockDetectorIntegration.php"
    "includes/Deteccion/WooCommerceProductNotifier.php"
    "includes/Deteccion/init.php"
    "includes/Admin/DetectionDashboard.php"
    "includes/Admin/NotificationConfig.php"
    "includes/Hooks/SyncHooks.php"
    "templates/admin/detection-dashboard.php"
    "assets/css/admin-dashboard.css"
    "assets/js/admin-dashboard.js"
)

for file in "${DETECTION_FILES[@]}"; do
    if [ ! -f "${PLUGIN_DIR}/$file" ]; then
        echo "❌ Error: Archivo crítico del sistema de detección no encontrado: $file"
        exit 1
    else
        # Verificar sintaxis PHP
        if [[ "$file" == *.php ]]; then
            php -l "${PLUGIN_DIR}/$file" > /dev/null 2>&1
            if [ $? -eq 0 ]; then
                echo "✅ $file - Sintaxis PHP OK"
            else
                echo "❌ $file - Error de sintaxis PHP"
                exit 1
            fi
        # Verificar sintaxis CSS
        elif [[ "$file" == *.css ]]; then
            if command -v csslint > /dev/null 2>&1; then
                csslint "${PLUGIN_DIR}/$file" > /dev/null 2>&1
                if [ $? -eq 0 ]; then
                    echo "✅ $file - Sintaxis CSS OK"
                else
                    echo "⚠️ $file - Advertencias de sintaxis CSS (no crítico)"
                fi
            else
                echo "✅ $file - Archivo encontrado (csslint no disponible)"
            fi
        # Verificar sintaxis JavaScript
        elif [[ "$file" == *.js ]]; then
            if command -v node > /dev/null 2>&1; then
                node -c "${PLUGIN_DIR}/$file" > /dev/null 2>&1
                if [ $? -eq 0 ]; then
                    echo "✅ $file - Sintaxis JavaScript OK"
                else
                    echo "❌ $file - Error de sintaxis JavaScript"
                    exit 1
                fi
            else
                echo "✅ $file - Archivo encontrado (Node.js no disponible)"
            fi
        else
            echo "✅ $file - Archivo encontrado"
        fi
    fi
done
echo "✅ Sistema de detección automática verificado correctamente."

# Ejecutar verificador de selectores CSS si existe
if [ -f "${PLUGIN_DIR}/css-selector-check.sh" ]; then
    echo "🔍 Verificando coherencia de selectores CSS..."
    bash "${PLUGIN_DIR}/css-selector-check.sh" > "${PLUGIN_DIR}/css-selector-check.log"
    echo "✅ Verificación de selectores completada. Resultados guardados en css-selector-check.log"
fi

echo "🔍 Iniciando compilación del plugin ${PLUGIN_SLUG}..."

# Verificar si ya existe un archivo ZIP y obtener su tamaño para comparación
if [ -f "$PLUGIN_DIR/$ZIP_FILE" ]; then
    ORIGINAL_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)
    echo "📊 Tamaño del plugin actual: $ORIGINAL_SIZE"
fi

# Limpiar build anterior si existe
if [ -d "$BUILD_DIR" ]; then
    echo "🗑️  Eliminando directorio de build anterior..."
    rm -rf "$BUILD_DIR"
fi
mkdir -p "$BUILD_DIR"

echo "📂 Copiando archivos al directorio de build..."

# Copiar archivos y carpetas necesarios directamente al BUILD_DIR (sin crear subcarpeta)
cp index.php "$BUILD_DIR/"
cp mi-integracion-api.php "$BUILD_DIR/"
cp verialconfig.php "$BUILD_DIR/"
cp uninstall.php "$BUILD_DIR/" || true
cp composer.json "$BUILD_DIR/" || true
cp .env.example "$BUILD_DIR/" || echo "⚠️ .env.example no encontrado"
cp -r admin "$BUILD_DIR/"
cp -r api_connector "$BUILD_DIR/"
cp -r assets "$BUILD_DIR/"
cp -r certs "$BUILD_DIR/" || echo "⚠️ Directorio de certificados no encontrado"
cp -r includes "$BUILD_DIR/"
cp -r languages "$BUILD_DIR/"
cp -r lib "$BUILD_DIR/" || echo "⚠️ Directorio lib no encontrado"

# Crear directorio de logs con permisos adecuados
mkdir -p "$BUILD_DIR/logs"
chmod 755 "$BUILD_DIR/logs"
echo "📂 Creado directorio de logs con permisos adecuados"

# Copiar archivos opcionales importantes si existen
echo "📄 Copiando archivos opcionales importantes..."
[ -f "README.txt" ] && cp README.txt "$BUILD_DIR/" && echo "✅ README.txt copiado"
[ -f "changelog.txt" ] && cp changelog.txt "$BUILD_DIR/" && echo "✅ changelog.txt copiado"
[ -f "LICENSE" ] && cp LICENSE "$BUILD_DIR/" && echo "✅ LICENSE copiado"
[ -f "LICENSE.txt" ] && cp LICENSE.txt "$BUILD_DIR/" && echo "✅ LICENSE.txt copiado"

# Copiar directorio de scripts de utilidades si existe
if [ -d "scripts" ]; then
    echo "📂 Copiando scripts de utilidades..."
    cp -r scripts "$BUILD_DIR/"
    echo "✅ Scripts de autoloader incluidos (optimize-autoloader.sh)"
fi

# Excluir archivos de diagnóstico/debug
echo "🔍 Excluyendo archivos de diagnóstico y desarrollo..."
if [ -f "debug-assets.php" ]; then
    echo "⚠️ debug-assets.php no se incluirá en el ZIP (archivo de diagnóstico)"
fi

# Copiar templates si existe
if [ -d "templates" ]; then
    echo "📂 Copiando templates para cabeceras y pies de página..."
    cp -r templates "$BUILD_DIR/"
else
    echo "⚠️ Directorio 'templates' no encontrado. Los templates de administración podrían faltar."
fi

# Copiar README.txt si existe (necesario para información del plugin en WordPress)
if [ -f "README.txt" ]; then
    cp README.txt "$BUILD_DIR/"
fi

# Eliminar archivos y carpetas innecesarios del build
echo "🧹 Limpiando archivos innecesarios..."

# Mantener docs y eliminar archivos no necesarios
if [ -d "$BUILD_DIR/docs" ]; then
    # Conservar documentación esencial para el usuario final
    echo "📚 Conservando documentación esencial para el usuario..."
    
    # Lista de documentación importante para el usuario final
    USER_DOCS=(
        "manual-usuario.md"
        "guia-instalacion.md"
        "guia-resolucion-problemas.md"
        "GUIA-TESTING-HOSTING.md"
        "sistema-unificado-configuracion.md"
        "AUTOLOADER_ARCHITECTURE.md"
    )
    
    # Crear directorio temporal para docs importantes
    mkdir -p "/tmp/docs-importantes"
    
    # Copiar documentación importante
    for doc in "${USER_DOCS[@]}"; do
        if [ -f "$BUILD_DIR/docs/$doc" ]; then
            cp "$BUILD_DIR/docs/$doc" "/tmp/docs-importantes/"
            echo "✅ Conservando: $doc"
        fi
    done
    
    # Eliminar todo el directorio docs
    rm -rf "$BUILD_DIR/docs"
    
    # Recrear directorio docs solo con documentación importante
    mkdir -p "$BUILD_DIR/docs"
    
    # Restaurar documentación importante
    if [ -d "/tmp/docs-importantes" ] && [ "$(ls -A /tmp/docs-importantes)" ]; then
        cp /tmp/docs-importantes/* "$BUILD_DIR/docs/"
        echo "✅ Documentación esencial restaurada"
    fi
    
    # Limpiar temporal
    rm -rf "/tmp/docs-importantes"
fi

# Guardar el resultado de la verificación de selectores en el directorio docs
if [ -f "${PLUGIN_DIR}/css-selector-check.log" ]; then
    echo "📝 Guardando informe de verificación de selectores en docs..."
    mkdir -p "$BUILD_DIR/docs"
    cp "${PLUGIN_DIR}/css-selector-check.log" "$BUILD_DIR/docs/verificacion-selectores.log"
fi

# Eliminar directorios y archivos de desarrollo
echo "🗑️ Eliminando archivos de desarrollo y testing..."

# ✅ ARCHIVOS DE BACKUP Y TEMPORALES
echo "🗑️ Eliminando archivos de backup y temporales..."
find "$BUILD_DIR" -name '*.bak' -delete
find "$BUILD_DIR" -name '*.backup' -delete
find "$BUILD_DIR" -name '*.old' -delete
find "$BUILD_DIR" -name '*.orig' -delete
find "$BUILD_DIR" -name '*.legacy' -delete
find "$BUILD_DIR" -name '*.copy' -delete
find "$BUILD_DIR" -name '*~' -delete
find "$BUILD_DIR" -name '#*#' -delete
find "$BUILD_DIR" -name '.#*' -delete
find "$BUILD_DIR" -name '*.tmp' -delete
find "$BUILD_DIR" -name '*.temp' -delete
find "$BUILD_DIR" -name '*.swp' -delete
find "$BUILD_DIR" -name '*.swo' -delete

# ✅ ARCHIVOS DE SISTEMA Y EDITORES
echo "🗑️ Eliminando archivos de sistema..."
find "$BUILD_DIR" -name '.DS_Store' -delete
find "$BUILD_DIR" -name 'Thumbs.db' -delete
find "$BUILD_DIR" -name 'desktop.ini' -delete
find "$BUILD_DIR" -name '__MACOSX' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.vscode' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.idea' -type d -exec rm -rf {} \; 2>/dev/null || true

# ✅ DIRECTORIOS DE DESARROLLO
echo "🗑️ Eliminando directorios de desarrollo..."
find "$BUILD_DIR" -name 'Legacy' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'tests' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'coverage' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name 'node_modules' -type d -exec rm -rf {} \; 2>/dev/null || true
find "$BUILD_DIR" -name '.git' -type d -exec rm -rf {} \; 2>/dev/null || true

# ✅ ARCHIVOS DE SCRIPTS (mantener solo optimize-autoloader.sh)
echo "🗑️ Eliminando scripts excepto optimize-autoloader.sh..."
if [ -d "$BUILD_DIR/scripts" ]; then
    # Crear temporal para preserve optimize-autoloader.sh
    mkdir -p "/tmp/preserve-scripts"
    if [ -f "$BUILD_DIR/scripts/optimize-autoloader.sh" ]; then
        cp "$BUILD_DIR/scripts/optimize-autoloader.sh" "/tmp/preserve-scripts/"
    fi
    
    # Eliminar todos los scripts
    find "$BUILD_DIR" -name '*.sh' -delete
    
    # Restaurar optimize-autoloader.sh
    if [ -f "/tmp/preserve-scripts/optimize-autoloader.sh" ]; then
        mkdir -p "$BUILD_DIR/scripts"
        cp "/tmp/preserve-scripts/optimize-autoloader.sh" "$BUILD_DIR/scripts/"
    fi
    rm -rf "/tmp/preserve-scripts"
else
    # Si no hay directorio scripts, eliminar todos los .sh
    find "$BUILD_DIR" -name '*.sh' -delete
fi

# ✅ ARCHIVOS DE LOG (mantener solo verificacion-selectores.log)
echo "🗑️ Eliminando logs excepto verificacion-selectores.log..."
find "$BUILD_DIR" -name '*.log' -not -name 'verificacion-selectores.log' -delete

# ✅ ARCHIVOS ESPECÍFICOS DE TESTING Y ANÁLISIS
echo "🧹 Eliminando archivos específicos de testing y análisis..."
find "$BUILD_DIR" -name 'test-*.php' -delete
find "$BUILD_DIR" -name 'test_*.php' -delete
find "$BUILD_DIR" -name '*-test.php' -not -name 'hpos-test.php' -delete
find "$BUILD_DIR" -name '*_test.php' -delete
find "$BUILD_DIR" -name 'analisis-*.php' -delete
find "$BUILD_DIR" -name 'analisis_*.php' -delete
find "$BUILD_DIR" -name 'audit-*.php' -delete
find "$BUILD_DIR" -name 'diagnostico-*.php' -delete
find "$BUILD_DIR" -name 'diagnostico_*.php' -delete
find "$BUILD_DIR" -name 'monitor-*.php' -delete
find "$BUILD_DIR" -name 'script-*.php' -delete
find "$BUILD_DIR" -name 'ejemplo-*.php' -delete
find "$BUILD_DIR" -name 'simple-*.php' -delete
find "$BUILD_DIR" -name 'configurar-*.php' -delete
find "$BUILD_DIR" -name 'inventario-*.php' -delete
find "$BUILD_DIR" -name 'sincronizacion-*.php' -delete
find "$BUILD_DIR" -name 'verificar-*.php' -delete
find "$BUILD_DIR" -name 'verificar_*.php' -delete
find "$BUILD_DIR" -name 'limpiar-*.php' -delete
find "$BUILD_DIR" -name 'limpiar_*.php' -delete
find "$BUILD_DIR" -name 'cleanup-*.php' -delete
find "$BUILD_DIR" -name 'cleanup_*.php' -delete
find "$BUILD_DIR" -name 'debug-*.php' -delete
find "$BUILD_DIR" -name 'debug_*.php' -delete

# ✅ ARCHIVOS DE STUBS Y DESARROLLO
echo "🗑️ Eliminando archivos de stubs y desarrollo..."
find "$BUILD_DIR" -name '*stubs*.php' -delete
find "$BUILD_DIR" -name '*-stubs.php' -delete
find "$BUILD_DIR" -name 'wordpress-stubs.php' -delete
find "$BUILD_DIR" -name 'woocommerce-stubs.php' -delete

# ✅ ARCHIVOS DE CONFIGURACIÓN DE DESARROLLO
echo "🗑️ Eliminando archivos de configuración de desarrollo..."
find "$BUILD_DIR" -name '.env*' -not -name '.env.example' -delete
find "$BUILD_DIR" -name '.gitignore' -delete
find "$BUILD_DIR" -name '.gitattributes' -delete
find "$BUILD_DIR" -name 'composer.lock' -delete
find "$BUILD_DIR" -name 'package-lock.json' -delete
find "$BUILD_DIR" -name 'yarn.lock' -delete

# ✅ ARCHIVOS ESPECÍFICOS DE TESTING Y CLASES ELIMINADAS
echo "🧹 Eliminando archivos específicos de testing y clases eliminadas..."
find "$BUILD_DIR" -name 'ApiConnector_Test.php' -delete
find "$BUILD_DIR" -name 'ApiConnector_Adapter.php' -delete
find "$BUILD_DIR" -name 'Migration_Manager.php' -delete
find "$BUILD_DIR" -name 'BatchApiHelper.php' -delete
find "$BUILD_DIR" -name 'ApiCallOptimizer.php' -delete
find "$BUILD_DIR" -name '*_Improved_Test.php' -delete
find "$BUILD_DIR" -name '*Test.php' -delete
find "$BUILD_DIR" -name 'phpunit.xml' -delete
find "$BUILD_DIR" -name 'phpunit.xml.dist' -delete
find "$BUILD_DIR" -name 'phpstan.neon' -delete
find "$BUILD_DIR" -name 'phpcs.xml' -delete
find "$BUILD_DIR" -name 'jest.config.js' -delete
find "$BUILD_DIR" -name 'jest.setup.js' -delete

# ✅ ARCHIVOS DE TEXTO Y CONFIGURACIÓN NO NECESARIOS
echo "🗑️ Eliminando archivos de texto innecesarios..."
find "$BUILD_DIR" -name 'crontab-*.txt' -delete
find "$BUILD_DIR" -name '*.md' -not -path "*/docs/*" -delete
find "$BUILD_DIR" -name '*.txt' -not -name 'README.txt' -not -name 'changelog.txt' -not -name 'LICENSE.txt' -delete

# ✅ ARCHIVOS MARKDOWN ESPECÍFICOS DEL DESARROLLO
echo "🗑️ Eliminando documentación de desarrollo..."
DEVELOPMENT_DOCS=(
    "CHECKLIST*.md"
    "ANALISIS*.md" 
    "REPORTE*.md"
    "PLAN_*.md"
    "ESTADO_*.md"
    "FLUJO_*.md"
    "IMPLEMENTACION_*.md"
    "DOCUMENTACION_*.md"
    "INFORME_*.md"
    "PROBLEMAS_*.md"
    "AREAS_*.md"
    "COMPARATIVA_*.md"
    "RECOMENDACIONES*.md"
)

for pattern in "${DEVELOPMENT_DOCS[@]}"; do
    find "$BUILD_DIR" -name "$pattern" -delete 2>/dev/null || true
done

# ✅ ARCHIVOS ESPECÍFICOS DE TU PROYECTO
echo "🗑️ Eliminando archivos específicos del proyecto..."
SPECIFIC_FILES=(
    "analisis_*.sh"
    "aggressive-*.sh"
    "final-*.sh"
    "ultimate-*.sh"
    "run_tests.sh"
    "ejecutar_tests_reales.sh"
    "build-*.sh"
    "get-docker.sh"
    "test-*.sh"
    "verificar-*.sh"
    "*.postman_collection.json"
    "Web Service TEST.*"
    "Manual integración servicio Web Verial.*"
    "qodana.yaml"
    "phpcs-ruleset.xml"
    "phpsalm.xml"
    "bootstrap-phpstan.php"
    "patch_*.php"
    "remove_*.php"
    "estructura_*.txt"
    "DIAGRAMA_*.txt"
    "FLUJO_*.txt"
    "README_*.md"
    "README-*.md"
    "*copy.sh"
    "*copy.php"
    "verialconfig copy.php"
)

for file_pattern in "${SPECIFIC_FILES[@]}"; do
    find "$BUILD_DIR" -name "$file_pattern" -delete 2>/dev/null || true
done

# ✅ DIRECTORIOS ESPECÍFICOS NO NECESARIOS
echo "🗑️ Eliminando directorios específicos..."
EXCLUDE_DIRS=(
    "backups"
    "uploads"
    "test_data"
    "documentacion"
)

for dir in "${EXCLUDE_DIRS[@]}"; do
    if [ -d "$BUILD_DIR/$dir" ]; then
        rm -rf "$BUILD_DIR/$dir"
        echo "🗑️ Eliminado directorio: $dir"
    fi
done

# Eliminar herramientas de desarrollo pero conservar documentación esencial del sistema unificado
if [ -d "$BUILD_DIR/tools" ]; then
    echo "📚 Conservando documentación esencial del sistema unificado..."
    # Crear directorio docs si no existe
    mkdir -p "$BUILD_DIR/docs"
    
    # Conservar documentación importante para el usuario final
    if [ -f "$BUILD_DIR/tools/unify-config-cleanup.php" ]; then
        cp "$BUILD_DIR/tools/unify-config-cleanup.php" "$BUILD_DIR/docs/migracion-configuracion.php"
        echo "✅ Script de migración incluido en docs/"
    fi
    
    # Eliminar el directorio tools completo
    rm -rf "$BUILD_DIR/tools"
fi

# No eliminar README.txt ya que es necesario para WordPress
find "$BUILD_DIR" -name '*.txt' -not -name 'README.txt' -delete

# ✅ DOCUMENTACIÓN INTERNA Y DE DESARROLLO AMPLIADA
echo "🗑️ Eliminando documentación interna extendida..."
INTERNAL_DOCS=(
    "README-SOLUCION.md"
    "INSTRUCCIONES-RAPIDAS.md"
    "ESTADO-FINAL-COMPLETO.md"
    "RESUMEN-FINAL-UNIFICACION.md"
    "ANÁLISIS*"
    "analisis*"
    "AUDITORIA*"
    "CHECKLIST*"
    "REPORTE*"
    "PLAN_*"
    "ESTADO_*"
    "FLUJO_*"
    "IMPLEMENTACION_*"
    "DOCUMENTACION_*"
    "INFORME_*"
    "PROBLEMAS_*"
    "AREAS_*"
    "COMPARATIVA_*"
    "RECOMENDACIONES*"
)

for pattern in "${INTERNAL_DOCS[@]}"; do
    find "$BUILD_DIR" -name "$pattern" -type f -delete 2>/dev/null || true
done

# Optimizar vendor para reducir tamaño
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo "📦 Instalando dependencias de producción optimizadas..."
    # Entrar al directorio build y ejecutar composer install solo para producción
    cd "$BUILD_DIR"
    
    # Limpiar el cache de composer para evitar conflictos
    composer clear-cache --quiet || echo "⚠️ No se pudo limpiar el cache de composer"
    
    # Instalar dependencias optimizadas para producción
    composer install --no-dev --optimize-autoloader --classmap-authoritative --quiet || echo "⚠️ No se pudieron instalar las dependencias. El plugin podría no funcionar correctamente."
    
    # Regenerar autoloader con optimización máxima
    composer dump-autoload --optimize --classmap-authoritative --quiet || echo "⚠️ No se pudo regenerar el autoloader optimizado"
    
    # Limpiar archivos innecesarios del vendor para reducir tamaño
    echo "🗑️ Reduciendo tamaño de la carpeta vendor..."
    if [ -d "$BUILD_DIR/vendor" ]; then
        # Eliminar archivos de documentación, test y desarrollo
        find "$BUILD_DIR/vendor" -type d -name "doc" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "docs" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "test" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name "tests" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -type d -name ".github" -exec rm -rf {} \; 2>/dev/null || true
        find "$BUILD_DIR/vendor" -name "*.md" -delete
        find "$BUILD_DIR/vendor" -name "*.txt" -not -name "LICENSE.txt" -delete
        find "$BUILD_DIR/vendor" -name "phpunit.*" -delete
        find "$BUILD_DIR/vendor" -name ".travis.yml" -delete
        find "$BUILD_DIR/vendor" -name ".gitignore" -delete
    fi
    cd - > /dev/null
    
    # Mostrar tamaño del vendor optimizado
    if [ -d "$BUILD_DIR/vendor" ]; then
        VENDOR_SIZE=$(du -sh "$BUILD_DIR/vendor" | cut -f1)
        echo "📊 Tamaño de vendor optimizado: $VENDOR_SIZE"
    fi
else
    # Si no hay composer.json, eliminar vendor si existe
    find "$BUILD_DIR" -name 'vendor' -type d -exec rm -rf {} \; 2>/dev/null || true
fi

# Entrar al directorio build y crear el ZIP desde ahí para tener la estructura correcta
echo "🔒 Creando archivo ZIP..."
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" ./* > /dev/null

# Verificar si el ZIP se creó correctamente
if [ ! -f "$ZIP_FILE" ]; then
    echo "❌ Error: No se pudo crear el archivo ZIP. Verifica que tienes permisos y espacio suficiente."
    exit 1
fi

# Mover el ZIP al directorio original, al escritorio y al pendrive (si está montado)
echo "🚚 Moviendo el archivo ZIP a las ubicaciones finales..."
cp "$ZIP_FILE" "$HOME/Escritorio/$ZIP_FILE"
mv "$ZIP_FILE" "$PLUGIN_DIR/$ZIP_FILE"

# Intentar detectar y copiar al primer pendrive montado en /media o /run/media
PENDRIVE_MOUNT=""
if mount | grep -q "/media/"; then
    PENDRIVE_MOUNT=$(lsblk -o MOUNTPOINT | grep "/media/" | head -n1 | xargs)
fi
if [ -z "$PENDRIVE_MOUNT" ] && mount | grep -q "/run/media/"; then
    PENDRIVE_MOUNT=$(lsblk -o MOUNTPOINT | grep "/run/media/" | head -n1 | xargs)
fi
if [ -n "$PENDRIVE_MOUNT" ] && [ -d "$PENDRIVE_MOUNT" ]; then
    cp "$PLUGIN_DIR/$ZIP_FILE" "$PENDRIVE_MOUNT/$ZIP_FILE"
    echo "✅ Copia adicional guardada en el pendrive: $PENDRIVE_MOUNT/$ZIP_FILE"
else
    echo "ℹ️ No se detectó pendrive montado en /media o /run/media. Solo se copió a Escritorio y proyecto."
fi

# Verificar que las copias se hicieron correctamente
if [ ! -f "$HOME/Escritorio/$ZIP_FILE" ] || [ ! -f "$PLUGIN_DIR/$ZIP_FILE" ]; then
    echo "⚠️ Advertencia: No se pudieron copiar los archivos a las ubicaciones finales."
fi

# ✅ VERIFICACIÓN FINAL DE ARCHIVOS FILTRADOS
echo ""
echo "🔍 VERIFICACIÓN FINAL DE LIMPIEZA"
echo "================================="

# Verificar si quedan archivos sospechosos
SUSPICIOUS_COUNT=0

# Verificar archivos de backup
BACKUP_FILES=$(find "$BUILD_DIR" -name "*.bak" -o -name "*.backup" -o -name "*.old" -o -name "*copy*" 2>/dev/null | wc -l)
if [ $BACKUP_FILES -gt 0 ]; then
    echo "⚠️ Advertencia: Encontrados $BACKUP_FILES archivos de backup"
    SUSPICIOUS_COUNT=$((SUSPICIOUS_COUNT + BACKUP_FILES))
fi

# Verificar archivos de testing
TEST_FILES=$(find "$BUILD_DIR" -name "test*.php" -o -name "*test.php" -o -name "*Test.php" 2>/dev/null | wc -l)
if [ $TEST_FILES -gt 0 ]; then
    echo "⚠️ Advertencia: Encontrados $TEST_FILES archivos de testing"
    SUSPICIOUS_COUNT=$((SUSPICIOUS_COUNT + TEST_FILES))
fi

# Verificar scripts no esenciales
SCRIPT_FILES=$(find "$BUILD_DIR" -name "*.sh" -not -path "*/scripts/optimize-autoloader.sh" 2>/dev/null | wc -l)
if [ $SCRIPT_FILES -gt 0 ]; then
    echo "⚠️ Advertencia: Encontrados $SCRIPT_FILES scripts no esenciales"
    SUSPICIOUS_COUNT=$((SUSPICIOUS_COUNT + SCRIPT_FILES))
fi

# Verificar documentación de desarrollo
DEV_DOCS=$(find "$BUILD_DIR" -name "ANALISIS*" -o -name "CHECKLIST*" -o -name "REPORTE*" 2>/dev/null | wc -l)
if [ $DEV_DOCS -gt 0 ]; then
    echo "⚠️ Advertencia: Encontrados $DEV_DOCS documentos de desarrollo"
    SUSPICIOUS_COUNT=$((SUSPICIOUS_COUNT + DEV_DOCS))
fi

if [ $SUSPICIOUS_COUNT -eq 0 ]; then
    echo "✅ Verificación completada: No se encontraron archivos sospechosos"
else
    echo "⚠️ Total de archivos sospechosos encontrados: $SUSPICIOUS_COUNT"
    echo "   Se recomienda revisar manualmente antes de distribuir el plugin"
fi

# Limpiar
cd - > /dev/null
rm -rf "$BUILD_DIR"

# Mostrar tamaño del archivo ZIP
ZIP_SIZE=$(du -h "$PLUGIN_DIR/$ZIP_FILE" | cut -f1)

# ✅ GENERAR REPORTE DE LIMPIEZA
echo ""
echo "📊 REPORTE DE LIMPIEZA DEL PLUGIN"
echo "================================="
echo "✅ Archivos de backup eliminados: *.bak, *.backup, *.old, *.orig, etc."
echo "✅ Archivos temporales eliminados: *.tmp, *.temp, *.swp, *~, etc."
echo "✅ Archivos de sistema eliminados: .DS_Store, Thumbs.db, desktop.ini"
echo "✅ Directorios de desarrollo eliminados: .vscode, .idea, .git, tests, coverage"
echo "✅ Scripts de desarrollo eliminados (excepto optimize-autoloader.sh)"
echo "✅ Archivos de testing eliminados: test-*.php, *-test.php, *Test.php"
echo "✅ Archivos de análisis eliminados: analisis-*.php, diagnostico-*.php, etc."
echo "✅ Archivos de stubs eliminados: *stubs*.php, wordpress-stubs.php"
echo "✅ Archivos de configuración dev eliminados: .env*, .gitignore, composer.lock"
echo "✅ Documentación de desarrollo eliminada: CHECKLIST*, ANALISIS*, REPORTE*, etc."
echo "✅ Archivos específicos del proyecto eliminados: build-*.sh, *.postman_collection.json"
echo "✅ Directorios innecesarios eliminados: backups, uploads, test_data, documentacion"

echo ""
echo "✅ PLUGIN COMPILADO EXITOSAMENTE"
echo "================================="
echo "📁 Ubicaciones:"
echo "   - Escritorio: $HOME/Escritorio/$ZIP_FILE"
echo "   - Proyecto: $PLUGIN_DIR/$ZIP_FILE"
echo "📊 Información:"
echo "   - Tamaño: $ZIP_SIZE"
echo "   - Fecha: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "🎯 FUNCIONALIDADES PRINCIPALES INCLUIDAS:"
echo "   ✅ Sistema de configuración unificado con VerialApiConfig"
echo "   ✅ ApiConnector optimizado con paginación completa"
echo "   ✅ Sync_Manager como orquestador principal"
echo "   ✅ Sistema de locks para sincronización segura"
echo "   ✅ Sistema de detección automática de stock"
echo "   ✅ Dashboard de administración avanzado"
echo "   ✅ Compatibilidad con versiones anteriores"
echo "   ✅ Script de migración incluido en docs/"
echo "   ✅ Documentación de usuario incluida"
echo ""
echo "🔄 SINCRONIZACIÓN AVANZADA REFACTORIZADA:"
echo "   ✅ Arquitectura simplificada (eliminadas capas innecesarias)"
echo "   ✅ Sync_Manager delega completamente a BatchProcessor"
echo "   ✅ Sincronización por lotes optimizada"
echo "   ✅ Sistema de cache de precios implementado"
echo "   ✅ Mapeo de productos Verial-WooCommerce mejorado"
echo "   ✅ BatchProcessor con llamadas directas a API"
echo "   ✅ Eliminación de ApiConnector_Adapter y BatchApiHelper"
echo "   ✅ Principio de responsabilidad única aplicado"
echo ""
echo "⚙️ AUTOLOADERS Y SEGURIDAD OPTIMIZADOS:"
echo "   ✅ Arquitectura de 3 niveles implementada"
echo "   ✅ ComposerAutoloader (nivel primario optimizado)"
echo "   ✅ SmartAutoloader (nivel inteligente con cache)"
echo "   ✅ EmergencyLoader (nivel de emergencia crítico)"
echo "   ✅ AutoloaderManager (coordinador maestro)"
echo "   ✅ SSLAdvancedSystemsTrait (seguridad SSL avanzada)"
echo "   ✅ Script optimize-autoloader.sh incluido"
echo "   ✅ PSR-4 compliant para todas las clases"
echo "   ✅ Sistema de traits para funcionalidades compartidas"
echo ""
echo "🔍 SISTEMA DE DETECCIÓN AUTOMÁTICA:"
echo "   ✅ StockDetector para monitoreo automático de stock"
echo "   ✅ StockDetectorIntegration para integración con WordPress"
echo "   ✅ DetectionDashboard con interfaz de administración"
echo "   ✅ Cron jobs automáticos cada 5 minutos"
echo "   ✅ Sistema de locks para evitar ejecuciones simultáneas"
echo "   ✅ Cache inteligente para optimizar rendimiento"
echo "   ✅ Logging detallado de todas las operaciones"
echo "   ✅ Interfaz de usuario moderna y responsive"
echo "   ✅ Configuración avanzada desde el admin"
echo "   ✅ Estadísticas en tiempo real"
echo ""
echo "🔔 SISTEMA DE NOTIFICACIONES AVANZADO:"
echo "   ✅ WooCommerceProductNotifier para eventos de productos"
echo "   ✅ Sistema de notificaciones en tiempo real"
echo "   ✅ Panel de notificaciones en el dashboard"
echo "   ✅ Configuración de tipos de notificaciones"
echo "   ✅ Sistema de prioridades (info, warning, error, success)"
echo "   ✅ Notificaciones de sincronización y errores"
echo "   ✅ Alertas de stock bajo"
echo "   ✅ Documentos de solicitud a Verial"
echo "   ✅ Gestión de notificaciones (leer, archivar, limpiar)"
echo "   ✅ Configuración de retención y programación"
echo ""
echo "�📋 CONTENIDO DEL PLUGIN:"
if [ -d "$PLUGIN_DIR/includes/Core" ]; then
    CORE_FILES=$(find "$PLUGIN_DIR/includes/Core" -name "*.php" | wc -l)
    echo "   - $CORE_FILES archivos principales del sistema unificado"
fi
if [ -d "$PLUGIN_DIR/includes/Sync" ]; then
    SYNC_FILES=$(find "$PLUGIN_DIR/includes/Sync" -name "*.php" | wc -l)
    echo "   - $SYNC_FILES archivos del sistema de sincronización"
fi
if [ -d "$PLUGIN_DIR/includes/Cache" ]; then
    CACHE_FILES=$(find "$PLUGIN_DIR/includes/Cache" -name "*.php" | wc -l)
    echo "   - $CACHE_FILES archivos del sistema de cache"
fi
if [ -d "$PLUGIN_DIR/includes/WooCommerce" ]; then
    WC_FILES=$(find "$PLUGIN_DIR/includes/WooCommerce" -name "*.php" | wc -l)
    echo "   - $WC_FILES archivos de integración WooCommerce"
fi
if [ -d "$PLUGIN_DIR/includes/Helpers" ]; then
    HELPER_FILES=$(find "$PLUGIN_DIR/includes/Helpers" -name "*.php" | wc -l)
    echo "   - $HELPER_FILES archivos de utilidades y helpers"
fi
if [ -d "$PLUGIN_DIR/includes/Endpoints" ]; then
    ENDPOINT_FILES=$(find "$PLUGIN_DIR/includes/Endpoints" -name "*.php" | wc -l)
    echo "   - $ENDPOINT_FILES archivos de endpoints API REST"
fi
if [ -d "$PLUGIN_DIR/includes/Deteccion" ]; then
    DETECTION_FILES=$(find "$PLUGIN_DIR/includes/Deteccion" -name "*.php" | wc -l)
    echo "   - $DETECTION_FILES archivos del sistema de detección automática"
fi
if [ -d "$PLUGIN_DIR/includes/Hooks" ]; then
    HOOKS_FILES=$(find "$PLUGIN_DIR/includes/Hooks" -name "*.php" | wc -l)
    echo "   - $HOOKS_FILES archivos del sistema de hooks"
fi
if [ -d "$PLUGIN_DIR/templates/admin" ]; then
    TEMPLATE_FILES=$(find "$PLUGIN_DIR/templates/admin" -name "*.php" | wc -l)
    echo "   - $TEMPLATE_FILES templates de administración"
fi
if [ -d "$PLUGIN_DIR/assets/css" ]; then
    CSS_FILES=$(find "$PLUGIN_DIR/assets/css" -name "*.css" | wc -l)
    echo "   - $CSS_FILES archivos CSS (incluyendo admin-dashboard.css)"
fi
if [ -d "$PLUGIN_DIR/assets/js" ]; then
    JS_FILES=$(find "$PLUGIN_DIR/assets/js" -name "*.js" | wc -l)
    echo "   - $JS_FILES archivos JavaScript (incluyendo admin-dashboard.js)"
fi
if [ -d "$PLUGIN_DIR/docs" ]; then
    DOC_COUNT=$(find "$PLUGIN_DIR/docs" -name "*.md" | wc -l)
    echo "   - Documentación para el usuario (archivos esenciales)"
fi
echo "   - Sistema de configuración unificado"
echo "   - Arquitectura simplificada y optimizada"
echo "   - Principio de responsabilidad única aplicado"
echo "   - Compatibilidad con configuraciones existentes"
echo ""
echo "🚀 LISTO PARA INSTALACIÓN EN WORDPRESS"
echo "================================="
