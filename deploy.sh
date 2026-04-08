#!/bin/bash

# deploy.sh — деплой проекта

echo "[deploy] Starting deploy..."

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

cd "$PROJECT_DIR" || {
  echo "[deploy][error] Project directory not found"
  exit 1
}

# 🔄 Обновляем код
echo "[deploy] Fetching updates..."
git fetch origin

echo "[deploy] Reset to origin/main..."
git reset --hard origin/main

# 🧹 Очистка (если нужно)
echo "[deploy] Cleaning..."
git clean -fd

# 📦 Установка зависимостей (если используешь composer)
if [ -f "composer.json" ]; then
  echo "[deploy] Installing composer dependencies..."
  composer install --no-dev --optimize-autoloader
fi

# 🔐 Права (опционально)
chmod -R 775 storage uploads 2>/dev/null

echo "[deploy] Done."
