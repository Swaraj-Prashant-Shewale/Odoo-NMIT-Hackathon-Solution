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
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * Identity seed.
 *
 * Two things are seeded unconditionally: the company defaults every other
 * service reads, and the administrator account named in the environment.
 * Without the second one a fresh deployment has no way in at all.
 *
 * Demo people are the twelve fixed identifiers the whole platform shares.
 * Their UUIDs are copied verbatim from docs/SEED-IDENTIFIERS.md rather than
 * generated, because eight other services seed records that point at them and
 * would otherwise reference people who do not exist.
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
 * The eleven demo colleagues, keyed by employee code.
 *
 * Every identifier is fixed rather than generated. Attendance, leave, payroll
 * and the rest all seed records against these employee_id values, so a
 * generated one here would leave the whole demo pointing at nobody.
 */
$demoPeople = [
    ['DF-0002', 'b988b84d-bdef-4ef7-9809-a14bd4a07350', 'a9f5c390-db56-42fa-96d9-fb1ff57ca041', 'Meera', 'Iyer', 'meera.iyer@dayflow.local', Roles::HR_ADMIN],
    ['DF-0003', '6b46a7fa-5737-4095-ba77-ec70b858dceb', 'bf5b3018-b6eb-4646-8507-8b6e6fdca490', 'Rahul', 'Deshmukh', 'rahul.deshmukh@dayflow.local', Roles::HR_OFFICER],
    ['DF-0004', 'cc6201b4-274b-4599-9a97-42b368cedd53', '31033589-5508-433c-966e-508f653b54be', 'Sneha', 'Kulkarni', 'sneha.kulkarni@dayflow.local', Roles::FINANCE],
    ['DF-0005', '78886f55-a3df-4831-8ea0-36204747eb75', 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6', 'Arjun', 'Nair', 'arjun.nair@dayflow.local', Roles::MANAGER],
    ['DF-0006', '208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237', '5f2fc57a-9d26-4279-bbdf-054496fd35ea', 'Priya', 'Sharma', 'priya.sharma@dayflow.local', Roles::EMPLOYEE],
    ['DF-0007', 'a26c2525-f201-44d6-9ccc-d06ea655b536', 'c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700', 'Vikram', 'Reddy', 'vikram.reddy@dayflow.local', Roles::EMPLOYEE],
    ['DF-0008', '0008e98e-7d40-451a-88c5-53419b6c993f', '5419981a-cd0a-4f65-9ff0-bcd16dd43a91', 'Ananya', 'Bose', 'ananya.bose@dayflow.local', Roles::EMPLOYEE],
    ['DF-0009', '7569e1f1-3659-42b9-89d3-bb90ab97c323', '4449d500-6fb5-48f3-8b9f-4fac8516da38', 'Karthik', 'Menon', 'karthik.menon@dayflow.local', Roles::MANAGER],
    ['DF-0010', '1c85286f-f790-4c8b-808d-bd63d619c205', '2b5d3904-9f2f-4ae3-9248-88adac38023a', 'Divya', 'Raghavan', 'divya.raghavan@dayflow.local', Roles::EMPLOYEE],
    ['DF-0011', '23513a48-7f1d-483b-8280-75a1c98aa8b9', 'a2f82591-b4b7-4de2-99ae-1098b9111ae7', 'Imran', 'Qureshi', 'imran.qureshi@dayflow.local', Roles::EMPLOYEE],
    ['DF-0012', '051c1f79-ef38-41d1-a44b-f21077a131a4', '87ae5bec-96ae-4226-92df-519a54dbda64', 'Neha', 'Joshi', 'neha.joshi@dayflow.local', Roles::EMPLOYEE],
];

$demoUserIds = array_column($demoPeople, 1);

// Two counting queries decide whether there is anything to do at all, which is
// what keeps a later run from issuing thirty statements to learn nothing.
$accountsPresent = QueryBuilder::table('users')->whereIn('id', $demoUserIds)->count();
$grantsPresent = QueryBuilder::table('user_roles')->whereIn('user_id', $demoUserIds)->count();

if ($accountsPresent === count($demoUserIds) && $grantsPresent >= count($demoUserIds)) {
    return;
}

$findDemo = $pdo->prepare('SELECT id FROM users WHERE id = :id OR email = :email LIMIT 1');

// One hash, computed once and shared. Every demo account uses the same
// password by design, so hashing it eleven times would only make a first boot
// slower without making anything safer.
$demoPasswordHash = null;

foreach ($demoPeople as [$code, $userId, $employeeId, $firstName, $lastName, $email, $role]) {
    $findDemo->execute(['id' => $userId, 'email' => $email]);
    $existing = $findDemo->fetch();

    if ($existing === false) {
        $demoPasswordHash ??= Password::hash('Dayflow@2026');

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
