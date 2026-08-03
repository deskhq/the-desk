<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Models\IncomingWebhook;
use App\Support\Integrations\IncomingWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ingests a message posted to a channel by an external system through an opaque,
 * unguessable webhook URL (Slack-style). The URL's final segment is the secret:
 * it is hashed and matched against a live webhook, and a message is authored by
 * the bound bot through the normal create + broadcast path. The whole surface is
 * gated by INTEGRATIONS_ENABLED (the route 404s when the platform is off).
 *
 * A request may also ask for a per-message display identity (`username` /
 * `icon_url`, Slack's own field names), letting one webhook post as many logical
 * sources. That is a display override only: the bot still authors the row, so the
 * BOT badge and squared avatar ride along and cannot be removed. There is no
 * per-webhook toggle for it — the holder of the URL can already post arbitrary
 * text as that bot, and the indelible badge makes the impersonating version
 * impossible.
 */
class IncomingWebhookController extends Controller
{
    /**
     * The request header carrying the optional hex HMAC-SHA256 signature of the
     * raw request body, tolerating a `sha256=` prefix (GitHub/Slack style).
     */
    private const string SIGNATURE_HEADER = 'X-Signature-256';

    /**
     * The request header carrying the Unix timestamp the signature was computed
     * at. Every signed request states one: the signed message is
     * `"{timestamp}.{raw body}"`, the same one outgoing deliveries use.
     */
    private const string TIMESTAMP_HEADER = 'X-Timestamp';

    /**
     * How far a signature's timestamp may sit from the server's own clock, in
     * either direction, before the request is refused. It absorbs ordinary clock
     * skew and delivery latency while bounding how long a captured request stays
     * usable.
     */
    private const int TIMESTAMP_TOLERANCE = 300;

    public function __invoke(Request $request, string $token, PostMessage $postMessage): JsonResponse
    {
        // The sender is a machine and rarely sets `Accept`. Without it Laravel
        // renders a validation failure as a redirect, and a `302` reads as
        // success to curl — the opposite of the self-diagnosing `422` this
        // endpoint promises. It only ever speaks JSON, so say so on its behalf.
        $request->headers->set('Accept', 'application/json');

        $webhook = IncomingWebhook::query()
            ->active()
            ->where('token_hash', IncomingWebhook::hashToken($token))
            ->first();

        // An unknown or revoked token is indistinguishable from a URL that never
        // existed — 404 without confirming the credential shape.
        abort_if($webhook === null, 404);

        $this->verifySignature($request, $webhook);

        $payload = $request->all();
        $body = IncomingWebhookPayload::body($payload);
        $override = IncomingWebhookPayload::authorOverride($payload);

        // A blank override field has already read as absent, so `nullable` here
        // is the "post under the bot's own identity" path. Anything present but
        // malformed fails instead of silently posting under a different name than
        // the one asked for — for a feature whose whole point is identity, that is
        // the worse failure. Plain `http` is allowed: ImageProxy accepts it and a
        // self-hoster's asset host may not offer TLS. The `url` rule rather than a
        // scheme prefix, so a bare `https://` — which would be signed into a proxy
        // URL that can only ever fail to fetch — is rejected at the door.
        $validated = Validator::make(['body' => $body, ...$override], [
            'body' => ['required', 'string', 'max:8000'],
            'username' => ['nullable', 'string', 'max:255'],
            'icon_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ])->validate();

        $bot = $webhook->bot;
        $channel = $webhook->channel;

        // The binding can outlive the membership that justified it (the bot was
        // removed, or the channel was archived), and it can outlive the channel
        // itself: a deleted channel is soft-deleted for its grace window, so the
        // relation resolves to null rather than the row it points at. Refuse the
        // post rather than authoring into a channel the bot may no longer touch.
        abort_if($channel === null, 403);
        abort_unless(Gate::forUser($bot)->allows('postMessage', $channel), 403);

        $postMessage->handle(
            channel: $channel,
            author: $bot,
            body: (string) $body,
            clientUuid: (string) Str::uuid(),
            authorOverrideName: $this->nullableString($validated, 'username'),
            authorOverrideAvatarUrl: $this->nullableString($validated, 'icon_url'),
            incomingWebhookId: $webhook->id,
        );

        return response()->json(['ok' => true], 202);
    }

    /**
     * Read a validated, nullable string out of the validator's output — the rules
     * above guarantee the shape, this narrows it back to a type the action takes.
     *
     * @param  array<string, mixed>  $validated
     */
    private function nullableString(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Enforce the HMAC signature when the webhook is configured with a signing
     * secret. An unsigned webhook skips this entirely (drop-in curl support); a
     * signed one rejects a missing or mismatched signature with 401.
     *
     * One scheme is verified here: the sender signs `"{timestamp}.{body}"` and
     * states that timestamp, which bounds how long a captured request stays
     * usable and lets a replay inside that window be refused outright. A digest
     * over the body alone replays forever and is no longer accepted from any
     * webhook, so an absent or malformed timestamp is refused rather than
     * quietly falling back to verifying the body on its own.
     */
    private function verifySignature(Request $request, IncomingWebhook $webhook): void
    {
        if ($webhook->signing_secret === null) {
            return;
        }

        $header = $request->header(self::SIGNATURE_HEADER);
        $signature = is_string($header) ? Str::after($header, 'sha256=') : '';
        $timestamp = $this->signedTimestamp($request);

        // `claimSignature` is last on purpose: it has a side effect, and the
        // short-circuit keeps it from burning a signature that failed anything
        // before it.
        abort_unless(
            $timestamp !== null
                && $signature !== ''
                && $this->timestampIsFresh($timestamp)
                && $webhook->signatureMatches($signature, $request->getContent(), $timestamp)
                && $this->claimSignature($webhook, $signature),
            401,
        );
    }

    /**
     * Record a signature as spent, and report whether it was still unspent — a
     * replay inside the freshness window loses the race and is refused. The
     * marker outlives the window it was accepted in: a timestamp up to the
     * tolerance in the future stays fresh for twice the tolerance, so a shorter
     * marker would expire while the signature is still verifiable.
     */
    private function claimSignature(IncomingWebhook $webhook, string $signature): bool
    {
        return Cache::add("incoming-webhook:{$webhook->id}:{$signature}", true, self::TIMESTAMP_TOLERANCE * 2);
    }

    /**
     * Whether a claimed signing time is close enough to now to be acted on.
     */
    private function timestampIsFresh(int $timestamp): bool
    {
        return abs(now()->getTimestamp() - $timestamp) <= self::TIMESTAMP_TOLERANCE;
    }

    /**
     * The Unix timestamp the request claims to have been signed at, or null when
     * the header is absent or not an integer — a signed request that states no
     * usable timestamp cannot be verified at all and is refused.
     */
    private function signedTimestamp(Request $request): ?int
    {
        $header = $request->header(self::TIMESTAMP_HEADER);

        return is_string($header) && ctype_digit($header) ? (int) $header : null;
    }
}
