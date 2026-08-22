# Dayflow Architecture

How the system is put together, why it is put together that way, and what
happens when someone clicks something.

---

## 1. The shape of it

Dayflow is a **client / server split** where the server is **nine
microservices** behind a single **API gateway**.

```mermaid
graph TB
    Browser["🌐 Browser"]

    subgraph Published["Published to the host machine"]
        Web["<b>Web client</b> :8000<br/>server-rendered pages<br/>Bootstrap 5 · no database"]
        GW["<b>API gateway</b> :8080<br/>authentication · rate limits<br/>routing · CORS"]
    end

    subgraph Internal["Private container network — unreachable from outside"]
        ID["identity"]
        EMP["employee"]
        ATT["attendance"]
        LV["leave"]
        PAY["payroll"]
        LRN["learning"]
        TAL["talent"]
        NTF["notification"]
        ANA["analytics"]
    end

    DB[("<b>PostgreSQL 16</b><br/>one schema per service<br/>one login per service")]

    Browser -->|"HTTPS · session cookie"| Web
    Web -->|"Bearer token"| GW
    GW -->|"signed · token forwarded"| ID
    GW --> EMP
    GW --> ATT
    GW --> LV
    GW --> PAY
    GW --> LRN
    GW --> TAL
    GW --> NTF
    GW --> ANA

    ANA -.->|"asks over HTTP"| EMP
    ANA -.-> ATT
    ANA -.-> LV
    ANA -.-> PAY
    PAY -.->|"days worked"| ATT
    PAY -.->|"unpaid leave"| LV
    LV  -.->|"holiday calendar"| ATT

    ID --> DB
    EMP --> DB
    ATT --> DB
    LV --> DB
    PAY --> DB
    LRN --> DB
    TAL --> DB
    NTF --> DB
    ANA --> DB

    style Browser fill:#e8f1ff,stroke:#1c77fd
    style Web fill:#fff,stroke:#1c77fd,stroke-width:2px
    style GW fill:#fff,stroke:#1c77fd,stroke-width:2px
    style DB fill:#f5f7fb,stroke:#5a6a85
```

Solid arrows are the request path. Dotted arrows are one service asking another
for something it does not own.

### Why microservices, honestly

The usual argument is scaling, and that does apply — payroll is busy for two
days a month while attendance is busy twice a day, every day. But the reasons
that matter most here are different:

**Each part can be understood on its own.** The payroll calculation is a few
hundred lines in one place, not a few hundred lines scattered through a much
larger program.

**Access control is enforced by the database, not by discipline.** Each service
logs into PostgreSQL with its own role, granted rights on its own schema only.
The payroll service *cannot* read attendance rows. That is a far stronger
statement than "no payroll code currently reads attendance rows", and it stays
true no matter what anyone writes later.

**A fault stays where it happened.** If the learning service stops, people can
still clock in and apply for leave; one card on the dashboard goes quiet.

The cost is real: a network hop where a function call would do, and data that
has to be assembled from several places. The dashboard endpoint is the clearest
example — it makes several calls to build one screen. That trade is made
deliberately and is documented at the call sites.

---

## 2. The layers

```mermaid
graph LR
    subgraph L1["1 · Presentation"]
        direction TB
        V["Views<br/><i>templates, all output escaped</i>"]
        C["Controllers<br/><i>read input, call the API, render</i>"]
    end

    subgraph L2["2 · Edge"]
        direction TB
        G["Gateway<br/><i>verify · throttle · route · sign</i>"]
    end

    subgraph L3["3 · Application"]
        direction TB
        SC["Service controllers<br/><i>validate, authorise, respond</i>"]
        SS["Domain services<br/><i>the business rules</i>"]
    end

    subgraph L4["4 · Data"]
        direction TB
        R["Repositories<br/><i>mass-assignment guard</i>"]
        Q["QueryBuilder<br/><i>everything bound</i>"]
    end

    subgraph L5["5 · Storage"]
        direction TB
        PG[("PostgreSQL<br/><i>constraints · roles · grants</i>")]
    end

    L1 --> L2 --> L3 --> L4 --> L5

    style L1 fill:#e8f1ff,stroke:#1c77fd
    style L2 fill:#fff4e5,stroke:#d97706
    style L3 fill:#e7f7ed,stroke:#16a34a
    style L4 fill:#f3e8ff,stroke:#8b5cf6
    style L5 fill:#f5f7fb,stroke:#5a6a85
```

A rule worth stating plainly: **each layer re-checks what matters rather than
trusting the one above it.**

The web client hides a menu item you may not use. The gateway rejects a call
without a valid token. The service checks the permission again, and then checks
whether *this particular record* is yours. The repository refuses to write a
column that was not declared writable. The database refuses a status value
outside its `CHECK` constraint.

Any one of those could be bypassed by a mistake. All five failing at once is
what it would take for something to go wrong.

---

## 3. The classes that matter

### The shared kernel

Every service is built on the same foundation, so that a security decision is
made once rather than nine times.

```mermaid
classDiagram
    direction TB

    class Kernel {
        +migrate() void
        +routes(callable) void
        +run() void
        -assertGatewayOrigin(Request) void
        -authenticate(Request) Principal
    }

    class Router {
        -routes array
        +get(pattern, handler) RouteDefinition
        +post(pattern, handler) RouteDefinition
        +match(method, path) array
    }

    class RouteDefinition {
        +requires(permission) self
        +authenticated() self
        +allowPublic() self
    }

    class Request {
        +method string
        +path string
        +clientIp string
        +principal() Principal
        +input(key, default) mixed
        +bearerToken() ?string
    }

    class Response {
        +ok(data, meta)$ Response
        +created(data)$ Response
        +page(page)$ Response
        +error(status, code, msg)$ Response
        +download(bytes, name, mime)$ Response
        +send() void
    }

    class Principal {
        +userId string
        +employeeId ?string
        +roles array
        +permissions array
        +managerId ?string
        +can(permission) bool
        +owns(employeeId) bool
    }

    class Repository {
        #table string
        #fillable array
        #hidden array
        +find(id) ?array
        +create(attributes) array
        +update(id, attributes) ?array
        +paginate(builder, page) array
    }

    class QueryBuilder {
        +where(col, op, value) self
        +whereIn(col, values) self
        +orderBy(col, dir) self
        +get() array
        +count() int
    }

    class Validator {
        +make(data, rules)$ Validator
        +validated() array
        +errors() array
    }

    Kernel --> Router : resolves
    Kernel --> Request : builds
    Kernel --> Principal : verifies token into
    Router --> RouteDefinition : returns
    Request --> Principal : carries
    Repository --> QueryBuilder : builds queries with
```

`Kernel` is the important one. It is why every service front controller is four
lines: the kernel runs the migrations, verifies the gateway signature, verifies
the token, enforces the route's permission and shapes every error. A service
author cannot forget those steps, because they are not their job.

### Security

```mermaid
classDiagram
    direction LR

    class Jwt {
        +issue(claims, ttl)$ string
        +verify(token)$ array
    }

    class Password {
        +hash(plain)$ string
        +verify(plain, hash)$ bool
        +needsRehash(hash)$ bool
        +problems(plain, context)$ array
    }

    class Roles {
        +SUPER_ADMIN
        +HR_ADMIN
        +HR_OFFICER
        +FINANCE
        +MANAGER
        +EMPLOYEE
        +permissionsFor(role)$ array
        +outranks(a, b)$ bool
        +matrix()$ array
    }

    class Permissions {
        +LEAVE_APPROVE
        +PAYROLL_VIEW_ALL
        +...
        +groups()$ array
    }

    class RateLimiter {
        +hit(key, limit, window)$ array
        +clear(key, window)$ void
    }

    class InternalSignature {
        +headers(method, path, body)$ array
        +verify(method, path, body, headers)$ bool
    }

    class Encryptor {
        +encrypt(plaintext)$ string
        +decrypt(payload)$ ?string
        +blindIndex(value)$ string
    }

    class AuditLog {
        +record(request, action, subject, ...)$ void
        +recordAuthEvent(request, action, ...)$ void
    }

    Roles --> Permissions : grants
    Principal --> Roles : resolves permissions through
    Jwt --> Principal : verified claims become
```

Two design decisions worth calling out.

**Role inheritance is declared, not derived.** It would be tempting to order the
roles by seniority and let each one inherit everything beneath it. That would
quietly hand the Finance Officer every permission of a People Manager, because
Finance happens to sit above Manager in the list. Inheritance is therefore an
explicit map, and Finance inherits from Employee only.

**Permissions are recomputed, never trusted from the token.** `Principal` reads
the *roles* from the verified token but looks the *permissions* up in the role
catalogue. A token issued before a permission was withdrawn from a role cannot
still carry it.

### The web client

```mermaid
classDiagram
    direction TB

    class Router {
        +get(pattern, handler) RouteOptions
        +post(pattern, handler) RouteOptions
        +dispatch() void
    }

    class Session {
        -_access_token
        -_refresh_token
        -_user
        +authenticate(access, refresh, ttl, user)$ void
        +accessToken()$ ?string
        +can(permission)$ bool
        +destroy()$ void
    }

    class Api {
        +get(path, query)$ array
        +post(path, payload)$ array
        +login(email, password)$ array
        +stream(path, query)$ bool
        -renew()$ bool
    }

    class Csrf {
        +token()$ string
        +field()$ string
        +verify(submitted)$ bool
        +rotate()$ void
    }

    class View {
        +render(template, data)$ void
        +renderAuth(template, data)$ void
        +partial(template, data)$ void
    }

    class Controller {
        #input(key, default) string
        #redirect(path) never
        #guard(response) void
    }

    Router --> Session : refuses anonymous access
    Router --> Csrf : rejects unsigned posts
    Router --> Controller : dispatches to
    Controller --> Api : asks for data
    Controller --> View : renders through
    Api --> Session : reads and renews tokens
```

The single most important property here: **`Session` holds the tokens, so the
browser never does.** The browser gets an opaque `HttpOnly` cookie. A
cross-site scripting flaw in a template could not steal a credential the
document never contained.

---

## 4. What happens when someone applies for leave

The clearest end-to-end illustration, because it touches authentication,
authorisation, validation, a cross-service call, a transaction and an event.

```mermaid
sequenceDiagram
    autonumber
    actor P as Priya
    participant W as Web client
    participant G as Gateway
    participant L as leave-service
    participant A as attendance-service
    participant DB as PostgreSQL
    participant N as notification-service

    P->>W: submits the leave form
    W->>W: verify CSRF token against the session
    W->>W: read access token from server-side session
    W->>G: POST /leave/requests + Bearer token

    G->>G: verify signature, expiry, issuer, audience
    G->>DB: is this token id revoked?
    G->>G: apply rate limits
    G->>G: is /leave a published prefix?
    G->>L: forward, signed with the internal key

    L->>L: verify the gateway signature
    L->>L: verify the token independently
    L->>L: check permission leave.apply
    L->>L: validate every field
    Note over L: employee_id comes from the TOKEN,<br/>never from the submitted form

    L->>A: GET /holidays?year=2026
    A-->>L: the holiday calendar
    L->>L: count working days, minus weekends and holidays

    L->>DB: does this overlap an existing request?
    alt overlaps
        L-->>G: 409 Conflict
        G-->>W: 409
        W-->>P: "You already have leave booked on those dates"
    end

    L->>DB: is the balance sufficient?
    alt not enough days
        L-->>G: 422 with the shortfall
        G-->>W: 422
        W-->>P: "You have 3 days available, this request is for 5"
    end

    rect rgb(232, 241, 255)
        Note over L,DB: one transaction — all of it, or none of it
        L->>DB: INSERT the leave request
        L->>DB: reserve the days on the balance
        L->>DB: INSERT the audit entry
        L->>DB: INSERT into the event outbox
    end

    L-->>G: 201 Created
    G-->>W: 201
    W-->>P: redirect · "Your request has been submitted"

    Note over L,N: after the response is already on the wire
    L->>N: POST /events · leave.request.submitted
    N->>N: render the template, escaping every value
    N->>DB: write the in-app notification
    N->>DB: queue the email
```

**Steps 12 to 15 are the point.** Four writes, one transaction. A leave request
cannot exist without its reserved days, its audit entry, and its queued
notification. If any one of them fails, none of them happened.

**Step 24 onwards happens after the browser already has its answer.** The person
is not kept waiting while an email is composed, and a notification service that
is briefly down delays a message rather than blocking an approval.

---

## 5. Signing in

```mermaid
sequenceDiagram
    autonumber
    actor U as User
    participant W as Web client
    participant G as Gateway
    participant I as identity-service
    participant E as employee-service
    participant DB as PostgreSQL

    U->>W: email + password
    W->>G: POST /auth/login
    G->>G: strict rate limit — 10 attempts per 5 minutes per address
    G->>I: forward, signed

    I->>DB: find the account by lowercased email

    alt no such account
        I->>I: verify against a dummy hash anyway
        Note over I: so the response time does not<br/>reveal whether the address exists
        I->>DB: record the failed attempt (email hashed)
        I-->>U: "Those credentials do not match our records"
    end

    I->>I: is the account locked?
    I->>I: Password::verify

    alt wrong password
        I->>DB: increment the failure count
        alt count reached the limit
            I->>DB: lock the account
            I->>I: publish identity.account.locked
        end
        I-->>U: the same generic message
    end

    I->>I: is the email verified? is the account active?

    rect rgb(231, 247, 237)
        Note over I,E: successful sign-in
        I->>DB: reset counters, record last_login_at and address
        I->>I: rehash the password if the cost settings have risen
        I->>E: GET /employees/by-user/{id}
        E-->>I: employee_id, department_id, manager_id
        I->>I: issue a 15-minute access token
        I->>DB: store the hash of a new 7-day refresh token
        I->>DB: write the audit entry
    end

    I-->>G: tokens + profile
    G-->>W: tokens + profile
    W->>W: regenerate the session id · store tokens server-side · rotate CSRF
    W-->>U: redirect to the dashboard
```

The failure branches all end in the same sentence on purpose. A sign-in form
that distinguishes "no such account" from "wrong password" is a directory of
who works at the company, available to anyone.

---

## 6. Refresh token rotation, and how theft is caught

Access tokens last fifteen minutes. Refresh tokens last a week, and **rotate on
every use**: presenting one returns a new one and marks the old one spent.

That rotation is what makes theft detectable.

```mermaid
sequenceDiagram
    autonumber
    participant V as Victim
    participant T as Attacker
    participant I as identity-service

    Note over V,T: the attacker has somehow obtained refresh token R1

    T->>I: refresh with R1
    I->>I: R1 is unused — accept
    I-->>T: new access token + R2 (R1 now marked spent)

    Note over V: meanwhile the victim's session tries to renew

    V->>I: refresh with R1
    I->>I: R1 was already spent

    rect rgb(253, 234, 234)
        Note over I: a spent token being presented again is theft,<br/>whichever party is the genuine one
        I->>I: revoke the ENTIRE token family
        I->>I: audit the reuse
        I-->>V: 401 — sign in again
    end

    T->>I: refresh with R2
    I->>I: R2 belongs to the revoked family
    I-->>T: 401 — the stolen session is dead
```

The victim is signed out once and signs back in. The attacker's stolen session
is destroyed. Without rotation, a stolen refresh token would work quietly for a
week.

---

## 7. How a payroll run assembles itself

The most cross-service operation in the product.

```mermaid
sequenceDiagram
    autonumber
    actor F as Finance officer
    participant P as payroll-service
    participant A as attendance-service
    participant L as leave-service
    participant E as employee-service
    participant DB as PostgreSQL

    F->>P: POST /payroll/runs/{id}/process
    P->>P: check permission payroll.run
    P->>DB: is the run still a draft?

    P->>E: GET /employees?status=active
    E-->>P: the employee list

    rect rgb(232, 241, 255)
        Note over P,DB: ONE transaction for the entire run
        loop for each employee
            P->>DB: the salary structure effective this period
            P->>A: attendance summary for the month
            A-->>P: present, absent, half days, overtime
            P->>L: approved leave for the month
            L-->>P: paid and unpaid days
            P->>P: payable days = working days − loss of pay
            P->>P: pro-rate earnings, apply each component
            P->>P: compute tax from the slab table
            P->>DB: write the payslip and its lines
        end
        P->>DB: recompute the run totals
    end

    alt any employee failed
        P->>DB: roll back the whole run
        P-->>F: 500 — nothing was written
    end

    P-->>F: the run, with totals

    Note over F,P: approving and publishing are separate steps
    F->>P: POST /payroll/runs/{id}/approve
    P->>P: the approver must differ from whoever processed it
    F->>P: POST /payroll/runs/{id}/publish
    P->>P: publish every payslip, notify every employee
```

**An all-or-nothing run is the point.** A payroll run that half-succeeded would
leave some people paid and others not, with no clear record of which. Rolling
back the entire run and reporting the failure is the only defensible behaviour.

**Processing, approving and publishing are three separate actions**, and the
approver must be someone other than the person who processed it. That is
ordinary separation of duties, and it is the control that makes payroll
auditable.

---

## 8. Data ownership

Each service owns its schema outright. Nothing reaches across.

```mermaid
erDiagram
    USERS ||--o{ USER_ROLES : "has"
    USERS ||--o{ REFRESH_TOKENS : "issues"
    USERS |o..o| EMPLOYEES : "user_id (no FK — different schema)"

    EMPLOYEES ||--o{ EMPLOYEE_DOCUMENTS : "holds"
    EMPLOYEES ||--o{ ONBOARDING_TASKS : "works through"
    EMPLOYEES }o--|| DEPARTMENTS : "belongs to"
    EMPLOYEES }o--|| DESIGNATIONS : "holds"
    EMPLOYEES }o--o| EMPLOYEES : "reports to"

    EMPLOYEES |o..o{ ATTENDANCE_RECORDS : "employee_id"
    ATTENDANCE_RECORDS ||--o{ ATTENDANCE_PUNCHES : "rolls up"

    EMPLOYEES |o..o{ LEAVE_REQUESTS : "employee_id"
    LEAVE_REQUESTS }o--|| LEAVE_TYPES : "of type"
    LEAVE_BALANCES }o--|| LEAVE_TYPES : "tracks"

    EMPLOYEES |o..o{ SALARY_STRUCTURES : "employee_id"
    SALARY_STRUCTURES ||--o{ SALARY_STRUCTURE_LINES : "made of"
    PAYROLL_RUNS ||--o{ PAYSLIPS : "produces"
    PAYSLIPS ||--o{ PAYSLIP_LINES : "itemises"

    COURSES ||--o{ LESSONS : "contains"
    COURSES ||--o{ ENROLMENTS : "enrols"
    ENROLMENTS ||--o{ LESSON_PROGRESS : "tracks"
```

Solid lines are real foreign keys, inside one schema. **Dotted lines are plain
UUID columns with no foreign key** — the referenced table is in another schema
that this database role cannot see. That is not an oversight; it is what service
independence costs, and what it buys.

The consequence: when the attendance service needs someone's name, it asks the
employee service. There is exactly one place a name is stored, so there is
exactly one place to correct it.

---

## 9. Events, and why they use an outbox

A naive implementation posts a notification straight after the approval. If the
notification call fails, the approval is already committed and nobody is told.
If the approval is rolled back after the notification is sent, someone is told
about something that did not happen.

The **transactional outbox** removes both failures:

```mermaid
graph LR
    subgraph TX["One database transaction"]
        A["approve the request"] --> B["update the balance"] --> C["write the audit entry"] --> D["INSERT into event_outbox"]
    end

    TX --> R["response to the browser"]
    R --> F["flush the outbox"]
    F --> N["notification-service"]
    N --> OK{"delivered?"}
    OK -->|yes| M["mark delivered"]
    OK -->|no| RETRY["increment attempts<br/>retry on the next flush"]

    style TX fill:#e8f1ff,stroke:#1c77fd
    style RETRY fill:#fdf3e3,stroke:#d97706
```

The event is written **in the same transaction as the change that produced it**.
It therefore cannot exist for a change that was rolled back, and it cannot be
missing for a change that was committed. Delivery happens afterwards and retries
until it succeeds.

The receiving side records every event id it has processed, so a retry that
arrives twice produces one notification.

---

## 10. Where the boundaries were drawn, and why

| Service | Why it is separate |
| --- | --- |
| **identity** | Credentials and tokens are the most sensitive thing here. Keeping them in one small service means the code that touches a password hash is a few hundred lines that can be read closely. |
| **employee** | The single system of record for a person. If a name lived in two places, they would disagree within a month. |
| **attendance** | The highest write volume in the system — several punches per person per day — and the only part with a real-time element. |
| **leave** | Balances and accrual are genuinely intricate rules that change with company policy, and they change independently of everything else. |
| **payroll** | The most sensitive data and the strictest correctness requirement. Separating it means the set of things that can read a salary is small and explicit. |
| **learning** | Almost entirely independent of the rest. It exists because it is a real HR need, and it costs nothing to isolate. |
| **talent** | Review visibility rules (who may see an unsubmitted review, an anonymous reviewer) are subtle enough to deserve their own place. |
| **notification** | The only service that talks to the outside world by email. One outbound path is one thing to secure. |
| **analytics** | Read-only and aggregating. It has no data of its own, which is exactly why it is safe for it to see across everything — it sees only what the asking user is entitled to. |

### Things deliberately *not* split

- **Attendance and timesheets** stay together: they share the same records and
  would need a chatty conversation if separated.
- **Payroll and expenses** stay together: both are money owed to an employee and
  both end in a payment run.
- **Goals and reviews** stay together: a review is largely an assessment of
  goals, and separating them would mean fetching one to render the other.

---

## 11. Deployment topology

```mermaid
graph TB
    subgraph Host["Docker host"]
        subgraph Pub["Published ports"]
            W["web :8000"]
            G["gateway :8080"]
            AD["adminer :8081<br/><i>development only</i>"]
        end

        subgraph Net["dayflow-internal — bridge network"]
            S1["identity"]
            S2["employee"]
            S3["attendance"]
            S4["leave"]
            S5["payroll"]
            S6["learning"]
            S7["talent"]
            S8["notification"]
            S9["analytics"]
            PG[("postgres")]
        end

        subgraph Vol["Volumes"]
            V1["dayflow-db<br/><i>database files</i>"]
            V2["dayflow-logs"]
            V3["./storage<br/><i>uploads · mail</i>"]
        end
    end

    W --> G
    G --> S1 & S2 & S3 & S4 & S5 & S6 & S7 & S8 & S9
    S1 & S2 & S3 & S4 & S5 & S6 & S7 & S8 & S9 --> PG
    PG --> V1

    style Pub fill:#e8f1ff,stroke:#1c77fd
    style Net fill:#f5f7fb,stroke:#5a6a85
    style Vol fill:#e7f7ed,stroke:#16a34a
```

All eleven PHP containers run the **same image**, differing only in which source
directory is mounted and which environment variables they receive. One image to
build, one to patch, and no possibility of one service running an older runtime
than another.

Source is mounted **read-only**. The containers can write to exactly two places:
`/var/www/storage` and `/var/log/dayflow`.

### Scaling from here

`docker compose up --scale attendance-service=3` works as-is, because services
hold no state between requests. Everything that persists is in PostgreSQL.

Beyond one host: the compose file translates to Kubernetes manifests almost
directly. The pieces that would need attention first are a shared object store
for uploads instead of a bind mount, and a read replica for analytics.

---

## 12. What we would change with more time

Being honest about the seams:

- **The dashboard makes many calls.** It is the one endpoint where the
  microservice split is visibly expensive. A materialised summary table, updated
  by the events that already exist, would fix it.
- **Events are delivered by polling an outbox after each response.** That is
  reliable and easy to reason about, but a message broker would be a better fit
  once the volume grows.
- **`analytics` has no cache beyond sixty seconds.** For monthly reports that
  never change once a period closes, a longer-lived snapshot table is the
  obvious next step.
- **No multi-factor authentication.** The token model is ready for it; the
  second factor is not implemented.
