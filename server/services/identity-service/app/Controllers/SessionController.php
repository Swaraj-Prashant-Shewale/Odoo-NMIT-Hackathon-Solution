<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\RefreshTokens;
use App\Services\TokenIssuer;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * The devices a person is currently signed in on.
 *
 * Both routes are declared as merely authenticated rather than carrying a
 * permission, because there is no permission that would be correct: this is
 * never about what a role may do, only ever about whose session it is. The
 * ownership check therefore lives here, and the query is scoped to the caller
 * so a row belonging to somebody else cannot even be named, let alone ended.
 */
final class SessionController
{
    private RefreshTokens $refreshTokens;
    private TokenIssuer $tokens;

    public function __construct()
    {
        $this->refreshTokens = new RefreshTokens();
        $this->tokens = new TokenIssuer();
    }

    /** Lists the caller's own live sessions. */
    public function index(Request $request): Response
    {
        $principal = $request->principal();
        $sessions = $this->refreshTokens->liveSessionsFor($principal->userId);

        return Response::ok(array_map(
            static fn (array $session): array => [
                'id' => (string) $session['id'],
                'family_id' => (string) $session['family_id'],
                'started_at' => (string) $session['started_at'],
                'last_used_at' => (string) $session['issued_at'],
                'expires_at' => (string) $session['expires_at'],
                'user_agent' => $session['user_agent'] === null ? null : (string) $session['user_agent'],
                'ip_address' => $session['ip_address'] === null ? null : (string) $session['ip_address'],
            ],
            $sessions
        ), ['total' => count($sessions)]);
    }

    /** Ends one of the caller's own sessions. */
    public function destroy(Request $request): Response
    {
        $principal = $request->principal();

        $data = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        // Scoping the lookup to the caller means an id belonging to another
        // person is indistinguishable from one that does not exist, so this
        // endpoint cannot be used to probe for other people's sessions either.
        $session = $this->refreshTokens->findOwnedSession((string) $data['id'], $principal->userId);

        if ($session === null) {
            throw HttpException::notFound('That session does not exist.');
        }

        $this->tokens->revokeSession((string) $session['family_id'], 'signed_out_remotely');

        AuditLog::record($request, 'identity.session.revoked', 'refresh_token', (string) $session['id'], [], [], [
            'family_id' => (string) $session['family_id'],
        ]);

        return Response::noContent();
    }
}
