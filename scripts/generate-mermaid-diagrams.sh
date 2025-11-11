#!/bin/bash

# Script para generar diagramas Mermaid del sistema
# Requiere: @mermaid-js/mermaid-cli instalado localmente

echo "🎨 Generando diagramas Mermaid del sistema..."

# Crear directorio de salida
mkdir -p docs/images

# Verificar si mermaid-cli está instalado
if [ ! -f "node_modules/.bin/mmdc" ]; then
    echo "❌ mermaid-cli no está instalado. Instalando..."
    npm install --save-dev @mermaid-js/mermaid-cli
fi

# Generar diagramas desde el archivo markdown
echo "📊 Generando diagramas desde docs/system-architecture.md..."

# Extraer diagramas Mermaid del archivo markdown y generar imágenes
npx mmdc -i docs/system-architecture.md -o docs/images/system-architecture.png -t dark -b transparent

echo "✅ Diagramas Mermaid generados en docs/images/"
echo "📁 Archivos generados:"
ls -la docs/images/

echo "🎉 ¡Diagramas del sistema generados exitosamente!"
echo "💡 Los diagramas también se renderizan automáticamente en GitHub"
