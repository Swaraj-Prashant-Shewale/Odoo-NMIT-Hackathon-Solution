# Deployment

Taking Dayflow from a laptop to a server that other people use.

---

## Before you start

Local development and a real deployment differ in ways that matter. Work through
this list; every item is a genuine exposure if skipped.

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Every secret regenerated — never reuse a development key
- [ ] `SESSION_SECURE_COOKIE=true`, behind real HTTPS
- [ ] `SEED_DEMO_DATA=false`
- [ ] The `ports:` mapping removed from the `postgres` service
- [ ] The `adminer` service left out (it is already behind a profile)
- [ ] `MAIL_DRIVER=smtp` with working credentials
- [ ] `APP_URL` and `CORS_ALLOWED_ORIGINS` set to the real address
- [ ] `REQUIRE_GATEWAY_SIGNATURE=true`
- [ ] A backup of the database volume, and a restore you have actually tested

---

## 1. Server

Modest requirements. Everything is small.

| | Minimum | Comfortable |
| --- | --- | --- |
| CPU | 2 cores | 4 cores |
| Memory | 4 GB | 8 GB |
| Disk | 20 GB | 50 GB SSD |
| OS | Anything running Docker Engine 24+ | Ubuntu 22.04 LTS |

```bash
# Ubuntu
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker "$USER"
newgrp docker
docker compose version
```

---

## 2. Get the code and configure it

```bash
git clone <your-repository-url> dayflow
cd dayflow

cp .env.example .env
bash scripts/generate-secrets.sh --admin-email hr@yourcompany.com
```

Then edit `.env` for production:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hr.yourcompany.com
APP_TIMEZONE=Asia/Kolkata

SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=https://hr.yourcompany.com

SEED_DEMO_DATA=false

MAIL_DRIVER=smtp
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=hr@yourcompany.com
MAIL_FROM_NAME=Your Company HR

# The reverse proxy is the only thing allowed to assert a client address.
TRUSTED_PROXIES=172.16.0.0/12
```

```bash
chmod 600 .env
```

---

## 3. Close the database off

In `docker-compose.yml`, delete the `ports:` block from the `postgres` service:

```yaml
  postgres:
    image: postgres:16-alpine
    # ports:                    <- remove these two lines
    #   - "5432:5432"
```

PostgreSQL stays reachable from the other containers on the internal network,
and from nowhere else. Reach it for maintenance with
`docker compose exec postgres psql …`.

---

## 4. Terminate TLS in front of it

Only the web client needs to be public. The gateway does not: the client reaches
it over the internal network.

Remove the gateway's port mapping too, and put a reverse proxy in front.

<details>
<summary><b>Caddy</b> — obtains and renews certificates automatically</summary>

```caddyfile
hr.yourcompany.com {
    reverse_proxy localhost:8000

    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        -Server
    }

    encode gzip
    log {
        output file /var/log/caddy/dayflow.log
    }
}
```

</details>

<details>
<summary><b>Nginx</b> with Certbot</summary>

```nginx
server {
    listen 443 ssl http2;
    server_name hr.yourcompany.com;

    ssl_certificate     /etc/letsencrypt/live/hr.yourcompany.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hr.yourcompany.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    client_max_body_size 12M;

    location / {
        proxy_pass         http://127.0.0.1:8000;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name hr.yourcompany.com;
    return 301 https://$host$request_uri;
}
```

</details>

> `TRUSTED_PROXIES` must contain the proxy's address. Dayflow ignores
> `X-Forwarded-For` from anything not listed there, so that a client cannot
> spoof its own address and walk past the rate limiter.

---

## 5. Start it

```bash
docker compose up -d --build
docker compose ps
curl -s http://localhost:8080/health | jq
```

Wait until every service reports `healthy`. The first start also creates the
schemas, applies every migration and seeds the reference data.

Sign in with the administrator credentials from `.env`, then:

1. Change that password.
2. Create the real departments, designations and locations.
3. Adjust the leave types to your actual policy.
4. Add the year's holiday calendar.
5. Create the employee records.

---

## 6. Backups

The database is the only thing that must survive. Uploaded files in `./storage`
matter too.

```bash
#!/usr/bin/env bash
# /usr/local/bin/dayflow-backup
set -euo pipefail

DIR=/var/backups/dayflow
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$DIR"

cd /opt/dayflow

docker compose exec -T postgres \
    pg_dump -U dayflow_owner -d dayflow --format=custom \
    > "$DIR/dayflow-$STAMP.dump"

tar -czf "$DIR/storage-$STAMP.tar.gz" storage/

# Keep a month.
find "$DIR" -name 'dayflow-*.dump'    -mtime +30 -delete
find "$DIR" -name 'storage-*.tar.gz'  -mtime +30 -delete
```

```bash
sudo chmod +x /usr/local/bin/dayflow-backup
sudo crontab -e
# 0 2 * * * /usr/local/bin/dayflow-backup >> /var/log/dayflow-backup.log 2>&1
```

Restore:

```bash
docker compose stop
docker compose start postgres

docker compose exec -T postgres \
    pg_restore -U dayflow_owner -d dayflow --clean --if-exists \
    < /var/backups/dayflow/dayflow-20260801-020000.dump

docker compose start
```

> A backup you have never restored is not a backup. Test it on a copy.

---

## 7. Updating

```bash
cd /opt/dayflow
/usr/local/bin/dayflow-backup          # always first

git pull
docker compose build
docker compose up -d
docker compose ps
curl -s http://localhost:8080/health | jq
```

New migrations apply automatically as each service starts. Because they run
under an advisory lock and are recorded once, a rolling restart is safe.

Rolling back:

```bash
git checkout <previous-tag>
docker compose up -d --build
```

> Migrations are forward-only. If a release added a column, rolling the code back
> is fine; rolling the *schema* back needs a new migration that reverses it.

---

## 8. Keeping an eye on it

### Health

`GET /health` on the gateway reports the gateway, the database and all nine
services. Point your uptime monitor at it and alert on anything but
`"status": "healthy"`.

### Logs

Every container writes structured JSON, one object per line:

```bash
docker compose logs -f --tail=100
docker compose logs identity-service | grep '"level":"error"'
```

Ship them somewhere with a retention policy. Credentials are redacted centrally
before anything is written, but logs still contain names and email addresses and
should be treated as personal data.

Cap the log size so a disk cannot fill:

```yaml
x-php-service: &php-service
  logging:
    driver: json-file
    options:
      max-size: "10m"
      max-file: "5"
```

### The audit trail

Distinct from logs, and the thing an auditor will ask for. It lives in
`platform.audit_log`, is append-only, and is exposed at `/admin/audit`.

It grows steadily. Archive rather than delete:

```sql
CREATE TABLE platform.audit_log_2026 AS
SELECT * FROM platform.audit_log WHERE occurred_at < '2027-01-01';
```

---

## 9. Scaling up

Services are stateless, so they scale horizontally as they are:

```bash
docker compose up -d --scale attendance-service=3 --scale leave-service=2
```

The internal Docker DNS load-balances between replicas automatically.

Beyond one host, the compose file maps closely onto Kubernetes. Two things need
real attention before that step:

1. **Uploads** must move from a bind mount to shared object storage.
2. **Analytics** should read from a replica rather than the primary.

PostgreSQL tuning worth doing early on a busy instance:

```ini
shared_buffers = 25% of RAM
effective_cache_size = 75% of RAM
work_mem = 16MB
max_connections = 200
```

---

## 10. Where the sensitive data is

Useful when someone asks what would be exposed if a particular thing were
compromised.

| Data | Where | Protection |
| --- | --- | --- |
| Passwords | `identity.users.password_hash` | Argon2id. Not reversible. |
| Refresh tokens | `identity.refresh_tokens.token_hash` | SHA-256 of the token. The plaintext is never stored. |
| Bank accounts | `payroll.bank_accounts` | AES-256-GCM. Only the last four digits are ever returned. |
| Tax identifiers | `payroll.bank_accounts` | AES-256-GCM. |
| Salaries | `payroll.salary_structures` | Plain, but reachable only with `payroll.view.all` or by the person themselves. |
| Personal details | `employee.employees` | Plain. Guarded by permission and by ownership checks. |
| Documents | `storage/uploads/documents` | Outside the web root, generated filenames, served only through an authorised endpoint. |
| Audit trail | `platform.audit_log` | Append-only by database grant. Credentials redacted before writing. |

If a service's database credentials leak, that role can reach **its own schema
only**. A leaked payroll credential cannot read attendance data, and vice versa.

---

## 11. Compliance notes

Not legal advice, but a starting point for the questions that get asked.

**Right of access.** Everything about one person is retrievable through their
`employee_id` across the nine schemas.

**Right to erasure.** In tension with statutory payroll retention, which is
typically several years. The workable approach is to anonymise the person record
while keeping the financial records that must legally be kept.

**Retention.** No automatic purge is implemented. Decide your periods and add a
scheduled job; the audit trail archive above is the pattern.

**Access records.** Every read of a payslip and every change to a record is in
the audit trail with the actor, the address and the time.
