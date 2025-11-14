#!/bin/bash
set -e

echo "[1] Updating system..."
sudo apt update -y

echo "[2] Installing PHP + GD + cURL..."
sudo apt install -y php php-cli php-fpm php-gd php-curl php-xml php-mbstring

echo "[3] Installing Tesseract OCR..."
sudo apt install -y tesseract-ocr tesseract-ocr-eng

echo "[4] Starting PHP server..."
php -S 0.0.0.0:8080 index.php
