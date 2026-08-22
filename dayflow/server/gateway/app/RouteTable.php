<?php

declare(strict_types=1);

namespace Gateway;

/**
 * Maps public API paths onto the microservice that owns them.
 *
 * The table is an allow-list. A path that does not appear here is rejected
 * with 404 before any proxying happens, so the gateway can never be used to
 * reach an internal endpoint that was not deliberately published.
 */
final class RouteTable
{
    /**
     * Prefix => service name.
     *
     * Longest prefix wins, which lets a specific path be routed differently
     * from the broader group it sits inside.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        // Identity: authentication, accounts, roles, audit trail, settings
        '/auth' => 'identity',
        '/users' => 'identity',
        '/roles' => 'identity',
        '/permissions' => 'identity',
        '/audit' => 'identity',
        '/settings' => 'identity',
        '/sessions' => 'identity',

        // People records, organisation structure, documents, onboarding
        '/employees' => 'employee',
        '/departments' => 'employee',
        '/designations' => 'employee',
        '/locations' => 'employee',
        '/org-chart' => 'employee',
        '/documents' => 'employee',
        '/onboarding' => 'employee',
        '/offboarding' => 'employee',
        '/assets' => 'employee',
        '/directory' => 'employee',

        // Time and attendance
        '/attendance' => 'attendance',
        '/shifts' => 'attendance',
        '/rosters' => 'attendance',
        '/holidays' => 'attendance',
        '/regularisations' => 'attendance',
        '/timesheets' => 'attendance',
        '/overtime' => 'attendance',

        // Leave and time off
        '/leave' => 'leave',
        '/leave-types' => 'leave',
        '/leave-balances' => 'leave',
        '/leave-policies' => 'leave',
        '/approvals' => 'leave',

        // Payroll and expenses
        '/payroll' => 'payroll',
        '/salary-structures' => 'payroll',
        '/payslips' => 'payroll',
        '/expenses' => 'payroll',
        '/pay-components' => 'payroll',

        // Learning and development
        '/learning' => 'learning',
        '/courses' => 'learning',
        '/enrolments' => 'learning',
        '/certificates' => 'learning',

        // Goals, reviews and feedback
        '/talent' => 'talent',
        '/goals' => 'talent',
        '/reviews' => 'talent',
        '/feedback' => 'talent',

        // Notifications and announcements
        '/notifications' => 'notification',
        '/announcements' => 'notification',
        '/mailbox' => 'notification',

        // Dashboards, reports and exports
        '/analytics' => 'analytics',
        '/reports' => 'analytics',
        '/dashboard' => 'analytics',
    ];

    /**
     * Endpoints reachable without an access token.
     *
     * Deliberately short, and matched exactly rather than by prefix so that
     * "/auth/login-as-admin" could never be smuggled through by accident.
     *
     * @var array<string, list<string>> Path => permitted methods.
     */
    private const PUBLIC_ENDPOINTS = [
        '/auth/login' => ['POST'],
        '/auth/register' => ['POST'],
        '/auth/refresh' => ['POST'],
        '/auth/verify-email' => ['POST', 'GET'],
        '/auth/resend-verification' => ['POST'],
        '/auth/forgot-password' => ['POST'],
        '/auth/reset-password' => ['POST'],
        '/auth/password-policy' => ['GET'],
        '/health' => ['GET'],
    ];

    /**
     * Paths that are rate limited far more aggressively than the default.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private const STRICT_LIMITS = [
        '/auth/login' => ['limit' => 10, 'window' => 300],
        '/auth/register' => ['limit' => 5, 'window' => 3600],
        '/auth/forgot-password' => ['limit' => 5, 'window' => 3600],
        '/auth/resend-verification' => ['limit' => 5, 'window' => 3600],
        '/auth/reset-password' => ['limit' => 10, 'window' => 3600],
    ];

    /** Returns the service that owns a path, or null when nothing matches. */
    public static function resolve(string $path): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach (self::ROUTES as $prefix => $service) {
            if (($path === $prefix || str_starts_with($path, $prefix . '/')) && strlen($prefix) > $bestLength) {
                $best = $service;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    public static function isPublic(string $path, string $method): bool
    {
        $methods = self::PUBLIC_ENDPOINTS[$path] ?? null;

        return $methods !== null && in_array(strtoupper($method), $methods, true);
    }

    /** @return array{limit: int, window: int}|null */
    public static function strictLimit(string $path): ?array
    {
        return self::STRICT_LIMITS[$path] ?? null;
    }

    /** The published surface, rendered by the /_catalogue endpoint. */
    public static function catalogue(): array
    {
        $grouped = [];

        foreach (self::ROUTES as $prefix => $service) {
            $grouped[$service][] = $prefix;
        }

        ksort($grouped);

        return $grouped;
    }
}
