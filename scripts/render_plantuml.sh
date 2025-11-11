#!/usr/bin/env bash
set -euo pipefail

# Renderiza todos los .puml en docs/diagrams a SVG (requiere plantuml en PATH o docker)
# Uso:
#   ./scripts/render_plantuml.sh              # usa plantuml local
#   PLANTUML_DOCKER=1 ./scripts/render_plantuml.sh   # usa imagen docker "plantuml/plantuml"

DIAGRAM_DIR="docs/diagrams"
OUT_DIR="$DIAGRAM_DIR/rendered"
mkdir -p "$OUT_DIR"

render_local() {
  command -v plantuml >/dev/null 2>&1 || { echo "plantuml no encontrado en PATH" >&2; exit 1; }
  plantuml -tsvg -o "rendered" "$DIAGRAM_DIR"/*.puml
}

render_docker() {
  docker run --rm -v "$(pwd)/$DIAGRAM_DIR":/work plantuml/plantuml -tsvg /work/*.puml
  mkdir -p "$OUT_DIR"
  # Los SVG quedan junto al .puml, moverlos a rendered/
  for f in "$DIAGRAM_DIR"/*.svg; do
    [ -f "$f" ] && mv "$f" "$OUT_DIR"/
  done
}

if [[ "${PLANTUML_DOCKER:-0}" == "1" ]]; then
  echo "[INFO] Render con Docker"
  render_docker
else
  echo "[INFO] Render local"
  render_local
fi

echo "Listo. SVG generados en $OUT_DIR"
