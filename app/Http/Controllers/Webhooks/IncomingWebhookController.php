<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Models\IncomingWebhook;
use App\Support\Integrations\IncomingWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ingests a message posted to a channel by an external system through an opaque,
 * unguessable webhook URL (Slack-style). The URL's final segment is the secret:
 * it is hashed and matched against a live webhook, and a message is authored by
 * the bound bot through the normal create + broadcast path. The whole surface is
 * gated by INTEGRATIONS_ENABLED (the route 404s when the platform is off).
 */
class IncomingWebhookController extends Controller
{
    /**
     * The request header carrying the optional hex HMAC-SHA256 signature of the
     * raw request body, tolerating a `sha256=` prefix (GitHub/Slack style).
     */
    private const string SIGNATURE_HEADER = 'X-Signature-256';

    public function __invoke(Request $request, string $token, PostMessage $postMessage): JsonResponse
    {
        $webhook = IncomingWebhook::query()
            ->active()
            ->where('token_hash', IncomingWebhook::hashToken($token))
            ->first();

        // An unknown or revoked token is indistinguishable from a URL that never
        // existed — 404 without confirming the credential shape.
        abort_if($webhook === null, 404);

        $this->verifySignature($request, $webhook);

        $body = IncomingWebhookPayload::body($request->all());

        Validator::make(['body' => $body], [
            'body' => ['required', 'string', 'max:8000'],
        ])->validate();

        $bot = $webhook->bot;
        $channel = $webhook->channel;

        // The binding can outlive the membership that justified it (the bot was
        // removed, or the channel was archived); refuse the post rather than
        // authoring into a channel the bot may no longer touch.
        abort_unless(Gate::forUser($bot)->allows('postMessage', $channel), 403);

        $postMessage->handle(
            channel: $channel,
            author: $bot,
            body: (string) $body,
            clientUuid: (string) Str::uuid(),
        );

        return response()->json(['ok' => true], 202);
    }

    /**
     * Enforce the HMAC signature when the webhook is configured with a signing
     * secret. An unsigned webhook skips this entirely (drop-in curl support); a
     * signed one rejects a missing or mismatched signature with 401.
     */
    private function verifySignature(Request $request, IncomingWebhook $webhook): void
    {
        if ($webhook->signing_secret === null) {
            return;
        }

        $header = $request->header(self::SIGNATURE_HEADER);
        $signature = is_string($header) ? Str::after($header, 'sha256=') : '';

        abort_unless(
            $signature !== '' && $webhook->signatureMatches($signature, $request->getContent()),
            401,
        );
    }
}
