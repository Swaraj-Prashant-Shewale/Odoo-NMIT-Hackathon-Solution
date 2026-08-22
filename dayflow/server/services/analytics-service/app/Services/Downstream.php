<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Logger;

/**
 * The only way this service reads data.
 *
 * The analytics database role can see the analytics schema and nothing else, so
 * every figure on every chart is fetched over HTTP from the service that owns
 * the records. The caller's own access token is forwarded on each call, which
 * means the far side applies its own authorisation and returns exactly what
 * that person is entitled to see. That is what makes an organisation-wide
 * dashboard safe: analytics never has a view of its own, only the caller's.
 *
 * Results are memoised for the lifetime of one request because several cards
 * are built from the same underlying collection, and an unavailable service
 * degrades the cards that needed it rather than the whole page.
 */
final class Downstream
{
    /** @var array<string, mixed> Response envelopes already fetched this request. */
    private array $memo = [];

    /** @var array<string, true> Services that failed at least once this request. */
    private array $unavailable = [];

    public function __construct(private readonly ?string $token)
    {
    }

    /**
     * The "data" element of a response, or $default when the call did not
     * succeed. This is the decoration path: a failure is a missing card, not
     * an error.
     *
     * @param array<string, mixed> $query
     */
    public function data(string $service, string $path, array $query = [], mixed $default = null): mixed
    {
        $envelope = $this->envelope($service, $path, $query);

        return $envelope === null ? $default : ($envelope['data'] ?? $default);
    }

    /**
     * A collection, normalised to a list of rows.
     *
     * Returns null - not an empty list - when the service could not be reached,
     * so a card can tell "nobody is on leave" apart from "leave is down".
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>|null
     */
    public function rows(string $service, string $path, array $query = []): ?array
    {
        $envelope = $this->envelope($service, $path, $query);

        return $envelope === null ? null : Payload::rows($envelope['data'] ?? []);
    }

    /**
     * A single record.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function record(string $service, string $path, array $query = []): ?array
    {
        $data = $this->data($service, $path, $query);

        return is_array($data) ? $data : null;
    }

    /**
     * How many records match, taken from the pagination metadata when the
     * service supplies it and from the returned rows otherwise.
     *
     * @param array<string, mixed> $query
     */
    public function total(string $service, string $path, array $query = []): ?int
    {
        $envelope = $this->envelope($service, $path, $query);

        if ($envelope === null) {
            return null;
        }

        $total = $envelope['meta']['total'] ?? null;

        return is_numeric($total) ? (int) $total : count(Payload::rows($envelope['data'] ?? []));
    }

    /**
     * Walks a paginated collection and returns every row.
     *
     * The page cap is deliberate: an analytics screen must not be able to pull
     * an unbounded amount of data out of another service, however large the
     * organisation grows.
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>|null
     */
    public function collect(string $service, string $path, array $query = [], int $perPage = 100, int $maxPages = 25): ?array
    {
        $collected = [];
        $page = 1;

        while ($page <= $maxPages) {
            $envelope = $this->envelope($service, $path, $query + ['page' => $page, 'per_page' => $perPage]);

            if ($envelope === null) {
                return $page === 1 ? null : $collected;
            }

            $rows = Payload::rows($envelope['data'] ?? []);
            $collected = array_merge($collected, $rows);

            $totalPages = $envelope['meta']['total_pages'] ?? null;

            if ($rows === [] || count($rows) < $perPage) {
                break;
            }

            if (is_numeric($totalPages) && $page >= (int) $totalPages) {
                break;
            }

            $page++;
        }

        return $collected;
    }

    /** @return list<string> Services that did not answer, for the response metadata. */
    public function unavailableServices(): array
    {
        return array_keys($this->unavailable);
    }

    /**
     * Performs the call and returns the whole envelope, or null when anything
     * at all went wrong.
     *
     * This is ServiceClient::tryGet with the envelope kept rather than
     * discarded: several cards need the pagination total, and asking for one
     * record and reading meta.total is far cheaper than pulling every row to
     * count them. The failure behaviour is identical - log and return null, so
     * one unavailable service costs one card.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function envelope(string $service, string $path, array $query): ?array
    {
        ksort($query);
        $key = $service . ' ' . $path . ' ' . json_encode($query, JSON_UNESCAPED_SLASHES);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $envelope = ServiceClient::for($service, $this->token)->get($path, $query);
        } catch (\Throwable $exception) {
            // A 4xx is an answer: the endpoint is not there, or this caller may
            // not have it. Only a genuine non-answer - a refused connection, a
            // timeout, a fault on the far side - means the service is down, and
            // only that belongs in unavailable_services. Counting a 404 as an
            // outage would report healthy services as broken every time an
            // optional endpoint was probed.
            if (!$exception instanceof HttpException || $exception->status() >= 500) {
                $this->unavailable[$service] = true;
            }

            Logger::warning('Dashboard source did not return data', [
                'service' => $service,
                'path' => $path,
                'status' => $exception instanceof HttpException ? $exception->status() : 0,
                'error' => $exception->getMessage(),
            ]);

            return $this->memo[$key] = null;
        }

        return $this->memo[$key] = $envelope;
    }
}
