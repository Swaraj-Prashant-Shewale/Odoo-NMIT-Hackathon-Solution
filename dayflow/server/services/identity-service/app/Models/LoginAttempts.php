<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The security log for sign-in.
 *
 * The address attempted is written as a keyed digest, never in the clear: the
 * table has to answer "is this account being ground at?", and a digest answers
 * that just as well without turning the log into an address book.
 */
final class LoginAttempts extends Repository
{
    protected string $table = 'login_attempts';

    protected string $primaryKey = 'id';

    protected array $fillable = ['id', 'email_hash', 'ip_address', 'successful', 'attempted_at', 'failure_reason'];

    protected array $hidden = ['email_hash'];

    protected array $casts = ['successful' => 'bool'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;
}
