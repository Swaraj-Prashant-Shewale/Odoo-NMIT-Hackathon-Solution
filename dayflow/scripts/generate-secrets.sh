#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Dayflow - configuration setup
#
# Generates the cryptographic secrets Dayflow needs and writes them into .env.
# Values that already have a content are left alone unless --force is given, so
# running this twice is safe and will not sign everyone out.
#
#   bash scripts/generate-secrets.sh
#   bash scripts/generate-secrets.sh --admin-email you@company.com
#   bash scripts/generate-secrets.sh --force
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
EXAMPLE_FILE="$ROOT/.env.example"

FORCE=0
ADMIN_EMAIL=""
ADMIN_PASSWORD=""

while [ $# -gt 0 ]; do
  case "$1" in
    --force)          FORCE=1; shift ;;
    --admin-email)    ADMIN_EMAIL="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    -h|--help)
      sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

printf '\n  Dayflow - configuration setup\n'
printf '  -----------------------------\n\n'

if [ ! -f "$ENV_FILE" ]; then
  if [ ! -f "$EXAMPLE_FILE" ]; then
    echo "  .env.example is missing. Run this from inside the project folder." >&2
    exit 1
  fi

  cp "$EXAMPLE_FILE" "$ENV_FILE"
  echo "  Created .env from .env.example"
fi

# --- Random value generation -------------------------------------------------
# Reads the kernel CSPRNG. $RANDOM is deliberately not used: it is seeded
# pseudo-randomness and unfit for a signing key.
new_secret() {
  local length="${1:-48}"
  LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length"
}

env_value() {
  local key="$1"
  grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | head -n1 | cut -d'=' -f2- || true
}

set_env_value() {
  local key="$1"
  local value="$2"
  local tmp
  tmp="$(mktemp)"

  if grep -qE "^${key}=" "$ENV_FILE"; then
    # awk rather than sed: the generated values are arbitrary strings and a
    # sed replacement would break on any character it treats as special.
    awk -v k="$key" -v v="$value" '
      BEGIN { FS = "=" }
      $1 == k { print k "=" v; next }
      { print }
    ' "$ENV_FILE" > "$tmp"
  else
    cp "$ENV_FILE" "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
  fi

  mv "$tmp" "$ENV_FILE"
}

# --- Secrets -----------------------------------------------------------------
generated=0
kept=0

set_secret() {
  local key="$1"
  local length="$2"
  local current
  current="$(env_value "$key")"

  if [ -n "$current" ] && [ "$FORCE" -eq 0 ]; then
    kept=$((kept + 1))
    return
  fi

  set_env_value "$key" "$(new_secret "$length")"
  generated=$((generated + 1))
}

set_secret POSTGRES_PASSWORD 40
set_secret DAYFLOW_DB_SERVICE_PASSWORD 40
set_secret JWT_SECRET 64
set_secret INTERNAL_SIGNING_KEY 64
set_secret ENCRYPTION_KEY 64

[ "$generated" -gt 0 ] && echo "  Generated $generated secret(s)"
[ "$kept" -gt 0 ] && echo "  Kept $kept existing secret(s). Use --force to replace them."

# --- Administrator account ---------------------------------------------------
current_email="$(env_value SEED_ADMIN_EMAIL)"
current_password="$(env_value SEED_ADMIN_PASSWORD)"

if [ -n "$ADMIN_EMAIL" ]; then
  set_env_value SEED_ADMIN_EMAIL "$ADMIN_EMAIL"
elif [ -z "$current_email" ] || [ "$current_email" = "admin@example.com" ]; then
  read -r -p "  Email for the first administrator: " entered
  [ -n "$entered" ] && set_env_value SEED_ADMIN_EMAIL "$entered"
fi

if [ -n "$ADMIN_PASSWORD" ]; then
  set_env_value SEED_ADMIN_PASSWORD "$ADMIN_PASSWORD"
elif [ -z "$current_password" ]; then
  read -r -s -p "  Password for that account: " entered
  echo
  if [ -z "$entered" ]; then
    echo "  An administrator password is required." >&2
    exit 1
  fi
  set_env_value SEED_ADMIN_PASSWORD "$entered"
fi

chmod 600 "$ENV_FILE" 2>/dev/null || true

printf '\n  .env is ready.\n\n'
printf '  Next:\n'
printf '    docker compose up\n\n'
printf '  Then open http://localhost:8000\n\n'
printf '  Keep .env out of version control. It is already listed in .gitignore.\n\n'
