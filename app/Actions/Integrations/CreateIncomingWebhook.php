<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\Integrations\BotChannelAccess;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints an incoming webhook bound to one (bot, channel) pair and records the
 * creation in the workspace audit log. The opaque URL token — the credential —
 * is generated here, stored only as its hash, and returned once in plaintext for
 * the operator to copy; its value is never logged. An optional HMAC signing
 * secret can be requested for senders that can sign their requests.
 */
class CreateIncomingWebhook
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  bool  $withSigningSecret  Whether to mint an HMAC shared secret too.
     */
    public function handle(User $bot, Channel $channel, User $actor, string $name, bool $withSigningSecret = false): NewIncomingWebhook
    {
        if (! BotChannelAccess::allows($bot, $channel)) {
            throw ValidationException::withMessages([
                'channel' => __('The bot must be a member of the channel.'),
            ]);
        }

        $token = Str::random(48);
        $signingSecret = $withSigningSecret ? Str::random(48) : null;

        $webhook = IncomingWebhook::create([
            'team_id' => $bot->owner_team_id,
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
            'created_by' => $actor->id,
            'name' => $name,
            'token_hash' => IncomingWebhook::hashToken($token),
            'signing_secret' => $signingSecret,
        ]);

        $this->recorder->record($channel->team, $actor, AuditAction::IncomingWebhookCreated, $webhook, [
            'webhook_name' => $name,
            'bot_name' => $bot->name,
            'channel_name' => $channel->name,
        ]);

        return new NewIncomingWebhook($webhook, $token, $signingSecret);
    }
}
