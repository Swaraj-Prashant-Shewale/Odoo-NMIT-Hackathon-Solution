<div align="center">

# Dayflow

### Human Resource Management System

**Every workday, perfectly aligned.**

*Attendance · Time off · Payroll · People · Learning · Performance*

</div>

---

## What is this, in plain language?

Dayflow is the software a company uses to run everything to do with its people.

Think of it as replacing a stack of spreadsheets and a long email chain:

- **Employees** clock in and out, apply for leave, look at their payslips, read
  their training courses and track their goals — all in one place.
- **Managers** see who is in today, approve their team's leave and expenses, and
  run performance reviews.
- **HR** keeps the employee records, the org chart, the holiday calendar and the
  leave policies, and can see the whole picture on a dashboard.
- **Finance** builds salary structures, runs payroll each month and reimburses
  expense claims.

Everyone sees exactly what their job requires, and nothing more. Every change is
recorded, permanently, so you can always answer "who changed this, and when?"

### What you can actually do with it

| Area | What it does |
| --- | --- |
| **Sign in** | Create an account, verify your email, reset a forgotten password. Accounts lock themselves after repeated wrong passwords. |
| **Dashboard** | A home screen that changes depending on who you are — an employee sees their own day, HR sees the whole company. |
| **Attendance** | Check in and check out. Daily, weekly and monthly views. Shifts, rosters, holidays, overtime, work-from-home, and a way to fix a missed punch. |
| **Time off** | Apply for leave, see your balance update live, and watch the request move through approval. Multiple leave types with real accrual and carry-forward rules. |
| **Payroll** | Salary structures, monthly payroll runs, downloadable payslips as PDF, salary revision history, and expense claims with reimbursement. |
| **People** | Employee records, departments, designations, locations, a reporting org chart, a document vault with expiry alerts, and onboarding checklists. |
| **Learning** | A course catalogue built from training videos, with progress tracking, quizzes, certificates and mandatory-training compliance reporting. |
| **Performance** | Goals with weightings and progress, review cycles with self and manager reviews, competency ratings, and continuous feedback. |
| **Reports** | A dozen ready-made reports, exportable to CSV or PDF, plus charts on every dashboard. |
| **Administration** | Manage users and roles, organisation structure, leave policies, shifts, holidays, company settings, and read the complete audit trail. |

---

## Running it on your own machine

### What you need

Just **[Docker Desktop](https://www.docker.com/products/docker-desktop/)**.

You do **not** need PHP, PostgreSQL, XAMPP, Composer or Node installed. Docker
provides all of it, correctly configured, in containers.

### Three steps

**1. Start Docker Desktop** and wait for it to say it is running.

**2. Open a terminal in this folder and set up your configuration:**

<table>
<tr><th align="left">Windows (PowerShell)</th></tr>
<tr><td>

```powershell
copy .env.example .env
powershell -File scripts\generate-secrets.ps1
```

</td></tr>
<tr><th align="left">macOS / Linux</th></tr>
<tr><td>

```bash
cp .env.example .env
bash scripts/generate-secrets.sh
```

</td></tr>
</table>

That creates a `.env` file with strong random keys. It also asks you for the
first administrator's email and password.

**3. Start everything:**

```bash
docker compose up
```

The first run takes a few minutes while Docker downloads and builds the images.
Afterwards it starts in seconds.

### Then open it

| What | Address |
| --- | --- |
| **The application** | **http://localhost:8000** |
| API gateway health | http://localhost:8080/health |
| Database browser *(optional)* | http://localhost:8081 |

Sign in with the administrator email and password you set in step 2.

> The database browser is off by default. Start it with
> `docker compose --profile tools up -d adminer`.

### Everyday commands

```bash
docker compose up                  # start, showing logs in the terminal
docker compose up -d               # start in the background
docker compose down                # stop everything
docker compose logs -f web         # follow one service's log
docker compose ps                  # see what is running and whether it is healthy
docker compose restart leave-service
```

To wipe the database and start completely fresh:

```bash
docker compose down -v
docker compose up
```

> **Careful:** `-v` deletes the database volume. Everything is recreated and
> reseeded from scratch.

### Trying the different roles

If `SEED_DEMO_DATA=true` in your `.env` (the default), the system creates a
small demo company: twelve people across six departments, with attendance
history, leave requests, payslips, courses and goals.

All demo accounts use the password **`Dayflow@2026`**.

| Sign in as | Email | You will see |
| --- | --- | --- |
| HR Administrator | `meera.iyer@dayflow.local` | Full HR view: everyone's records, policies, org settings |
| HR Officer | `rahul.deshmukh@dayflow.local` | Day-to-day HR: approvals, onboarding, employee records |
| Finance | `sneha.kulkarni@dayflow.local` | Payroll runs, salary structures, expense reimbursement |
| People Manager | `arjun.nair@dayflow.local` | A team view with approvals for direct reports |
| Employee | `priya.sharma@dayflow.local` | The self-service view most people get |

Set `SEED_DEMO_DATA=false` before the first start for a clean, empty system with
only your administrator account.

---

## The shape of the system

Dayflow is built as **microservices**: instead of one large program, it is nine
small ones, each owning a single part of the business. They never read each
other's data directly — they ask each other over the network, the same way an
outside application would.

```
                        ┌──────────────────────┐
     Your browser  ───► │   Web client :8000   │   Server-rendered pages.
                        │   (Bootstrap 5 UI)   │   Holds no data of its own.
                        └──────────┬───────────┘
                                   │  every request carries an access token
                        ┌──────────▼───────────┐
                        │   API gateway :8080  │   The single front door.
                        │  auth · rate limits  │   Verifies the token once,
                        │  routing · CORS      │   signs the call, forwards it.
                        └──────────┬───────────┘
                                   │  private network — nothing else can reach in
   ┌───────────┬───────────┬───────┼───────┬───────────┬───────────┬───────────┐
   ▼           ▼           ▼       ▼       ▼           ▼           ▼           ▼
┌────────┐┌─────────┐┌──────────┐┌──────┐┌────────┐┌─────────┐┌────────┐┌──────────┐
│Identity││Employee ││Attendance││Leave ││Payroll ││Learning ││Talent  ││Notifica- │
│        ││         ││          ││      ││        ││         ││        ││tion      │
└───┬────┘└────┬────┘└────┬─────┘└──┬───┘└───┬────┘└────┬────┘└───┬────┘└────┬─────┘
    │          │          │         │        │          │         │          │
    │      ┌───────────┐  │         │        │          │         │          │
    │      │ Analytics │◄─┴─────────┴────────┴──────────┴─────────┴──────────┘
    │      └─────┬─────┘     asks the others over HTTP; stores no copy
    │            │
    ▼            ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                          PostgreSQL 16                                       │
│  identity │ employee │ attendance │ leave_management │ payroll │ learning    │
│  talent   │ notification │ analytics │ platform (audit trail, rate limits)   │
│                                                                              │
│  One schema per service. One database login per service, granted rights on   │
│  ONLY its own schema — isolation enforced by the database, not by good       │
│  intentions in the application code.                                         │
└──────────────────────────────────────────────────────────────────────────────┘
```

### The nine services

| Service | Owns | Schema |
| --- | --- | --- |
| **identity** | Accounts, sign-in, tokens, roles, permissions, audit trail, settings | `identity` |
| **employee** | People records, departments, designations, locations, org chart, documents, onboarding, assets | `employee` |
| **attendance** | Check-in/out, shifts, rosters, holidays, corrections, timesheets, overtime | `attendance` |
| **leave** | Leave types and policies, balances and accrual, requests, approval chains, delegation | `leave_management` |
| **payroll** | Salary structures, pay components, tax slabs, payroll runs, payslips, expense claims | `payroll` |
| **learning** | Course catalogue, video lessons, enrolments, progress, quizzes, certificates | `learning` |
| **talent** | Goals, review cycles, competency ratings, feedback | `talent` |
| **notification** | In-app notifications, email, announcements, templates | `notification` |
| **analytics** | Dashboards, reports, exports | `analytics` |

### Why split it up?

- **Each part can be understood on its own.** Payroll is about six hundred lines
  of business logic, not six hundred lines buried in sixty thousand.
- **A fault stays contained.** If the learning service stops, people can still
  clock in and apply for leave; only the training card on the dashboard goes
  quiet.
- **They can be scaled separately.** Payroll is busy for two days a month;
  attendance is busy twice a day, every day.
- **Access is enforced by the database.** Because each service logs in with its
  own PostgreSQL role, the payroll service *cannot* read attendance data even if
  someone asked it to. That is a much stronger guarantee than a code review.

### How the pieces talk to each other

- **Synchronously** for things needed right now: the payroll service asks the
  attendance service how many days someone worked, over HTTP, carrying the
  original user's token so the attendance service applies its own rules.
- **Asynchronously** for things that can happen a moment later: when leave is
  approved, the leave service records a `leave.request.decided` event in an
  outbox table *in the same database transaction as the approval*. After the
  response has gone back to the browser, the event is delivered to the
  notification service. An approval can therefore never be committed without its
  notification being queued, and a notification can never be sent for an
  approval that was rolled back.

---

## How a request actually flows

Take the most common thing anyone does — applying for leave.

```
 1. Priya fills in the form at /leave/apply and submits it.

 2. Web client
      · checks the CSRF token matches her session
      · reads her access token from the server-side session
        (the token is never in the page, never in JavaScript, never in a cookie)
      · POSTs to the gateway

 3. API gateway
      · verifies the token's signature, expiry, issuer and audience
      · checks the token has not been revoked by a sign-out
      · applies rate limits
      · confirms /leave is a published path
      · signs the call with the internal key and forwards it

 4. Leave service
      · confirms the signature — it refuses anything not from the gateway
      · re-verifies the token independently
      · confirms the route's permission, leave.apply
      · validates every submitted field
      · takes employee_id from the TOKEN, never from the form
      · asks the attendance service which days are holidays
      · counts working days, excluding weekends and holidays
      · refuses if the dates overlap an existing request
      · refuses if the balance is insufficient
      · in one transaction: writes the request, reserves the days,
        records the audit entry, and queues the event
      · returns the created record

 5. Notification service (a moment later, after the response was sent)
      · receives leave.request.submitted
      · looks up the approver's details
      · writes an in-app notification and queues an email

 6. Priya sees her request listed as Pending, with her balance already updated.
    Arjun sees it in his approval queue with a badge in the navigation bar.
```

---

## The main classes, and what they are for

You do not need to read the code to use Dayflow, but if you are reviewing it,
this is the map.

### The shared kernel — `server/shared/src/`

Every service is built on these. They are written once so that security
decisions are made once.

| Class | Responsibility |
| --- | --- |
| `Http\Kernel` | Boots a service, runs migrations, verifies the gateway signature and the token, enforces the route's permission, shapes errors. Every service is four lines because this holds the rest. |
| `Http\Router` | The route table. Every route must declare `requires()`, `authenticated()` or `allowPublic()` — access is closed by default. |
| `Http\Request` | Read-only view of the incoming request. Also decides the real client address, honouring `X-Forwarded-For` only from a trusted proxy. |
| `Http\Response` | The single response envelope, plus the security headers on every reply. |
| `Http\ServiceClient` | One service calling another: signs the call, carries the user's token forward. |
| `Database\Connection` | The PostgreSQL connection. Sets the service's schema, retries while the database is still starting, and never emulates prepared statements. |
| `Database\QueryBuilder` | Safe query construction. Values are always bound; table and column names are validated against a strict pattern; operators come from a fixed list. |
| `Database\Repository` | Base class for data access. `$fillable` is the mass-assignment guard: a field not listed there can never be written from a request. |
| `Database\Migrator` | Applies `.sql` migrations on boot, exactly once, under an advisory lock so two starting containers cannot collide. **This is why the tables create themselves.** |
| `Security\Jwt` | Signs and verifies access tokens. The algorithm is pinned, so the "none" algorithm attack and RS256-to-HS256 confusion both fail. |
| `Security\Password` | Argon2id hashing, the strength policy, and rehashing on sign-in as the cost settings rise. |
| `Security\Roles` | The six roles and what each one may do. Inheritance is declared explicitly rather than derived from seniority, so a specialist role cannot silently inherit rights it was never meant to have. |
| `Security\Permissions` | The complete catalogue of named permissions. Every authorisation check names one of these. |
| `Security\Principal` | The authenticated caller. Permissions are recomputed from the role catalogue rather than trusted from the token, so a stale token cannot carry a withdrawn permission. |
| `Security\RateLimiter` | Request throttling, shared between the gateway and the identity service. |
| `Security\InternalSignature` | Proves a request came through the gateway. Signs method, path, timestamp, nonce and a digest of the body. |
| `Security\Encryptor` | AES-256-GCM for the few fields that must be stored reversibly — bank account numbers, tax identifiers. |
| `Validation\Validator` | Declarative input rules. Returns a clean array containing only the fields you declared. |
| `Audit\AuditLog` | The append-only trail. Redacts credentials centrally so a careless call site cannot log a password. |
| `Events\EventPublisher` | The transactional outbox and its delivery. |
| `Pdf\PdfDocument` | A small PDF writer for payslips, certificates and report exports. |

### Inside a service — `server/services/<name>-service/`

| Folder | What lives there |
| --- | --- |
| `public/index.php` | Four lines: boot, migrate, register routes, run. |
| `routes.php` | Every endpoint and the permission it requires. **Read this file first** to understand a service. |
| `app/Controllers/` | One per resource. Validate, check ownership, act, audit, respond. |
| `app/Models/` | One per table. Declares which columns may be written. |
| `app/Services/` | Business rules too substantial for a controller — the payroll calculator, the leave-balance engine. |
| `app/Policies/` | Authorisation decisions more involved than a single permission. |
| `database/migrations/` | Numbered `.sql` files, applied in order, exactly once. |
| `database/seeds/seed.php` | Reference and demo data. Safe to run repeatedly. |

### The web client — `client/web/`

| Class | Responsibility |
| --- | --- |
| `Core\Router` | Page routing. Refuses anonymous access and rejects any form post without a valid CSRF token. |
| `Core\Session` | The server-side session. **Holds the tokens so the browser never does.** Enforces both an idle and an absolute timeout. |
| `Core\Api` | The only thing in the client that touches the network. Renews an expired token and retries, transparently. |
| `Core\Csrf` | Per-session tokens, compared in constant time, rotated on sign-in. |
| `Core\View` | Renders templates. Resolves paths so a view name can never escape the views directory. |
| `Core\helpers.php` | `e()`, `money()`, `badge()` and friends. `e()` is the escaping every template uses on every dynamic value. |
| `Core\Controller` | Base class. Its `redirect()` refuses any destination outside this site, which closes the open-redirect hole. |

---

## Security

Security was a requirement here, not an afterthought. This is what is actually
implemented.

### Getting in

- Passwords hashed with **Argon2id** (bcrypt where Argon2 is unavailable), and
  rehashed automatically as the cost settings are raised.
- A password policy with a length floor, character requirements, a common-password
  blocklist, and a rule against using your own name or email.
- **Email verification** required before an account can be used.
- **Account lockout** after repeated failures, plus rate limiting on both the
  address and the account being targeted.
- **No account enumeration.** Sign-in, registration, password reset and
  verification all return the same response whether or not the address exists —
  and sign-in verifies against a dummy hash when the account is unknown, so the
  response time does not give it away either.
- Every attempt, successful or not, is written to the audit trail with the email
  address masked.

### Staying in

- **Short-lived access tokens** (15 minutes) with **rotating refresh tokens**
  (7 days).
- **Refresh token reuse detection**: if a token that was already spent is
  presented again, that is theft, so the entire token family is revoked at once.
- **Immediate revocation** on sign-out — the gateway checks a revocation list, so
  signing out takes effect now rather than when the token happens to expire.
- **Tokens never reach the browser.** They live in the server-side session; the
  browser holds only an opaque `HttpOnly`, `SameSite=Lax` cookie. A cross-site
  scripting flaw could not steal a credential the page never contained.
- Session id regenerated on sign-in (defeats session fixation), with both an idle
  and an absolute lifetime.

### What you are allowed to do

- **Six roles** — System Administrator, HR Administrator, HR Officer, Finance
  Officer, People Manager, Employee — mapped to **named permissions**, not to
  role-string checks scattered through the code.
- **Closed by default.** A route with no declared permission does not run.
- **Ownership is checked separately from permission.** "May see leave requests"
  and "may see *this* leave request" are two different questions, and both are
  asked.
- **No privilege escalation.** Nobody can grant a role senior to their own, and
  nobody can change their own roles.
- **No self-approval.** You cannot approve your own leave, decide your own
  expense claim, or grade your own quiz.
- Registration offers only Employee and HR Officer. Elevated roles are granted by
  an existing administrator or not at all.

### The data

- **Every** query is a bound prepared statement. No user value is ever
  concatenated into SQL.
- Mass assignment is blocked by an explicit `$fillable` list per table.
- **Schema isolation enforced by PostgreSQL**: nine database logins, each with
  rights on its own schema only.
- The audit trail is **append-only** — services are granted `INSERT` and
  `SELECT`, and nothing else, so an entry cannot be altered or erased.
- Bank account numbers and tax identifiers are encrypted with **AES-256-GCM**,
  and only the last four digits are ever returned to anyone, including HR.
- Money is stored in integer minor units, so no rounding error can appear in a
  payslip.

### The application surface

- **Content-Security-Policy** forbidding external script; the only exception is
  YouTube as a frame source for training videos.
- Every dynamic value in every template escaped with `e()`.
- **CSRF tokens** on every state-changing form, compared in constant time.
- **No inline JavaScript anywhere** — no `onclick` attributes, no `<script>`
  blocks in pages — so the policy above can be strict.
- **Open redirects closed**: redirect destinations are forced to be paths within
  this site.
- **File uploads**: extension allow-list *and* real content sniffed with `finfo`,
  with the two required to agree; size capped; stored under a generated name,
  outside the web root, reachable only through an authorised download endpoint.
- **CSV exports neutralise formula injection** — a cell starting with `=`, `+`,
  `-` or `@` is escaped so a spreadsheet does not execute it.
- Email templates escape every substituted value, and header values are stripped
  of line breaks to prevent header injection.
- Errors are logged, never displayed. No stack trace, file path or query ever
  reaches a browser in production.

### The network

- **Only two containers publish a port.** The nine services are unreachable from
  outside Docker.
- That is treated as a perimeter, not a guarantee: every proxied call is
  **HMAC-signed** over its method, path, timestamp, nonce and body digest, and
  services refuse anything unsigned. A process that got onto the internal network
  still could not call them.
- Rate limiting at the gateway, with a much tighter allowance on authentication.
- Security headers on every response; `PHP` version and server banner suppressed;
  shell-execution functions disabled in the runtime entirely.

### Deliberate limitations

Being straight about what is *not* here:

- **HTTPS is not configured for local development.** Set `SESSION_SECURE_COOKIE=true`
  and terminate TLS at a reverse proxy before putting this on a network.
- **No multi-factor authentication yet.** The token and session model is built to
  accept it; the second factor itself is not implemented.
- **The development mailbox** at `/mailbox` shows every sent message, including
  password reset links. It refuses to load when `APP_ENV=production`, but do not
  weaken that check.
- **The demo accounts share a published password.** Set `SEED_DEMO_DATA=false`
  for anything real.

---

## Configuration

Everything is set in `.env`. Nothing is hard-coded, and no secret is in the
repository — `.env` is git-ignored.

The values you must set are marked `REQUIRED` in `.env.example`;
`scripts/generate-secrets` fills in the cryptographic ones for you.

Before deploying anywhere real:

```ini
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SEED_DEMO_DATA=false
MAIL_DRIVER=smtp
```

…then regenerate every secret, and remove the `ports:` mapping from the
`postgres` service in `docker-compose.yml` so the database is not exposed.

---

## Project layout

```
dayflow/
├── README.md                    you are here
├── docker-compose.yml           the whole stack in one file
├── .env.example                 configuration template
│
├── client/
│   └── web/                     the browser-facing application
│       ├── public/              document root: index.php + assets
│       ├── routes.php           every page in the product
│       └── app/
│           ├── Core/            session, CSRF, API client, view engine
│           ├── Controllers/     one per area of the product
│           └── Views/           templates (layouts, partials, pages)
│
├── server/
│   ├── gateway/                 the API front door
│   ├── shared/                  the kernel every service is built on
│   └── services/                the nine microservices
│
├── db/init/                     runs once: schemas, roles, grants
├── docker/php/                  the shared PHP runtime image
├── docs/                        architecture, data model, API, deployment
├── scripts/                     setup and maintenance helpers
└── storage/                     uploads, logs, the local mail outbox
```

## Further reading

| Document | What it covers |
| --- | --- |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The design in depth, with diagrams |
| [`docs/DOMAIN-MODEL.md`](docs/DOMAIN-MODEL.md) | Shared identifiers, enumerations, events |
| [`docs/SERVICE-CONTRACT.md`](docs/SERVICE-CONTRACT.md) | The rules every service follows |
| [`docs/API.md`](docs/API.md) | Every endpoint, with examples |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Tables and relationships |
| [`docs/SECURITY.md`](docs/SECURITY.md) | The threat model and each control |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Taking it to a server |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | When something will not start |

---

## When something goes wrong

**Nothing loads at localhost:8000**

```bash
docker compose ps       # is everything "running" and "healthy"?
docker compose logs web
```

**A service says it cannot reach the database**

The database takes a few seconds to accept connections on a first run. Services
wait for it and retry. If it persists, `docker compose logs postgres`.

**"missing required configuration" on startup**

Your `.env` is incomplete. Run the secret generator, or fill in every value
marked `REQUIRED` in `.env.example`.

**Port 8000, 8080 or 5432 is already in use**

Change `WEB_PORT`, `GATEWAY_PORT` or `POSTGRES_PORT` in `.env` and start again.

**I changed a migration and it did not apply**

Migrations run once and are recorded. Add a new numbered file rather than
editing an old one — or, during development, `docker compose down -v` to reset
the database entirely.

**Where do the verification and password reset emails go?**

Nowhere, while `MAIL_DRIVER=log`. Sign in as an administrator and open
`/mailbox`, or look in `storage/mail/`.

More detail in [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md).
