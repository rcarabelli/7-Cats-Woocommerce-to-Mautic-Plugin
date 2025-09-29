#!/usr/bin/env bash
set -euo pipefail

# Uso: ./dump_plugin.sh wp-content/plugins/7c-shop2mautic
PLUGIN_PATH="${1:-}"
if [[ -z "$PLUGIN_PATH" ]]; then
  echo "Uso: $0 <ruta_del_plugin_relativa_o_absoluta>"
  echo "Ej:  $0 wp-content/plugins/7c-shop2mautic"
  exit 1
fi

# Normaliza y valida
if [[ ! -d "$PLUGIN_PATH" ]]; then
  echo "No existe el directorio: $PLUGIN_PATH" >&2
  exit 1
fi

# Archivo de salida en la raíz actual
PLUGIN_BASENAME="$(basename "$PLUGIN_PATH")"
OUT="DUMP_${PLUGIN_BASENAME}_$(date +%Y%m%d_%H%M%S).txt"

# Extensiones a excluir (imágenes, video, audio) + .htaccess
EXCL_REGEX='\.(jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|psd|tif|tiff|heic|heif|jp2|jxl|webm|mp4|m4v|mov|avi|mkv|wmv|flv|mpg|mpeg|3gp|3g2|mxf|mp3|m4a|aac|wav|flac|ogg|oga|opus|amr)$'

{
  echo "===== DUMP START ====="
  echo "Plugin: $PLUGIN_PATH"
  echo "Fecha:  $(date -R)"
  echo

  echo "===== FILE TREE (simple) ====="
  # Árbol simple y ordenado
  find "$PLUGIN_PATH" -print | sed "s|^|/|g" | sort
  echo
  echo "===== FILES & CONTENT ====="
} > "$OUT"

# Recorre archivos, excluyendo .htaccess y extensiones multimedia
# Detección de binario con 'file -bi'
find "$PLUGIN_PATH" -type f ! -iname ".htaccess" \
| sort \
| while IFS= read -r f; do
    fname_lower="$(printf '%s' "$f" | awk '{print tolower($0)}')"
    if [[ "$fname_lower" =~ $EXCL_REGEX ]]; then
      continue
    fi

    # Detección de binario/charset
    mimetype="$(file -bi "$f" || true)"
    # Si es octet-stream o application/* no-textual evidente, saltar
    if echo "$mimetype" | grep -qiE 'application/(octet-stream|x-executable)'; then
      continue
    fi

    {
      echo
      echo "========== FILE: $f =========="
      echo "MIME: $mimetype"
      echo
      sed -n '1,500000p' "$f"
    } >> "$OUT"
  done

echo "Listo: $(realpath "$OUT")"
