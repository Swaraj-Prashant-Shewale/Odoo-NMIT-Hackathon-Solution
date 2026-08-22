<?php

declare(strict_types=1);

use App\Models\UserRoles;
use App\Models\Users;
use App\Services\SettingSchema;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Security\Password;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * Identity seed.
 *
 * Two things are seeded unconditionally: the company defaults every other
 * service reads, and the administrator account named in the environment.
 * Without the second one a fresh deployment has no way in at all.
 *
 * Demo people come from DemoCohort in the shared kernel, the single place the
 * platform agrees on who exists and what identifier each of them has. Eight
 * other services seed records pointing at these people, so an identifier
 * invented here would leave the rest of the demo referring to somebody who is
 * not there.
 *
 * How often the kernel loads a seed is its own business, so nothing here
 * assumes it runs rarely. Every step either checks before it writes or is a
 * single conditional statement that does nothing once the database is already
 * in the state it describes.
 */

$users = new Users();
$userRoles = new UserRoles();
$pdo = Connection::pdo();

// ---------------------------------------------------------------------------
// Company defaults
// ---------------------------------------------------------------------------

$defaults = SettingSchema::defaults();

/**
 * The shared platform tables are provisioned by the database bootstrap rather
 * than by a migration, because a service role holds no CREATE right on that
 * schema. Checking first means a database that never went through the
 * bootstrap logs one clear line instead of failing every request.
 */
$settingsTable = $pdo->query("SELECT to_regclass('platform.settings') AS present")->fetchColumn();

if ($settingsTable === false || $settingsTable === null) {
    Logger::warning('Company settings not seeded: platform.settings is missing');
} elseif ((int) $pdo->query("SELECT count(*) FROM platform.settings WHERE key LIKE 'company.%'")->fetchColumn() < count($defaults)) {
    $insertSetting = $pdo->prepare(
        'INSERT INTO platform.settings (key, value, updated_at, updated_by)
         VALUES (:setting_key, :setting_value::jsonb, :updated_at, NULL)
         ON CONFLICT (key) DO NOTHING'
    );

    // DO NOTHING rather than DO UPDATE: a default is a starting point, and an
    // administrator who has changed the working week must not find it reset
    // the next time the container restarts.
    foreach ($defaults as $key => $value) {
        $insertSetting->execute([
            'setting_key' => $key,
            'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Clock::iso(),
        ]);
    }
}

// ---------------------------------------------------------------------------
// The administrator account
// ---------------------------------------------------------------------------

$adminEmail = strtolower(trim((string) Env::get('SEED_ADMIN_EMAIL', '')));
$adminPassword = (string) Env::get('SEED_ADMIN_PASSWORD', '');

if ($adminEmail === '' || $adminPassword === '') {
    Logger::warning('Administrator account not seeded: SEED_ADMIN_EMAIL or SEED_ADMIN_PASSWORD is not set');
} else {
    $seededAdminId = '8044f8e4-46c5-442a-bfcb-ae491dcc9ded';

    $lookup = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id OR email = :email LIMIT 1');
    $lookup->execute(['id' => $seededAdminId, 'email' => $adminEmail]);
    $existing = $lookup->fetch();

    if ($existing === false) {
        $users->create([
            'id' => $seededAdminId,
            'employee_id' => '28010836-0cfb-4b4d-aa20-b0b0f2bedfe3',
            'employee_code' => 'DF-0001',
            'email' => $adminEmail,
            // Administrative provisioning, not registration. The configured
            // credential is hashed exactly as given and never run through the
            // account policy: an operator who has set a password in their own
            // environment file must be able to sign in with it, and sign-in
            // does not apply the policy either.
            'password_hash' => Password::hash($adminPassword),
            'first_name' => (string) Env::get('SEED_ADMIN_FIRST_NAME', 'System'),
            'last_name' => (string) Env::get('SEED_ADMIN_LAST_NAME', 'Administrator'),
            'is_active' => true,
            'email_verified_at' => Clock::iso(),
            'must_change_password' => false,
            'failed_login_count' => 0,
        ]);

        Logger::info('Administrator account created', ['user_id' => $seededAdminId]);
        $adminId = $seededAdminId;
    } else {
        $adminId = (string) $existing['id'];

        // Cheap and unconditionally attempted, so an administrator who has
        // locked themselves out is back in as soon as the service restarts.
        // The guard means the statement writes nothing at all once the account
        // is already in this state.
        $pdo->prepare(
            'UPDATE users
                SET is_active         = TRUE,
                    email_verified_at = COALESCE(email_verified_at, :verified_at),
                    failed_login_count = 0,
                    locked_until      = NULL,
                    deleted_at        = NULL,
                    updated_at        = :updated_at
              WHERE id = :id
                AND (is_active = FALSE
                     OR locked_until IS NOT NULL
                     OR email_verified_at IS NULL
                     OR deleted_at IS NOT NULL
                     OR failed_login_count > 0)'
        )->execute([
            'id' => $adminId,
            'verified_at' => Clock::iso(),
            'updated_at' => Clock::iso(),
        ]);

        // Confirming the stored hash still matches the configured password
        // costs an Argon2id verification, which is far too expensive to repeat
        // freely, so one process an interval does it and the rest skip. See
        // migration 0005.
        $claim = $pdo->prepare(
            "INSERT INTO seed_state (name, checked_at)
             VALUES ('admin_credential', NOW())
             ON CONFLICT (name)
             DO UPDATE SET checked_at = NOW()
                     WHERE seed_state.checked_at < NOW() - make_interval(secs => :sync_interval)
             RETURNING name"
        );
        $claim->execute(['sync_interval' => max(5, Env::int('SEED_ADMIN_SYNC_SECONDS', 60))]);

        if ($claim->fetchColumn() !== false
            && !Password::verify($adminPassword, (string) $existing['password_hash'])
        ) {
            // The environment file is the single source of truth for this one
            // credential. An operator who edits it and restarts must never be
            // locked out of their own deployment; anyone wanting a password a
            // restart will not overwrite has the ordinary change-password flow
            // and a second administrator account.
            $pdo->prepare(
                'UPDATE users
                    SET password_hash        = :password_hash,
                        must_change_password = FALSE,
                        updated_at           = :updated_at
                  WHERE id = :id'
            )->execute([
                'id' => $adminId,
                'password_hash' => Password::hash($adminPassword),
                'updated_at' => Clock::iso(),
            ]);

            Logger::info('Administrator password synchronised with the environment', ['user_id' => $adminId]);
        }
    }

    $userRoles->grant($adminId, Roles::SUPER_ADMIN, null);
}

// ---------------------------------------------------------------------------
// Demo people
// ---------------------------------------------------------------------------

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

/**
 * Everyone in the demo company except the administrator, who is seeded above
 * from the environment.
 *
 * The roster itself lives in the shared kernel, in DemoCohort, because eight
 * other services seed records against these same people and all of them have
 * to agree on the identifiers. Keeping a private copy of the list here is
 * exactly how the original twelve UUIDs came to be duplicated across nine
 * files, and how the tenth file would have been forgotten.
 */
$roleFor = static fn (string $key): string => match ($key) {
    'hr_admin' => Roles::HR_ADMIN,
    'hr_officer' => Roles::HR_OFFICER,
    'finance' => Roles::FINANCE,
    'manager' => Roles::MANAGER,
    default => Roles::EMPLOYEE,
};

$founding = [
    ['DF-0002', 'Meera', 'Iyer', Roles::HR_ADMIN],
    ['DF-0003', 'Rahul', 'Deshmukh', Roles::HR_OFFICER],
    ['DF-0004', 'Sneha', 'Kulkarni', Roles::FINANCE],
    ['DF-0005', 'Arjun', 'Nair', Roles::MANAGER],
    ['DF-0006', 'Priya', 'Sharma', Roles::EMPLOYEE],
    ['DF-0007', 'Vikram', 'Reddy', Roles::EMPLOYEE],
    ['DF-0008', 'Ananya', 'Bose', Roles::EMPLOYEE],
    ['DF-0009', 'Karthik', 'Menon', Roles::MANAGER],
    ['DF-0010', 'Divya', 'Raghavan', Roles::EMPLOYEE],
    ['DF-0011', 'Imran', 'Qureshi', Roles::EMPLOYEE],
    ['DF-0012', 'Neha', 'Joshi', Roles::EMPLOYEE],
];

$demoPeople = [];

foreach ($founding as [$code, $firstName, $lastName, $role]) {
    $demoPeople[] = [
        $code,
        DemoCohort::userId($code),
        DemoCohort::employeeId($code),
        $firstName,
        $lastName,
        DemoCohort::email($firstName, $lastName),
        $role,
    ];
}

foreach (DemoCohort::extended() as $person) {
    $demoPeople[] = [
        $person['code'],
        $person['user_id'],
        $person['employee_id'],
        $person['first_name'],
        $person['last_name'],
        $person['work_email'],
        $roleFor($person['role']),
    ];
}

$demoUserIds = array_column($demoPeople, 1);

// Two counting queries decide whether there is anything to do at all, which is
// what keeps a later run from issuing thirty statements to learn nothing.
$accountsPresent = QueryBuilder::table('users')->whereIn('id', $demoUserIds)->count();
$grantsPresent = QueryBuilder::table('user_roles')->whereIn('user_id', $demoUserIds)->count();

if ($accountsPresent === count($demoUserIds) && $grantsPresent >= count($demoUserIds)) {
    return;
}

$findDemo = $pdo->prepare('SELECT id FROM users WHERE id = :id OR email = :email LIMIT 1');

// employee_code is unique here too - on upper(employee_code) - and an account
// created through the application can already be holding the one this roster
// wants. Left unchecked that aborts the seed mid-way and the service stops
// answering, so the clash is detected and the person is left out instead.
$codeTaken = $pdo->prepare(
    'SELECT 1 FROM users WHERE upper(employee_code) = upper(:employee_code) AND id <> :id LIMIT 1'
);

// One hash, computed once and shared. Every demo account uses the same
// password by design, so hashing it once per person would only make a first
// boot slower without making anything safer - and at this size that is the
// difference between a boot and a wait.
$demoPasswordHash = null;

foreach ($demoPeople as [$code, $userId, $employeeId, $firstName, $lastName, $email, $role]) {
    $findDemo->execute(['id' => $userId, 'email' => $email]);
    $existing = $findDemo->fetch();

    if ($existing === false) {
        $codeTaken->execute(['employee_code' => $code, 'id' => $userId]);

        if ($codeTaken->fetchColumn() !== false) {
            Logger::warning('Demo account skipped: employee code already in use', ['employee_code' => $code]);

            continue;
        }

        $demoPasswordHash ??= Password::hash(DemoCohort::PASSWORD);

        $users->create([
            'id' => $userId,
            'employee_id' => $employeeId,
            'employee_code' => $code,
            'email' => $email,
            'password_hash' => $demoPasswordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => true,
            'email_verified_at' => Clock::iso(),
            'must_change_password' => false,
            'failed_login_count' => 0,
        ]);
    } elseif ((string) $existing['id'] !== $userId) {
        // The address belongs to a different account, so the fixed identifier
        // is not available. Granting against it would point at a row that is
        // not there, so this person is left out of the demo entirely.
        Logger::warning('Demo account skipped: address already in use', ['employee_code' => $code]);

        continue;
    }

    // Granting separately, and outside the insert, means a role added to the
    // catalogue later reaches accounts that were seeded on an earlier boot.
    $userRoles->grant($userId, $role, null);
}
