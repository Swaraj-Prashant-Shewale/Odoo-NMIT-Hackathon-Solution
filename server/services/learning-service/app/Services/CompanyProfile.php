<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Env;

/**
 * Reads the organisation-wide settings a certificate has to carry.
 *
 * The values live in the shared platform schema, which every service may read
 * but only identity-service may write, so the company name printed on a
 * certificate is corrected in exactly one place.
 */
final class CompanyProfile
{
    private static ?string $name = null;

    private function __construct()
    {
    }

    public static function name(): string
    {
        if (self::$name !== null) {
            return self::$name;
        }

        $fallback = Env::get('APP_NAME', 'Dayflow') ?? 'Dayflow';

        try {
            $statement = Connection::pdo()->prepare(
                'SELECT value FROM platform.settings WHERE key = :key'
            );
            $statement->execute(['key' => 'company.name']);
            $raw = $statement->fetchColumn();
        } catch (\Throwable) {
            return self::$name = $fallback;
        }

        if ($raw === false || $raw === null) {
            return self::$name = $fallback;
        }

        $decoded = json_decode((string) $raw, true);

        return self::$name = is_string($decoded) && $decoded !== '' ? $decoded : $fallback;
    }
}
