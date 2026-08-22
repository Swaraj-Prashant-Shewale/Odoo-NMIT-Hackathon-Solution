<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

use Dayflow\Kernel\Security\InternalSignature;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * Calls another microservice.
 *
 * Requests are signed with the internal key and carry the caller's access
 * token forward, so the receiving service sees the original user and applies
 * its own authorisation rules rather than trusting the calling service.
 *
 * Service addresses come from the environment, so the topology can change
 * without touching any code.
 */
final class ServiceClient
{
    private function __construct(
        private readonly string $baseUrl,
        private readonly ?string $bearerToken,
        private readonly int $timeout,
    ) {
    }

    /**
     * @param string $service Logical name, e.g. "employee". Resolved from
     *                        EMPLOYEE_SERVICE_URL in the environment.
     */
    public static function for(string $service, ?string $bearerToken = null): self
    {
        $key = strtoupper(str_replace('-', '_', $service)) . '_SERVICE_URL';
        $baseUrl = Env::get($key);

        if ($baseUrl === null) {
            throw new \RuntimeException(sprintf('No address configured for the "%s" service (%s).', $service, $key));
        }

        return new self(rtrim($baseUrl, '/'), $bearerToken, Env::int('SERVICE_TIMEOUT_SECONDS', 10));
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): array
    {
        $suffix = $query === [] ? '' : '?' . http_build_query($query);

        return $this->send('GET', $path . $suffix, null);
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('POST', $path, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('PUT', $path, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function patch(string $path, array $payload = []): array
    {
        return $this->send('PATCH', $path, $payload);
    }

    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, null);
    }

    /**
     * Performs a call and returns only the "data" element of the envelope.
     *
     * Failures are logged and surfaced as null so a non-essential lookup, such
     * as decorating a record with a department name, cannot take down the page
     * that requested it.
     */
    public function tryGet(string $path, array $query = [], mixed $default = null): mixed
    {
        try {
            $response = $this->get($path, $query);

            return $response['data'] ?? $default;
        } catch (\Throwable $exception) {
            Logger::warning('Optional service call failed', [
                'url' => $this->baseUrl . $path,
                'error' => $exception->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed> The decoded response envelope.
     */
    private function send(string $method, string $path, ?array $payload): array
    {
        $path = '/' . ltrim($path, '/');
        $url = $this->baseUrl . $path;
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Only the path is signed, so the query string is stripped first to
        // match exactly what the receiving service will reconstruct.
        $signedPath = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach (InternalSignature::headers($method, $signedPath, $body) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $headers[] = 'X-Request-Id: ' . $_SERVER['HTTP_X_REQUEST_ID'];
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Internal calls must never be bounced somewhere unexpected.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            Logger::error('Service call failed', ['url' => $url, 'error' => $error]);

            throw HttpException::serviceUnavailable('A downstream service did not respond.');
        }

        $decoded = json_decode((string) $raw, true);
        $envelope = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            $message = $envelope['error']['message'] ?? 'A downstream service reported an error.';
            $code = $envelope['error']['code'] ?? 'downstream_error';

            throw new HttpException($status, (string) $message, (string) $code, $envelope['error']['details'] ?? []);
        }

        return $envelope;
    }
}
