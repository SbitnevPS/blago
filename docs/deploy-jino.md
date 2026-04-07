# Jino deploy flow (fetch/reset)

## How deploy works
- Public endpoint `deploy.php?key=...` runs `deploy.sh`.
- `deploy.sh` **does not use `git pull`**. It always runs:
  - `git merge --abort 2>/dev/null || true`
  - `git rebase --abort 2>/dev/null || true`
  - `git fetch origin`
  - `git reset --hard origin/main`
- This guarantees server code becomes an exact copy of `origin/main`.

## Why `git pull` is forbidden on this server
`git pull` previously failed because local and remote branches diverged and unfinished merge/rebase states accumulated. `fetch + reset --hard` is deterministic and conflict-free for deploy-target servers.

## Server-only files and secrets
- `config.php` must exist only on server and is not tracked by git.
- Deploy secret must be kept outside repository (`DEPLOY_SECRET` env var).
- The GitHub SSH key on server may be read-only; this is expected for deploy-only flow because server only fetches.

## Do not edit manually in tracked area
Do not patch tracked files directly on server. Commit changes from local development and deploy by opening the secret URL.

## Runtime data excluded from git
- `uploads/` (user files)
- `storage/` (generated/runtime artifacts, including mPDF cache)
