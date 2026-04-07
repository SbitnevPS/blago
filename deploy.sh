#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/home/users/s/sbitnevps/domains/konkurs.tolkodobroe.info"
BRANCH_REF="origin/main"

log() {
  printf '[deploy] %s\n' "$1"
}

fail() {
  printf '[deploy][error] %s\n' "$1" >&2
  exit 1
}

log "Starting deploy in ${PROJECT_DIR}"
cd "${PROJECT_DIR}" || fail "Project directory not found"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  fail "Directory is not a git repository"
fi

log "Aborting unfinished merge/rebase if present"
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true

log "Fetching latest changes from origin"
git fetch origin

log "Resetting working tree to ${BRANCH_REF}"
git reset --hard "${BRANCH_REF}"

if command -v composer >/dev/null 2>&1; then
  log "Installing/updating production dependencies"
  composer install --no-dev --optimize-autoloader --no-interaction
else
  log "Composer not found, skipping dependency install"
fi

log "Ensuring runtime directories exist"
mkdir -p uploads storage

log "Setting permissions for runtime directories"
chmod -R u+rwX,go+rX uploads storage || true

log "Deploy finished successfully"
