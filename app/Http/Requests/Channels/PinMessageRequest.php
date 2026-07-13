<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PinMessageRequest extends FormRequest
{
    /**
     * The hard cap on how many messages a single channel may pin at once.
     */
    public const int MAX_PINS = 100;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Pinning reuses the `postMessage` rule: only a member of the (non-archived)
     * channel may pin or unpin, so an archived channel stays read-only for pins
     * too (its existing pins remain viewable). The route scopes `{message}` to the
     * channel and excludes soft-deleted rows, so a tombstone can't be pinned; an
     * inert system notice is rejected here. Both endpoints share this rule.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel())
            && ! $this->message()->isSystem();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Enforce the per-channel pin cap on the pin (POST) path only.
     *
     * Re-pinning an already-pinned message is an idempotent no-op, so it never
     * trips the cap — only a genuinely new pin that would push the channel over
     * {@see self::MAX_PINS} is rejected, with a translated error the client
     * surfaces as a toast. Unpin (DELETE) never runs this check. The count race is
     * accepted: the unique constraint and this server-side count are the source of
     * truth, so no client pre-check is needed.
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->isMethod('post')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $channel = $this->channel();
            $alreadyPinned = $channel->pins()->where('message_id', $this->message()->id)->exists();

            if (! $alreadyPinned && $channel->pins()->count() >= self::MAX_PINS) {
                $validator->errors()->add('message', __('This channel has reached its limit of :max pinned messages.', ['max' => self::MAX_PINS]));
            }
        });
    }

    /**
     * Get the channel the message is being pinned in.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * Get the message being pinned or unpinned.
     */
    public function message(): Message
    {
        $message = $this->route('message');

        abort_if(! $message instanceof Message, 404);

        return $message;
    }
}
