# Dayflow Service Contract

Every microservice in this platform is built the same way. This document is the
specification: follow it exactly and services stay interchangeable, testable and
safe to review.

---

## 1. Directory layout

Each service lives at `server/services/<name>-service/` and looks like this:

```
<name>-service/
├── public/
│   └── index.php            front controller (4 lines, identical everywhere)
├── routes.php               route table, returns a callable
├── app/
│   ├── Controllers/         one class per resource, methods return Response
│   ├── Models/              one Repository subclass per table
│   ├── Services/            business rules that do not belong in a controller
│   └── Policies/            authorisation decisions beyond a single permission
└── database/
    ├── migrations/          0001_*.sql, 0002_*.sql … applied in filename order
    └── seeds/
        └── seed.php         idempotent reference and demo data
```

## 2. Front controller — copy verbatim

`public/index.php`:

```php
<?php

declare(strict_types=1);

require (getenv('DAYFLOW_SHARED') ?: __DIR__ . '/../../shared') . '/bootstrap.php';

dayflow_autoload_app('App', __DIR__ . '/../app');

$kernel = new Dayflow\Kernel\Http\Kernel(__DIR__ . '/..');
$kernel->migrate();
$kernel->routes(require __DIR__ . '/../routes.php');
$kernel->run();
```

The application namespace is always `App\`. So `app/Controllers/LeaveController.php`
declares `namespace App\Controllers;` and `class LeaveController`.

## 3. Routes

`routes.php` returns a closure that receives the `Router`:

```php
<?php

declare(strict_types=1);

use App\Controllers\LeaveController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $leave = new LeaveController();

    $router->get('/leave/requests',      [$leave, 'index'])->authenticated();
    $router->post('/leave/requests',     [$leave, 'store'])->requires(Permissions::LEAVE_APPLY);
    $router->get('/leave/requests/{id}', [$leave, 'show'])->authenticated();
    $router->post('/leave/requests/{id}/approve', [$leave, 'approve'])->requires(Permissions::LEAVE_APPROVE);
};
```

Rules:

- **`->requires(Permissions::X)`** — the kernel rejects the call with 403 unless
  the principal holds that permission. Use this whenever a single permission is
  the whole answer.
- **`->authenticated()`** — a verified principal is required, and the controller
  performs a finer check (for example "own record, or `*.view.all`").
- **`->allowPublic()`** — no token. Only sign-in, sign-up, verification, password
  reset and health may use this.
- Every route MUST call exactly one of the three. There is no default.
- Path prefixes must match what `server/gateway/app/RouteTable.php` publishes for
  this service. If you need a new prefix, it has to be added there too.

## 4. Controllers

```php
namespace App\Controllers;

use App\Models\LeaveRequests;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Validation\Validator;

final class LeaveController
{
    private LeaveRequests $requests;

    public function __construct()
    {
        $this->requests = new LeaveRequests();
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'leave_type_id' => 'required|uuid',
            'starts_on'     => 'required|date',
            'ends_on'       => 'required|date|after_or_equal:starts_on',
            'reason'        => 'nullable|safe_text|max:1000',
        ])->validated();

        $record = $this->requests->create($data + [
            'employee_id' => $request->principal()->employeeId,
            'status'      => 'pending',
        ]);

        AuditLog::record($request, 'leave.request.created', 'leave_request', $record['id'], [], $record);

        return Response::created($record);
    }
}
```

Non-negotiables:

- A controller method takes exactly one `Request` and returns a `Response`.
- **Never** pass `$request->all()` into a repository. Always run it through
  `Validator` first and use the returned array. The validator drops every field
  you did not declare.
- Throw `HttpException::notFound()`, `::forbidden()`, `::conflict()` and friends
  rather than returning error payloads by hand.
- Record every state change with `AuditLog::record(...)`.
- Publish a domain event for anything another service cares about:
  `EventPublisher::publish('leave.request.approved', ['employee_id' => ..., ...])`.

## 5. Ownership checks

A permission answers "may this role do this kind of thing". It does not answer
"may this person touch this particular record". Whenever a route is
`->authenticated()`, the controller must decide scope explicitly:

```php
$principal = $request->principal();

if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
    // HR sees everything
} elseif ($principal->can(Permissions::LEAVE_VIEW_TEAM)) {
    // manager sees direct reports only
    $builder->whereIn('employee_id', $this->teamMemberIds($principal));
} else {
    // everyone else sees only their own
    $builder->where('employee_id', '=', $principal->employeeId);
}
```

Never trust an `employee_id` that arrived in the request body. Read it from
`$request->principal()->employeeId` unless the caller holds an `*.all`
permission, and even then verify the target record exists.

## 6. Models

```php
namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeaveRequests extends Repository
{
    protected string $table = 'leave_requests';
    protected string $primaryKey = 'id';

    /** Only these columns may ever be written from request data. */
    protected array $fillable = [
        'employee_id', 'leave_type_id', 'starts_on', 'ends_on',
        'day_count', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected array $casts = ['day_count' => 'float'];

    protected bool $softDeletes = false;
}
```

- `$fillable` is the mass-assignment guard. `status` belongs there only if a
  controller legitimately sets it — and the controller must set it from its own
  logic, never from `$request`.
- Anything sensitive (`password_hash`, tokens) goes in `$hidden`.
- Hand-written SQL goes through `raw()` / `rawOne()` / `execute()` with bound
  parameters. **Never** interpolate a value into a query string.

## 7. Migrations

Files are `database/migrations/0001_description.sql`, applied in filename order
and recorded so they run exactly once. Tables are created unqualified — the
connection's `search_path` already points at this service's schema.

```sql
CREATE TABLE IF NOT EXISTS leave_requests (
    id             UUID PRIMARY KEY,
    employee_id    UUID        NOT NULL,
    leave_type_id  UUID        NOT NULL REFERENCES leave_types (id),
    starts_on      DATE        NOT NULL,
    ends_on        DATE        NOT NULL,
    day_count      NUMERIC(5,2) NOT NULL DEFAULT 0,
    status         TEXT        NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','approved','rejected','cancelled')),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT leave_dates_ordered CHECK (ends_on >= starts_on)
);

CREATE INDEX IF NOT EXISTS leave_requests_employee_idx ON leave_requests (employee_id, starts_on DESC);
```

Rules:

- Every statement is `IF NOT EXISTS` or otherwise re-runnable.
- Primary keys are `UUID`, never sequential integers — a sequential id in a URL
  tells an attacker how many records exist and lets them walk the range.
- Put real `CHECK` constraints on status columns and real `FOREIGN KEY`s inside
  your own schema. Cross-service references are plain UUID columns with no
  foreign key, because the other table lives in a schema this role cannot see.
- Money is stored as `BIGINT` in minor units (paise), never as a float.
- Always `created_at` and `updated_at` as `TIMESTAMPTZ`.
- Index every column you filter or sort on.

## 8. Seeds

`database/seeds/seed.php` runs on every boot, so it must be idempotent — check before
inserting. Guard demo data behind `Env::bool('SEED_DEMO_DATA', true)`; reference
data (leave types, pay components, permission catalogue) seeds unconditionally.

```php
<?php
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Env;

$exists = Connection::pdo()->query("SELECT 1 FROM leave_types LIMIT 1")->fetchColumn();
if ($exists !== false) {
    return;
}
// … insert reference rows …
```

## 9. Calling another service

Services never read another service's tables — the database role physically
cannot. Use HTTP, carrying the caller's token so the far side applies its own
rules:

```php
use Dayflow\Kernel\Http\ServiceClient;

$employee = ServiceClient::for('employee', $request->bearerToken())
    ->tryGet('/employees/' . $employeeId);
```

Use `tryGet()` when the call is decoration (a department name) and the page
should still render if it fails. Use `get()`/`post()` when the call is essential
and its failure should surface.

## 10. Response envelope

Always via the `Response` factories, never `echo`:

| Situation            | Call                                        |
| -------------------- | ------------------------------------------- |
| One record           | `Response::ok($record)`                     |
| Created a record     | `Response::created($record)`                |
| A page of records    | `Response::page($repo->paginate($qb, $page))` |
| Deleted something    | `Response::noContent()`                     |
| A generated file     | `Response::download($bytes, $name, $mime)`  |

## 11. Security checklist for every endpoint

Before considering an endpoint done, confirm all of it:

- [ ] The route declares `requires()`, `authenticated()` or `allowPublic()`.
- [ ] Input goes through `Validator` and only the validated array is used.
- [ ] Record-level ownership is checked, not just the permission.
- [ ] No user value is concatenated into SQL.
- [ ] The response contains no password hash, token, or another person's data.
- [ ] State changes are written to the audit trail.
- [ ] Uploads check real MIME type via `finfo`, cap size, and store outside the
      web root under a generated filename.
- [ ] Errors do not disclose whether a record or account exists when that is
      itself sensitive (sign-in, password reset).

## 12. Style

- `declare(strict_types=1);` at the top of every PHP file.
- `final class` unless it is genuinely meant to be extended.
- Typed properties, typed parameters, typed returns.
- Comments explain *why*, never *what*. No commented-out code, no `TODO`s left
  behind, no placeholder implementations.
- British or American spelling is fine, but be consistent within a file.
