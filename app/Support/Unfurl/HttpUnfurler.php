<?php

declare(strict_types=1);

namespace App\Support\Unfurl;

use App\Support\Http\GuardedEgress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Unit\GuardedEgressHomeTest;

/**
 * Talks to the `unfurler` service.
 *
 * This class holds the HTTP client, which makes it the fifth entry on
 * {@see GuardedEgressHomeTest}'s allowlist. It earns that on the
 * criterion the other exceptions already established: it dials an
 * operator-configured host — `config('unfurl.url')`, a service name on the
 * container network — and not a member-controlled one. The member-controlled URL
 * is in the *body* of this request, and what opens a connection to it is the
 * guard inside the service. See ADR-0015's amendment and ADR-0016.
 *
 * Routing this through {@see GuardedEgress} would be worse than
 * useless: the guard refuses a private container address, so the only way
 * through is the per-caller "guard: off" flag ADR-0015 names as how a security
 * module stops being one.
 */
final readonly class HttpUnfurler implements Unfurler
{
    #[\Override]
    public function unfurl(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $service = (string) config('unfurl.url');

        // No service configured is not a failure. It is an instance that has
        // turned link unfurling off, either deliberately or by running a compose
        // file that predates the service, and either way every link resolves to
        // no card without a request being made.
        if ($service === '') {
            return array_fill_keys($urls, null);
        }

        $results = $this->ask($service, $urls);

        $previews = [];

        foreach ($results as $result) {
            if (! is_array($result) || ! is_string($url = $result['url'] ?? null)) {
                continue;
            }

            // A result for something we never asked about is not ours to act on.
            if (! in_array($url, $urls, true)) {
                continue;
            }

            $previews[$url] = $this->toPreview($result);
        }

        // Anything the service did not answer is a failure, not a gap. A row
        // left pending is a skeleton that spins forever.
        return array_merge(array_fill_keys($urls, null), $previews);
    }

    /**
     * Send the batch and return the raw per-URL results.
     *
     * @param  list<string>  $urls
     * @return array<int, mixed>
     *
     * @throws UnfurlerUnavailable
     */
    private function ask(string $service, array $urls): array
    {
        try {
            $response = Http::withToken((string) config('unfurl.token'))
                ->timeout((int) config('unfurl.timeout'))
                ->connectTimeout((int) config('unfurl.connect_timeout'))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($service, '/').'/unfurl', ['urls' => $urls]);
        } catch (ConnectionException $exception) {
            throw UnfurlerUnavailable::unreachable($exception->getMessage());
        }

        if (! $response->successful()) {
            throw UnfurlerUnavailable::badStatus($response->status());
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            throw UnfurlerUnavailable::malformed();
        }

        return array_values($results);
    }

    /**
     * Read one result, or null when it carries no usable preview.
     *
     * @param  array<string, mixed>  $result
     */
    private function toPreview(array $result): ?UnfurledPreview
    {
        if (($result['status'] ?? null) !== 'ok' || ! is_array($preview = $result['preview'] ?? null)) {
            return null;
        }

        $title = is_string($preview['title'] ?? null) ? trim($preview['title']) : '';

        if ($title === '') {
            return null;
        }

        return new UnfurledPreview(
            title: $title,
            description: $this->text($preview['description'] ?? null),
            image: $this->text($preview['image'] ?? null),
            siteName: $this->text($preview['siteName'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
