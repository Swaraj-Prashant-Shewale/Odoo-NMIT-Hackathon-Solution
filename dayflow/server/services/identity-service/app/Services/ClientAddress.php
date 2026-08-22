<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Security\InternalSignature;

/**
 * Works out which address a sign-in attempt really came from.
 *
 * Services only ever see the gateway as their peer, so REMOTE_ADDR would put
 * every attempt on the planet in one bucket and make the per-address rate
 * limit meaningless. The gateway therefore states the address it saw in
 * X-Dayflow-Client-Ip.
 *
 * That header is trusted only because the request as a whole is: the kernel
 * has already refused anything without a valid gateway signature, so nothing
 * that is not the gateway can set it. When signature checking is switched off
 * for local work, the header is ignored and the peer address is used instead,
 * so the relaxed setting cannot become a way to forge an address.
 */
final class ClientAddress
{
    public const HEADER = 'X-Dayflow-Client-Ip';

    public static function of(Request $request): string
    {
        $stated = trim($request->header(self::HEADER));

        if ($stated !== ''
            && filter_var($stated, FILTER_VALIDATE_IP) !== false
            && self::cameThroughGateway($request)
        ) {
            return $stated;
        }

        return $request->clientIp;
    }

    private static function cameThroughGateway(Request $request): bool
    {
        return InternalSignature::verify(
            $request->method,
            $request->path,
            $request->rawBody,
            $request->headers
        );
    }
}
