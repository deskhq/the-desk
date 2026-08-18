<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one way this application opens a connection to a member-controlled URL.
 *
 * {@see OutboundUrlGuard} answers three separate questions — is this URL public,
 * what address does it resolve to, how must the connection be pinned — and they
 * only mean anything asked together, in that order, on every hop. Asking them at
 * a call site is how the sequence drifts: two of the three sites here once
 * spelled the same forty-five line hop-and-pin walk, and only one of them caught
 * a transport failure, so an unreachable host degraded to an empty result on the
 * image path and requeued the job on the unfurl path.
 *
 * Two named readings, because two different callers need two different things
 * from the same guarantee:
 *
 *  - {@see self::fetch()} — *give me the bytes at this URL*. Owns the hop bound,
 *    re-guards and re-pins every hop, enforces the size cap, applies the policy's
 *    content-type predicate, and returns null for anything that fails, including
 *    a host that never answers.
 *  - {@see self::send()} — *send this request I have already composed*. Owns the
 *    guard triple and nothing else, for a caller (webhook delivery) that has its
 *    own retry, its own failure log and its own idea of what a bad response
 *    means.
 *
 * Deliberately not routed through here: the Giphy client, the update checker and
 * the OIDC discovery calls. They talk to operator-configured hosts rather than
 * member-controlled ones, and routing them through a guard they would each need
 * to switch off is how a security module stops being one. ADR-0015 records that.
 */
final readonly class GuardedEgress
{
    /**
     * How many redirect hops {@see self::fetch()} follows before giving up. Each
     * hop is re-guarded and re-pinned, so a public URL cannot bounce us onto an
     * internal one.
     */
    private const int MAX_REDIRECTS = 3;

    /**
     * Total time budget for a single hop, in seconds.
     */
    private const int TIMEOUT_SECONDS = 5;

    public function __construct(private OutboundUrlGuard $guard) {}

    /**
     * Read the bytes at a URL, following redirects the guard clears, or null when
     * there is nothing safe and usable to read.
     *
     * Every way this can fail — blocked destination, dead host, error status,
     * unwanted content type, oversize body, redirect loop — is one null, because
     * a caller unfurling a link a member typed has the same answer for all of
     * them: no preview.
     */
    public function fetch(string $url, FetchPolicy $policy): ?FetchedBody
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = $this->send($url, Http::timeout(self::TIMEOUT_SECONDS));
            } catch (Throwable) {
                // Blocked by the guard, or a DNS/connect/timeout failure. Either
                // way there are no bytes, and an instance with no egress at all
                // degrades rather than failing the request that asked.
                return null;
            }

            if (! $response->redirect()) {
                return $this->read($response, $url, $policy);
            }

            $location = (string) $response->header('Location');

            if ($location === '') {
                return null;
            }

            $url = AbsoluteUrl::from($url, $location);
        }

        return null;
    }

    /**
     * Send an already-composed request to a vetted URL.
     *
     * The request arrives carrying whatever the caller owns — headers, body,
     * timeout, retry — and leaves with the two things it does not: redirects
     * disabled, and the connection pinned to the address the guard resolved.
     *
     * @throws BlockedEgress when the guard refuses the destination
     * @throws ConnectionException when the host does not answer
     */
    public function send(string $url, PendingRequest $request, string $method = 'GET'): Response
    {
        if (! OutboundUrlGuard::isPublic($url)) {
            throw BlockedEgress::notPublic();
        }

        $pinnedIp = $this->guard->resolveDeliveryIp($url);

        if ($pinnedIp === false) {
            throw BlockedEgress::resolvesToPrivateAddress();
        }

        return $request->withOptions($this->guard->transportOptions($url, $pinnedIp))->send($method, $url);
    }

    /**
     * Turn a non-redirect response into the body the policy will accept, or null.
     *
     * `Content-Length` is checked before the body is touched so an oversize
     * response that declares itself is refused without being read at all; the
     * measured length is checked after, because a chunked response declares
     * nothing.
     *
     * Both checks still bound what this keeps rather than what it reads — Guzzle
     * has buffered the response by the time `body()` is called — which is #1202.
     * The unfurl path no longer runs through here at all (ADR-0016 moved it to a
     * service that reads through an `io.LimitReader`); the image proxy is the one
     * caller left, and the one the issue is still open for.
     */
    private function read(Response $response, string $url, FetchPolicy $policy): ?FetchedBody
    {
        if (! $response->successful()) {
            return null;
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! ($policy->accepts)($contentType)) {
            return null;
        }

        if ((int) $response->header('Content-Length') > $policy->maxBytes) {
            return null;
        }

        $body = $response->body();

        if ($body === '') {
            return null;
        }

        if (strlen($body) > $policy->maxBytes) {
            return null;
        }

        return new FetchedBody($url, $contentType, $body);
    }
}
