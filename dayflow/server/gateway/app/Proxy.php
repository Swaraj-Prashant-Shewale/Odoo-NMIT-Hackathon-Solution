<?php

declare(strict_types=1);

namespace Gateway;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\InternalSignature;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * Forwards a verified request to the service that owns it.
 *
 * The proxy adds the internal signature that proves the call came through the
 * gateway, carries the caller's own token downstream, and relays the response
 * unchanged. It never interprets or rewrites a service's payload, so a service
 * remains the sole authority on its own data.
 */
final class Proxy
{
    /** Response headers worth passing back to the client. */
    private const FORWARDED_RESPONSE_HEADERS = [
        'content-type',
        'content-disposition',
        'content-length',
        'retry-after',
        'x-request-id',
    ];

    public function forward(string $service, Request $request, ?string $token): Response
    {
        $baseUrl = $this->addressOf($service);
        $path = $request->path;
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $url = $baseUrl . $path . ($query !== '' ? '?' . $query : '');

        // The signature covers the body, so it has to be computed over exactly
        // what will be sent. A GET body is not forwarded, and signing one that
        // the service never receives would make the signature fail to verify
        // for reasons nobody could see from either side.
        $body = $request->method === 'GET' ? '' : $request->rawBody;

        $headers = [
            'Accept: application/json',
            'X-Request-Id: ' . $request->requestId,
            // The service uses this rather than trusting X-Forwarded-For from
            // an arbitrary client, so rate limits and the audit trail record
            // the address the gateway actually saw.
            'X-Dayflow-Client-Ip: ' . $request->clientIp,
        ];

        $contentType = $request->header('content-type');
        if ($contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        foreach (InternalSignature::headers($request->method, $path, $body) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => Env::int('SERVICE_TIMEOUT_SECONDS', 20),
            CURLOPT_CONNECTTIMEOUT => 5,
            // A downstream service must never be able to redirect the gateway
            // somewhere else, and only plain HTTP on the internal network.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        ]);

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $status === 0) {
            Logger::error('Service unreachable', ['service' => $service, 'url' => $url, 'error' => $error]);

            throw HttpException::serviceUnavailable(
                sprintf('The %s service is not responding. Please try again shortly.', $service)
            );
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        return $this->buildResponse($status, $rawHeaders, $responseBody);
    }

    /** Checks whether a service answers its health endpoint. */
    public function ping(string $service): bool
    {
        try {
            $handle = curl_init($this->addressOf($service) . '/health');
        } catch (\Throwable) {
            return false;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status === 200;
    }

    private function buildResponse(int $status, string $rawHeaders, string $body): Response
    {
        $forwarded = [];
        $isJson = true;

        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (!in_array($name, self::FORWARDED_RESPONSE_HEADERS, true)) {
                continue;
            }

            if ($name === 'content-type') {
                $isJson = str_contains($value, 'application/json');
            }

            // Header values are stripped of anything that could start a new
            // header line before being written back to the client.
            $forwarded[$this->canonicalise($name)] = str_replace(["\r", "\n"], '', $value);
        }

        // A binary payload such as a payslip PDF is relayed byte for byte, with
        // the service's own Content-Type and Content-Disposition preserved.
        if (!$isJson) {
            return Response::binary($status, $body, $forwarded);
        }

        $decoded = json_decode($body, true);
        $payload = is_array($decoded) ? $decoded : ['data' => null];

        // Content-Length is dropped: the body is re-encoded on the way out and
        // a stale length would truncate the response.
        unset($forwarded['Content-Length'], $forwarded['Content-Type']);

        return Response::raw($status, $payload, $forwarded);
    }

    private function canonicalise(string $header): string
    {
        return implode('-', array_map('ucfirst', explode('-', $header)));
    }

    private function addressOf(string $service): string
    {
        $key = strtoupper(str_replace('-', '_', $service)) . '_SERVICE_URL';
        $url = Env::get($key);

        if ($url === null) {
            throw new \RuntimeException(sprintf('No address configured for the "%s" service.', $service));
        }

        return rtrim($url, '/');
    }
}
