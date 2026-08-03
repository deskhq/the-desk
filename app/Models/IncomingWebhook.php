<?php

namespace App\Models;

use App\Support\Webhooks\WebhookSignature;
use Database\Factories\IncomingWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An external system's credential to post one channel's messages by POSTing to
 * an unguessable URL. The opaque URL token is the secret (Slack-style), stored
 * only as its sha256 hash; the webhook is bound to a single (bot, channel) pair
 * where the bot is a member and authors every message it ingests.
 *
 * @property string $id
 * @property string $team_id
 * @property string $channel_id
 * @property string $bot_id
 * @property string|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property string|null $signing_secret
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Channel|null $channel
 * @property-read User $bot
 */
#[Fillable(['team_id', 'channel_id', 'bot_id', 'created_by', 'name', 'token_hash', 'signing_secret'])]
class IncomingWebhook extends Model
{
    /** @use HasFactory<IncomingWebhookFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Hash an opaque token the way it is stored, so a lookup can match it.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Limit the query to webhooks that still resolve — a revoked webhook stays
     * for the audit trail but no longer accepts posts.
     *
     * @param  Builder<IncomingWebhook>  $query
     */
    protected function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Whether the request's HMAC signature matches this webhook's signing secret.
     * A webhook without a signing secret is unsigned and never matches here — the
     * caller decides whether an unsigned request is acceptable.
     *
     * The signed message is `"{timestamp}.{body}"`, computed through the same
     * {@see WebhookSignature} both directions share, so a captured request stops
     * verifying once its timestamp falls outside the caller's freshness window.
     * The timestamp is not optional: a digest over the body alone replays
     * forever, and signing one is no longer a way to authenticate here.
     */
    public function signatureMatches(string $signature, string $rawBody, int $timestamp): bool
    {
        if ($this->signing_secret === null) {
            return false;
        }

        return hash_equals(WebhookSignature::digest($this->signing_secret, $rawBody, $timestamp), $signature);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the channel this webhook posts into.
     *
     * Null once that channel is deleted: the row survives its grace window as a
     * soft delete, so the relation resolves to nothing rather than to a channel
     * nobody can see. The ingest endpoint refuses the post in that case.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_id');
    }
}
