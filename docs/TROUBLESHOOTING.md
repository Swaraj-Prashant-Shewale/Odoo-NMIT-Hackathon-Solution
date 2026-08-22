# Troubleshooting

Things that go wrong, and what to do about them. Written so that someone who is
not a developer can work through it.

---

## Before anything else

Run this. It answers most questions on its own:

```bash
docker compose ps
```

Every row should say `running` and, for the application containers, `healthy`.

| What you see | What it means |
| --- | --- |
| `running (healthy)` | Fine. |
| `running (starting)` | Still booting. Give it up to a minute on a first run. |
| `running (unhealthy)` | The container is up but failing its own check. Read its log. |
| `restarting` | It crashes on startup and Docker keeps retrying. Read its log. |
| `exited (1)` | It stopped with an error. Read its log. |

To read a log:

```bash
docker compose logs web
docker compose logs identity-service
docker compose logs --tail=50 gateway
docker compose logs -f leave-service     # follow it live
```

---

## Startup problems

### "missing required configuration: JWT_SECRET INTERNAL_SIGNING_KEY"

Your `.env` file has empty values that must be filled in.

```powershell
powershell -File scripts\generate-secrets.ps1     # Windows
bash scripts/generate-secrets.sh                   # macOS / Linux
```

Then `docker compose up` again.

### "JWT_SECRET must be at least 32 characters"

Something set it to a short value by hand. Run the generator with `-Force`
(PowerShell) or `--force` (bash) to replace it with a proper one.

> Replacing `JWT_SECRET` signs everyone out. That is expected and harmless.

### "Cannot connect to the Docker daemon"

Docker Desktop is not running. Start it, wait for the whale icon to settle, and
try again.

### "port is already allocated"

Something else on your machine is using 8000, 8080 or 5432 — very often an
existing PostgreSQL installation on 5432.

Change the port in `.env`:

```ini
WEB_PORT=8100
GATEWAY_PORT=8180
POSTGRES_PORT=55432
```

Then `docker compose down` and `docker compose up`.

### The build fails while installing packages

Usually a network hiccup during `apt-get`. Try again:

```bash
docker compose build --no-cache
```

### It worked yesterday and today nothing starts

```bash
docker compose down
docker compose up --build
```

If that does not help, and you do not mind losing the data:

```bash
docker compose down -v
docker compose up
```

---

## Database problems

### A service logs "Unable to establish a database connection"

On a first run, PostgreSQL takes a few seconds to become ready. Services wait
and retry for thirty seconds, so this normally resolves itself.

If it persists:

```bash
docker compose logs postgres
```

Look for `database system is ready to accept connections`. If you see
`FATAL: password authentication failed`, your `.env` was changed after the
database was first created. The passwords are set once, when the data directory
is initialised. Either put the old password back, or reset the database:

```bash
docker compose down -v
docker compose up
```

### "permission denied for schema …"

A service is trying to reach a schema that is not its own. That is the isolation
working as intended — it is a bug in the service, not a configuration problem.
The log line names the schema and the service.

### I edited a migration and nothing changed

Migrations run once and are recorded in `<schema>.schema_migrations`. Editing an
applied file has no effect.

- **While developing:** `docker compose down -v && docker compose up` to start
  from scratch.
- **Anywhere else:** add a new numbered migration that makes the change.

### I want to look at the data

```bash
docker compose --profile tools up -d adminer
```

Then open <http://localhost:8081>:

| Field | Value |
| --- | --- |
| System | PostgreSQL |
| Server | `postgres` |
| Username | `dayflow_owner` |
| Password | `POSTGRES_PASSWORD` from your `.env` |
| Database | `dayflow` |

---

## Signing in

### "Those credentials do not match our records"

- The password is wrong, or
- the account does not exist, or
- the account is locked.

The message is deliberately the same in all three cases, so that the sign-in
form cannot be used to discover who has an account.

If you are locked out, wait for `LOGIN_LOCKOUT_SECONDS` (fifteen minutes by
default), or clear it directly:

```bash
docker compose exec postgres psql -U dayflow_owner -d dayflow \
  -c "UPDATE identity.users SET failed_login_count = 0, locked_until = NULL WHERE email = 'you@company.com';"
```

### "Please verify your email address before signing in"

Open the development inbox. Sign in as an administrator and go to `/mailbox`, or
look in `storage/mail/` for the HTML files.

To verify an account directly:

```bash
docker compose exec postgres psql -U dayflow_owner -d dayflow \
  -c "UPDATE identity.users SET email_verified_at = NOW() WHERE email = 'you@company.com';"
```

### I forgot the administrator password

Set `SEED_ADMIN_PASSWORD` in `.env` to a new value and restart the identity
service. Its seed resets the administrator's password to whatever is configured,
so an operator can never be locked out of their own deployment:

```bash
docker compose restart identity-service
```

### I am signed out every few minutes

`SESSION_IDLE_TIMEOUT` is thirty minutes by default. If it is happening faster
than that, check that your browser is accepting cookies from `localhost`, and
that `SESSION_SECURE_COOKIE` is `false` while you are on plain HTTP — a secure
cookie is never sent over an unencrypted connection, so the session appears to
vanish on every request.

---

## Pages and data

### A dashboard card says "unavailable"

One service behind that card is not answering. The rest of the page still works,
which is the intended behaviour.

```bash
curl http://localhost:8080/health
```

That reports every service. Restart whichever is unreachable:

```bash
docker compose restart learning-service
```

### "You do not have access to this"

Your role does not include that permission. Sign in as an administrator and open
`/admin/roles` to see the full matrix of who may do what.

### There is no data anywhere

`SEED_DEMO_DATA` is `false`, or the database was created while it was. Set it to
`true` in `.env` and reset:

```bash
docker compose down -v
docker compose up
```

### "Your session expired while that form was open"

The CSRF token on the page was older than two hours. Reload the page and submit
again. If it happens constantly, the browser is not keeping the session cookie.

### A page is blank or shows "Something went wrong"

```bash
docker compose logs --tail=100 web
```

With `APP_DEBUG=true` the page itself shows the file and line.

---

## Uploads and files

### "That file type is not accepted"

Uploads are restricted to PDF, JPG, PNG and DOCX, and the real content of the
file must match its extension. A `.pdf` that is actually a ZIP is refused on
purpose.

### "The file is too large"

`MAX_UPLOAD_BYTES` in `.env`, five megabytes by default. Note that the container
also caps a whole request at ten megabytes.

### Uploaded files vanish after a restart

They should not — `./storage` is a bind mount. Check that the folder exists and
is writable, and that you did not run `docker compose down -v` (which removes
volumes, though not bind mounts).

---

## Email

### No email arrives

Expected while `MAIL_DRIVER=log`. Messages are written to `storage/mail/` and
listed at `/mailbox` for administrators.

### Real email will not send

Set `MAIL_DRIVER=smtp` and fill in the host, port, username, password and
encryption. Then:

```bash
docker compose restart notification-service
docker compose logs -f notification-service
```

Failures are recorded per message in `notification.email_outbox.last_error`.

---

## Performance

### Everything is slow

- On Windows, Docker Desktop's file sharing is slow for bind mounts. Allocating
  more memory to Docker (Settings → Resources) helps noticeably.
- The dashboard makes several service calls to assemble one screen. That is the
  cost of the microservice split, and it is documented in
  [ARCHITECTURE.md](ARCHITECTURE.md).

### One service uses a lot of memory

`memory_limit` is 256 MB per container. A single request approaching that is a
bug — usually an unpaginated query. The log names the endpoint.

---

## Starting completely over

```bash
docker compose down -v          # stop everything, delete the database
docker system prune -f          # reclaim space (optional)
docker compose up --build       # rebuild and start fresh
```

Everything is recreated: schemas, roles, tables, reference data and, if
`SEED_DEMO_DATA=true`, the demo company.

---

## Still stuck

Collect this before asking anyone for help:

```bash
docker compose ps                    > diagnostics.txt
docker compose logs --tail=200      >> diagnostics.txt
docker version                      >> diagnostics.txt
```

Then remove anything sensitive from the file — **`diagnostics.txt` may contain
values from `.env`** — before sending it on.
