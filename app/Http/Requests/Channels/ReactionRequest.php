<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class ReactionRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Reacting reuses the `postMessage` rule: only a member of the (non-archived)
     * channel may react, so an archived channel stays read-only for reactions too.
     * The route scopes `{message}` to the channel and excludes soft-deleted rows,
     * so a tombstone can't be reacted to; an inert system notice is rejected here.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel())
            && ! $this->message()->isSystem();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Either a literal unicode emoji character or a workspace custom-emoji
            // `:name:` shortcode. Capped at 32 so a stray payload can't bloat the
            // row while still fitting multi-codepoint unicode (skin-tone and ZWJ
            // sequences) and the longest `:name:` token (name ≤ 30).
            'emoji' => ['required', 'string', 'max:32', $this->customEmojiRule()],
        ];
    }

    /**
     * When the reaction is a `:name:` shortcode, require the custom emoji to
     * exist in the channel's workspace; native unicode reactions pass through.
     * This keeps a reaction from pinning a shortcode that resolves to nothing.
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
