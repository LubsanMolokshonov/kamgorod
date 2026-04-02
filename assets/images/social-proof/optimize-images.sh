#!/bin/bash
# Оптимизация изображений для блока социальных доказательств
# Создаёт thumb/ (max 400px) и full/ (max 1400px) версии в JPG + WebP

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
SRC="$PROJECT_ROOT/Дипломы"
THUMB_DIR="$SCRIPT_DIR/thumb"
FULL_DIR="$SCRIPT_DIR/full"

mkdir -p "$THUMB_DIR" "$FULL_DIR"

optimize() {
    local src="$1"
    local name="$2"

    echo "Processing: $name"

    # Full size (max 1400px width)
    convert "$src" -resize "1400x>" -quality 85 -strip "$FULL_DIR/${name}.jpg"
    cwebp -q 85 "$FULL_DIR/${name}.jpg" -o "$FULL_DIR/${name}.webp" 2>/dev/null

    # Thumbnail (max 400px width)
    convert "$src" -resize "400x>" -quality 80 -strip "$THUMB_DIR/${name}.jpg"
    cwebp -q 80 "$THUMB_DIR/${name}.jpg" -o "$THUMB_DIR/${name}.webp" 2>/dev/null

    echo "  -> thumb: $(du -h "$THUMB_DIR/${name}.jpg" | cut -f1) / full: $(du -h "$FULL_DIR/${name}.jpg" | cut -f1)"
}

# RBC рейтинг
optimize "$SRC/28mesto.png" "rbc"

# HH.ru рейтинг
optimize "$SRC/hhru.png" "hhru"

# Лицензии
optimize "$SRC/Лицензии/vipiska_erl_page-0001_1.jpg" "license-1"
optimize "$SRC/Лицензии/vipiska_erl_page-0002_1.jpg" "license-2"

# Благодарности (PNG файлы)
counter=1
for file in "$SRC/Благодароности/"*.png; do
    [ -f "$file" ] || continue
    name=$(printf "thanks-%02d" $counter)
    optimize "$file" "$name"
    counter=$((counter + 1))
done

# Благодарности (PDF файлы -> конвертация первой страницы)
for file in "$SRC/Благодароности/"*.pdf; do
    [ -f "$file" ] || continue
    name=$(printf "thanks-%02d" $counter)
    echo "Converting PDF: $name"
    # Конвертировать первую страницу PDF в PNG
    convert "${file}[0]" -density 200 -quality 90 "/tmp/${name}_tmp.png"
    optimize "/tmp/${name}_tmp.png" "$name"
    rm -f "/tmp/${name}_tmp.png"
    counter=$((counter + 1))
done

echo ""
echo "Done! Total files:"
echo "  Thumbnails: $(ls "$THUMB_DIR"/*.jpg 2>/dev/null | wc -l) JPG + $(ls "$THUMB_DIR"/*.webp 2>/dev/null | wc -l) WebP"
echo "  Full size:  $(ls "$FULL_DIR"/*.jpg 2>/dev/null | wc -l) JPG + $(ls "$FULL_DIR"/*.webp 2>/dev/null | wc -l) WebP"
