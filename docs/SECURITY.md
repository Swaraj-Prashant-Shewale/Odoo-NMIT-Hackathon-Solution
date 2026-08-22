# Security

What Dayflow defends against, how, and what it does not defend against.

An HRMS is a concentrated target: it holds salaries, bank details, home
addresses, dates of birth and performance records for everyone in a company,
and it grants some of those people power over the others. This document is
written so a reviewer can check the claims rather than take them on trust.

---

## 1. What we are protecting, and from whom

| Asset | Why it matters | Who would want it |
| --- | --- | --- |
| Credentials | Reused elsewhere; opens everything | Anyone |
| Salaries and structures | Deeply sensitive internally | Colleagues, recruiters |
| Bank and tax details | Directly monetisable | Criminals |
| Personal details | Identity theft, harassment | Outsiders, colleagues |
| Performance reviews | Career damage, disputes | The subject, their peers |
| Approval authority | Approve your own leave or expenses | Employees |
| The audit trail | Cover tracks after a misuse | Anyone who misused it |

The threat that gets least attention in HR software is the **honest-but-curious
insider**: someone with a legitimate account who changes an id in a URL to see a
colleague's payslip. Most of the controls below exist for that person rather
than for an anonymous attacker.

---

## 2. Authentication

### Passwords

- **Argon2id**, with bcrypt as the fallback where Argon2 is unavailable
  (`Security\Password`).
- Cost parameters come from the environment and can be raised over time;
  `needsRehash` upgrades a stored hash silently on the next successful sign-in.
- Policy: at least 10 characters, upper and lower case, a digit, not on a
  blocklist of common passwords, not containing the person's own name or email
  local part, no character repeated four times.
- `Password::generate()` produces a compliant password when an administrator
  creates an account, and the account is flagged `must_change_password`.

### Sign-in

Implemented in identity-service, and every point below is exercised by the
integration test:

- **No account enumeration.** Unknown address and wrong password return the same
  sentence. When the account does not exist, the password is still verified
  against a dummy hash, so the response time does not answer the question the
  message refused to.
- **Lockout** after `LOGIN_MAX_ATTEMPTS` failures for `LOGIN_LOCKOUT_SECONDS`.
- **Rate limiting** on both the client address and the targeted account, so
  neither a broad scan nor a distributed attempt on one account gets far.
- **Email verification** required before an account can be used.
- Every attempt is recorded, with the email address **hashed** in
  `login_attempts` and **masked** in the audit trail — the security log must not
  itself become a harvestable directory.

### Tokens

- **Access token**: JWT, HS256, 15 minutes, carrying `sub`, `employee_id`,
  `roles`, `iss`, `aud`, `exp`, `nbf`, `jti`.
- **Refresh token**: opaque random value, 7 days, stored only as a SHA-256 hash.
- The **algorithm is pinned** on verification. `alg: none` and the
  RS256-to-HS256 confusion attack both fail at the first check.
- Signatures are compared with `hash_equals`.
- Issuer and audience are verified, not merely present.

### Refresh rotation and theft detection

Refresh tokens rotate on every use. Presenting one that was already spent is
treated as theft — whichever party is genuine — and **the entire token family is
revoked**. A stolen refresh token therefore buys minutes, and the real owner
finds out because they are signed out once.

### Revocation

Signing out inserts the access token's `jti` into `identity.revoked_tokens`,
which the gateway checks on every call. Sign-out takes effect immediately rather
than up to fifteen minutes later.

---

## 3. Sessions in the browser

The web client is server-rendered, and **the tokens never leave the server**.

- The browser holds an opaque `HttpOnly`, `SameSite=Lax` session cookie and
  nothing else. A cross-site scripting flaw could not steal a credential the
  document never contained.
- `Secure` is set from `SESSION_SECURE_COOKIE`; turn it on with HTTPS.
- The session id is **regenerated on sign-in**, which defeats session fixation.
  Verified by test.
- Two independent lifetimes: an idle timeout and an absolute timeout.
- A weak fingerprint over user agent and accept-language detects a cookie
  replayed from a different browser. The client address is deliberately excluded
  — a mobile connection changes address legitimately, and signing such a person
  out repeatedly would be a bug rather than a protection.

---

## 4. Authorisation

### The model

Six roles — System Administrator, HR Administrator, HR Officer, Finance Officer,
People Manager, Employee — mapped to **named permissions**. Every check in every
service names a `Permissions::` constant. There are no role-string comparisons
scattered through the code.

**Inheritance is declared explicitly** rather than derived from seniority order.
A positional model would have handed the Finance Officer every permission of a
People Manager purely because Finance is listed above Manager. Finance inherits
from Employee only.

**Permissions are recomputed from the role catalogue** when a token is turned
into a `Principal`, never read from the token itself. A token minted before a
permission was withdrawn from a role cannot still carry it.

### Closed by default

Every route must declare one of `requires()`, `authenticated()` or
`allowPublic()`. There is no default. A route added without a decision does not
run.

### Permission is not ownership

These are two different questions and both are asked:

- *May this role read leave requests?* — the route's permission.
- *May this person read **this** leave request?* — the controller's check.

Every `authenticated()` route narrows its query by scope: everything for an
`*.all` holder, direct reports for a `*.team` holder, own records otherwise.

Where confirming a record exists is itself a disclosure — someone else's payslip
— the answer is **404 rather than 403**.

### Escalation is blocked

- Nobody may grant a role senior to their own (`Roles::outranks`).
- Nobody may change their own roles.
- Nobody may deactivate themselves.
- Registration offers only Employee and HR Officer.
- Nobody may approve their own leave, decide their own expense claim, verify
  their own document, or grade their own quiz.
- A payroll run must be approved by someone other than whoever processed it.

---

## 5. The data layer

### Injection

Every query is a bound prepared statement, with `ATTR_EMULATE_PREPARES` off so
they are prepared on the server. No user value is ever concatenated into SQL.

Identifiers cannot be bound as parameters, so `QueryBuilder` validates every
table and column name against `^[A-Za-z_][A-Za-z0-9_]*$` and quotes it, and
restricts operators to a fixed allow-list. A caller cannot smuggle SQL through a
column name or a sort direction.

`LIKE` searches escape `%`, `_` and `\` before binding, so a wildcard in a search
box is data rather than a wildcard.

### Mass assignment

Every repository declares `$fillable`. A field not listed there is dropped before
an `INSERT` or `UPDATE` is built. A request body containing `"status":"approved"`
or `"role":"super_admin"` cannot write those columns even if a controller
forwards the whole thing.

### Isolation enforced by PostgreSQL

Nine schemas, nine database logins, each granted rights on its own schema only.

This is the control we are most confident in, because it does not depend on
application code being correct. Verified directly:

```
payroll role → CREATE TABLE identity.x   → permission denied for schema identity
payroll role → DELETE FROM audit_log     → permission denied for table audit_log
```

Cross-service data is fetched over HTTP, carrying the original user's token so
the owning service applies its own rules. The analytics service — which draws
company-wide dashboards — has **no read access to any other schema at all**, and
therefore can never show a caller more than that caller is entitled to.

### Encryption at rest

Bank account numbers and tax identifiers are encrypted with **AES-256-GCM**.
They have to be reversible to run payroll, so hashing is not an option; GCM adds
tamper detection, so a modified ciphertext fails rather than decrypting to
something else. A keyed blind index supports uniqueness checks without
decrypting. **Only the last four digits are ever returned to anyone, including
HR.**

### Money

Integer minor units throughout, `BIGINT`. No float ever touches a payslip.

### The audit trail

Append-only, enforced by grant: services hold `INSERT` and `SELECT` on
`platform.audit_log` and nothing else. Credentials are redacted centrally before
anything is written, so a careless call site cannot log a password.

---

## 6. The application surface

### Cross-site scripting

- Every dynamic value in every template passes through `e()`.
- A **Content-Security-Policy** of `script-src 'self'` with **no inline
  JavaScript anywhere** in the product — no `onclick`, no `<script>` in a page.
  Behaviour is wired from data attributes in a static file. That is what allows
  the policy to be strict rather than decorative.
- `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'none'`.
- The single exception is `frame-src` for YouTube, needed for training videos.
  Lesson embeds are built from a **validated eleven-character video id**, never
  from a stored URL, so the iframe source cannot be steered anywhere else.
- Email templates escape every substituted value — an unescaped one would be
  stored XSS delivered to every recipient's inbox.

### Cross-site request forgery

Per-session tokens on every state-changing form, compared with `hash_equals`,
rotated on sign-in, expiring after two hours. Enforced centrally in the client
router, so a controller cannot forget. Verified by test: a forged token, a
missing token and another session's token are all rejected.

### Open redirects

Redirect destinations are forced to be paths within this application. An
absolute URL is discarded. This closes the "link that looks like your HR system
but lands on a credential-harvesting page" attack.

### File uploads

- Extension allow-list **and** real content sniffed with `finfo`, and the two
  must agree. A `.pdf` that is actually a ZIP is refused.
- Size capped at the container and the application.
- Stored under a generated UUID filename, **outside the web root**, reachable
  only through an authorised download endpoint that re-checks ownership.
- A SHA-256 checksum is recorded.

### Exports

CSV cells beginning `=`, `+`, `-`, `@`, tab or carriage return are prefixed with
a quote. Without that, opening an export in a spreadsheet executes the cell as a
formula — a real and frequently overlooked path from "employee typed something
into a field" to "code ran on the finance director's laptop".

### Email

Header values are stripped of CR and LF before being written into an SMTP
message, and recipients are validated. This is the classic mail header injection
flaw.

---

## 7. The network

- **Only two containers publish a port.** The nine services are unreachable from
  the host.
- Network placement is treated as a perimeter, not a guarantee. Every proxied
  call carries an **HMAC signature** over method, path, timestamp, nonce and a
  digest of the body, and services **refuse anything unsigned**. A process that
  found its way onto the internal network still could not call them.
- The timestamp bounds a replay window; the body digest stops a relayed request
  being edited in flight.
- `X-Forwarded-For` is honoured **only** from a configured trusted proxy.
  Accepting it from anyone would let a client spoof its own address and walk
  past the rate limiter.
- The gateway never follows redirects and is restricted to plain HTTP on the
  internal network, so a compromised service cannot steer it elsewhere.

---

## 8. The runtime

- `display_errors` off; errors are logged, never rendered. No stack trace, file
  path or query reaches a browser in production.
- `expose_php` off; server and PHP version banners suppressed.
- `exec`, `shell_exec`, `system`, `proc_open`, `popen`, `passthru` and `dl` are
  **disabled in php.ini**. The application never shells out, and removing the
  functions removes an entire class of remote code execution.
- Apache serves only `public/`; application code, migrations, seeds and uploads
  sit outside the document root. Dot-files and `.sql`, `.md`, `.yml`, `.log`,
  `.sh`, `.ini` are refused outright.
- Source is mounted **read-only**. The containers can write to exactly two
  places.
- Request body capped at 10 MB; `TRACE` disabled; only the verbs in use allowed.
- A shutdown handler guarantees a JSON error rather than an empty 200 body even
  on a fatal error.

---

## 9. Secrets

- Everything comes from the environment. Nothing is hard-coded.
- `.env` is git-ignored; `.env.example` contains only placeholders.
- Services refuse to start if `JWT_SECRET` or `INTERNAL_SIGNING_KEY` is missing
  or shorter than 32 characters — a misconfiguration fails loudly at startup
  rather than quietly at the first request that needed it.
- `scripts/generate-secrets` reads the OS cryptographic source. It preserves
  existing values unless forced, so re-running it does not sign everyone out.

---

## 10. What is verified, and how

`docs/` claims are checked by an integration test that drives the real API
through the gateway as real users. It asserts both that the product works and
that it refuses what it should:

- forged tokens, missing tokens, expired sessions
- an employee reading a colleague's record, payslip, salary, attendance, leave
- self-approval of leave
- privilege escalation via role grant and via registration
- weak passwords
- account enumeration
- SQL injection through a search field
- a script tag stored in a text field
- quiz answer keys in the payload served to a candidate

Database-level isolation is verified directly with `psql`.

---

## 11. Known limitations

Stated plainly, because a security document that claims completeness is not
credible.

**No multi-factor authentication.** The token and session model is built to
accept it. It is the first thing to add.

**No HTTPS in local development.** Set `SESSION_SECURE_COOKIE=true` and
terminate TLS at a reverse proxy before this is on a network. Without it the
session cookie crosses the wire in the clear.

**The development mailbox** at `/mailbox` lists every sent message, including
password reset links. It refuses to load when `APP_ENV=production` and requires
`system.settings`. Do not weaken either check.

**Demo accounts share a published password.** Set `SEED_DEMO_DATA=false` for
anything real.

**Rate limiting is per-instance and fixed-window.** Adequate here; a sliding
window in shared storage would be better under real load.

**No automatic data retention or purge.** Retention periods are a policy
decision the deployment has to make.

**An administrator can read almost everything.** That is inherent to the role.
The mitigation is the audit trail, not prevention — which is why it is
append-only and why it records every read of a payslip.

---

## 12. Reporting a problem

Do not open a public issue. Contact the maintainer directly with the affected
endpoint, what you observed, and what you expected.
