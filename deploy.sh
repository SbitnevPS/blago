#!/bin/bash
set -e

PROJECT_DIR="/home/users/p/pavel-sbitnev/domains/test.tolkodobroe.info"
BRANCH="develop"

echo "[deploy] Starting deploy in ${PROJECT_DIR}"

cd "${PROJECT_DIR}"

if [ -d .git/rebase-merge ] || [ -d .git/rebase-apply ]; then
  echo "[deploy] Aborting unfinished rebase"
  git rebase --abort || true
fi

if [ -f .git/MERGE_HEAD ]; then
  echo "[deploy] Aborting unfinished merge"
  git merge --abort || true
fi

echo "[deploy] Fetching updates..."
git fetch origin

echo "[deploy] Reset to origin/${BRANCH}..."
git reset --hard "origin/${BRANCH}"

echo "[deploy] Cleaning..."
git clean -fd

if [ -f composer.phar ]; then
  echo "[deploy] Installing composer dependencies..."
  php composer.phar install --no-dev --optimize-autoloader
else
  echo "[deploy] composer.phar not found, skipping composer install"
fi

echo "[deploy] Done."
