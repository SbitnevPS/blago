#!/bin/bash

set -e

PROJECT_DIR="/home/users/p/pavel-sbitnev/domains/konkurs.tolkodobroe.info"
BRANCH="main"

echo "[deploy] Starting deploy in $PROJECT_DIR"
cd "$PROJECT_DIR"

echo "[deploy] Current branch:"
git branch --show-current || true

echo "[deploy] Fetching origin"
git fetch origin

echo "[deploy] Resetting to origin/$BRANCH"
git reset --hard "origin/$BRANCH"

echo "[deploy] Restoring permissions"
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

[ -f deploy.sh ] && chmod 755 deploy.sh
[ -f deploy.php ] && chmod 644 deploy.php
[ -f .htaccess ] && chmod 644 .htaccess
[ -f config.php ] && chmod 600 config.php

mkdir -p uploads storage
[ -f uploads/.gitkeep ] || touch uploads/.gitkeep
[ -f storage/.gitkeep ] || touch storage/.gitkeep

echo "[deploy] Done"