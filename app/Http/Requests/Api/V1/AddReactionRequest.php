<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a bot adding a reaction to a message via the public API. The emoji
 * is a route segment, so it is merged into the input for validation.
 */
class AddReactionRequest extends ApiRequest
{
    /**
     * The bot must be a member of the channel, the message must belong to it and
     * not be a system notice, and the channel must be reactable — reusing the
     * same `postMessage` gate the web reaction path uses.
     */
    public function authorize(): bool
    {
        ApiChannelAccess::assert($this->subject(), $this->channel());

        abort_unless($this->message()->channel_id === $this->channel()->id, 404);

        return Gate::allows('postMessage', $this->channel())
            && ! $this->message()->isSystem();
    }

    /**
     * Validate the emoji route segment.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'emoji' => $this->route('emoji'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'max:32', $this->customEmojiRule()],
        ];
    }

    /**
     * When the reaction is a `:name:` shortcode, require the custom emoji to
     * exist in the channel's workspace; native unicode reactions pass through.
     */
    private function customEmojiRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! is_string($value) || preg_match('/^:([a-z0-9]+(?:-[a-z0-9]+)*):$/', $value, $matches) !== 1) {
                return;
            }

            $exists = $this->channel()->team
                ->customEmojis()
                ->where('name', $matches[1])
                ->exists();

            if (! $exists) {
                $fail(__('That custom emoji does not exist.'));
            }
        };
    }
}
