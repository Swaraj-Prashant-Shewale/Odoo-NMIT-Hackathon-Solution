#!/bin/bash
# ---------------------------------------------------------------------------
# Container entrypoint shared by every Dayflow PHP container.
#
# Confirms the runtime is configured correctly before Apache accepts traffic,
# so a missing secret fails loudly at startup rather than quietly at the first
# request that needs it.
# ---------------------------------------------------------------------------
set -euo pipefail

SERVICE="${SERVICE_NAME:-dayflow}"

echo "[${SERVICE}] starting"

# --- Required configuration -------------------------------------------------
missing=()
for var in JWT_SECRET INTERNAL_SIGNING_KEY; do
  if [ -z "${!var:-}" ]; then
    missing+=("$var")
  fi
done

if [ ${#missing[@]} -gt 0 ]; then
  echo "[${SERVICE}] FATAL: missing required configuration: ${missing[*]}" >&2
  echo "[${SERVICE}] Copy .env.example to .env and run scripts/generate-secrets before starting." >&2
  exit 1
fi

# --- Secret strength --------------------------------------------------------
if [ "${#JWT_SECRET}" -lt 32 ]; then
  echo "[${SERVICE}] FATAL: JWT_SECRET must be at least 32 characters." >&2
  exit 1
fi

if [ "${#INTERNAL_SIGNING_KEY}" -lt 32 ]; then
  echo "[${SERVICE}] FATAL: INTERNAL_SIGNING_KEY must be at least 32 characters." >&2
  exit 1
fi

# --- Writable paths ---------------------------------------------------------
for dir in /var/log/dayflow /var/www/storage/uploads /var/www/storage/mail /var/www/storage/cache; do
  mkdir -p "$dir" 2>/dev/null || true
  chown -R www-data:www-data "$dir" 2>/dev/null || true
done

# --- Wait for PostgreSQL ----------------------------------------------------
# Compose health checks cover the normal case; this handles a database that
# restarts underneath an already-running service.
if [ -n "${DB_HOST:-}" ]; then
  echo "[${SERVICE}] waiting for database at ${DB_HOST}:${DB_PORT:-5432}"
  for attempt in $(seq 1 60); do
    if php -r 'exit(@fsockopen(getenv("DB_HOST"), (int)(getenv("DB_PORT") ?: 5432), $e, $s, 1) ? 0 : 1);'; then
      echo "[${SERVICE}] database reachable"
      break
    fi
    if [ "$attempt" -eq 60 ]; then
      echo "[${SERVICE}] FATAL: database never became reachable" >&2
      exit 1
    fi
    sleep 1
  done
fi

echo "[${SERVICE}] ready on port 80"

exec "$@"
