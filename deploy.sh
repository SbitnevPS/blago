#!/bin/bash
set -e

PROJECT_DIR="/home/users/p/pavel-sbitnev/domains/test.tolkodobroe.info"
BRANCH="develop"

echo "[deploy] Starting deploy in ${PROJECT_DIR}"

cd "${PROJECT_DIR}"

if [ -d .git/rebase-merge ] || [ -d .git/rebase-apply ]; then
  git rebase --abort || true
fi

if [ -f .git/MERGE_HEAD ]; then
  git merge --abort || true
fi

git fetch origin
git reset --hard "origin/${BRANCH}"
git clean -fd

if [ -f composer.phar ]; then
  php composer.phar install --no-dev --optimize-autoloader
fi

echo "[deploy] Done."