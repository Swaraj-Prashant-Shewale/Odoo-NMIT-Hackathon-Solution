#!/bin/bash
# ---------------------------------------------------------------------------
# Dayflow HRMS - database bootstrap
#
# Runs once, the first time the PostgreSQL container initialises its data
# directory. It creates:
#
#   * one schema per microservice,
#   * one login role per microservice, granted rights ONLY on its own schema,
#   * a shared "platform" schema for the audit trail and rate-limit counters.
#
# The isolation between services is therefore enforced by PostgreSQL itself.
# If the payroll service is ever compromised, its database credentials still
# cannot read a single row of identity or attendance data.
# ---------------------------------------------------------------------------
set -euo pipefail

SERVICE_PASSWORD="${DAYFLOW_DB_SERVICE_PASSWORD:?DAYFLOW_DB_SERVICE_PASSWORD must be provided}"

# service-name : schema-name
SERVICES=(
  "identity:identity"
  "employee:employee"
  "attendance:attendance"
  "leave:leave_management"
  "payroll:payroll"
  "learning:learning"
  "talent:talent"
  "notification:notification"
  "analytics:analytics"
)

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    -- Shared schema: cross-cutting concerns that every service writes to.
    CREATE SCHEMA IF NOT EXISTS platform;

    -- ---------------------------------------------------------------------
    -- Append-only audit trail.
    --
    -- Every service writes here, but nothing may ever update or delete a row;
    -- that is enforced below by granting INSERT and SELECT only.
    -- ---------------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS platform.audit_log (
        id            UUID PRIMARY KEY,
        occurred_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        service       TEXT        NOT NULL,
        action        TEXT        NOT NULL,
        subject_type  TEXT        NOT NULL,
        subject_id    TEXT,
        actor_id      TEXT,
        actor_email   TEXT,
        actor_role    TEXT,
        ip_address    TEXT,
        user_agent    TEXT,
        before_state  JSONB,
        after_state   JSONB,
        context       JSONB,
        request_id    TEXT
    );

    CREATE INDEX IF NOT EXISTS audit_log_occurred_at_idx ON platform.audit_log (occurred_at DESC);
    CREATE INDEX IF NOT EXISTS audit_log_actor_idx       ON platform.audit_log (actor_id, occurred_at DESC);
    CREATE INDEX IF NOT EXISTS audit_log_subject_idx     ON platform.audit_log (subject_type, subject_id);
    CREATE INDEX IF NOT EXISTS audit_log_action_idx      ON platform.audit_log (action);

    -- ---------------------------------------------------------------------
    -- Rate limiting counters, shared so the gateway and the identity service
    -- observe exactly the same windows.
    -- ---------------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS platform.rate_limits (
        bucket_key  TEXT PRIMARY KEY,
        hits        INTEGER     NOT NULL DEFAULT 0,
        expires_at  TIMESTAMPTZ NOT NULL
    );

    CREATE INDEX IF NOT EXISTS rate_limits_expires_idx ON platform.rate_limits (expires_at);

    -- ---------------------------------------------------------------------
    -- Organisation-wide settings that are genuinely global (company name,
    -- working week, currency). Written by the identity service, read by all.
    -- ---------------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS platform.settings (
        key         TEXT PRIMARY KEY,
        value       JSONB       NOT NULL,
        updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_by  TEXT
    );
SQL

echo "Dayflow: platform schema ready."

for entry in "${SERVICES[@]}"; do
  service="${entry%%:*}"
  schema="${entry##*:}"
  role="dayflow_${service}"

  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
      DO \$\$
      BEGIN
          IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${role}') THEN
              CREATE ROLE ${role} LOGIN PASSWORD '${SERVICE_PASSWORD}';
          END IF;
      END
      \$\$;

      CREATE SCHEMA IF NOT EXISTS ${schema} AUTHORIZATION ${role};

      -- The service owns its own schema outright.
      GRANT USAGE, CREATE ON SCHEMA ${schema} TO ${role};
      GRANT ALL PRIVILEGES ON ALL TABLES    IN SCHEMA ${schema} TO ${role};
      GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA ${schema} TO ${role};

      -- Shared schema: append to the audit trail and maintain rate counters,
      -- but no service may modify or erase an audit entry once written.
      GRANT USAGE ON SCHEMA platform TO ${role};
      GRANT INSERT, SELECT ON platform.audit_log TO ${role};
      GRANT SELECT, INSERT, UPDATE, DELETE ON platform.rate_limits TO ${role};
      GRANT SELECT ON platform.settings TO ${role};

      -- Connect, but nothing else at database level.
      GRANT CONNECT ON DATABASE ${POSTGRES_DB} TO ${role};

      -- Explicitly deny reach into anything not granted above. Revoking the
      -- public schema stops a service creating stray objects outside its own
      -- boundary.
      REVOKE ALL ON SCHEMA public FROM ${role};
SQL

  echo "Dayflow: schema '${schema}' provisioned for role '${role}'."
done

# Only the identity service serves the audit-trail screen, and only the
# analytics service aggregates across it.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    GRANT SELECT ON platform.audit_log TO dayflow_identity, dayflow_analytics;
    GRANT INSERT, UPDATE, DELETE ON platform.settings TO dayflow_identity;

    -- New tables created later by a service's migrations inherit the same
    -- rights automatically, so nothing has to be re-granted after a migration.
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_identity     IN SCHEMA identity            GRANT ALL ON TABLES TO dayflow_identity;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_employee     IN SCHEMA employee            GRANT ALL ON TABLES TO dayflow_employee;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_attendance   IN SCHEMA attendance          GRANT ALL ON TABLES TO dayflow_attendance;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_leave        IN SCHEMA leave_management    GRANT ALL ON TABLES TO dayflow_leave;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_payroll      IN SCHEMA payroll             GRANT ALL ON TABLES TO dayflow_payroll;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_learning     IN SCHEMA learning            GRANT ALL ON TABLES TO dayflow_learning;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_talent       IN SCHEMA talent              GRANT ALL ON TABLES TO dayflow_talent;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_notification IN SCHEMA notification        GRANT ALL ON TABLES TO dayflow_notification;
    ALTER DEFAULT PRIVILEGES FOR ROLE dayflow_analytics    IN SCHEMA analytics           GRANT ALL ON TABLES TO dayflow_analytics;
SQL

echo "Dayflow: database bootstrap complete."
