#!/bin/bash

# Script para generar diagramas del sistema
# Requiere: plantuml.jar instalado

echo "Generando diagramas del sistema..."

# Crear directorio de salida
mkdir -p docs/images

# Generar diagramas PlantUML
if command -v plantuml &> /dev/null; then
    plantuml -o ../images docs/plantuml-diagrams.puml
    echo "✅ Diagramas PlantUML generados en docs/images/"
else
    echo "⚠️  PlantUML no está instalado. Instálalo con:"
    echo "   sudo apt install plantuml"
    echo "   o descarga plantuml.jar desde https://plantuml.com/"
fi

# Generar diagrama Mermaid (se renderiza automáticamente en GitHub)
echo "✅ Diagramas Mermaid listos para GitHub en docs/system-architecture.md"

echo "🎨 Diagramas del sistema generados exitosamente!"
