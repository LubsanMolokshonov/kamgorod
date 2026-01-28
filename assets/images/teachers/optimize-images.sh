#!/bin/bash

IMAGES=(1 2 3 4)
BASE_DIR="."

echo "Starting image optimization..."

for i in "${IMAGES[@]}"; do
  echo "Processing image $i..."

  # Desktop JPG (600x600)
  convert "$BASE_DIR/$i.png" \
    -resize 600x600^ \
    -gravity center \
    -extent 600x600 \
    -quality 85 \
    -strip \
    "$BASE_DIR/optimized/desktop/$i.jpg"

  # Desktop WebP
  cwebp -q 85 \
    "$BASE_DIR/optimized/desktop/$i.jpg" \
    -o "$BASE_DIR/optimized/desktop/$i.webp"

  # Mobile JPG (320x320)
  convert "$BASE_DIR/$i.png" \
    -resize 320x320^ \
    -gravity center \
    -extent 320x320 \
    -quality 85 \
    -strip \
    "$BASE_DIR/optimized/mobile/$i.jpg"

  # Mobile WebP
  cwebp -q 85 \
    "$BASE_DIR/optimized/mobile/$i.jpg" \
    -o "$BASE_DIR/optimized/mobile/$i.webp"

  echo "✓ Image $i optimized"
done

echo "✓ All images optimized successfully!"
echo ""
echo "Results:"
ls -lh optimized/desktop/ | awk '{print $5, $9}'
ls -lh optimized/mobile/ | awk '{print $5, $9}'
