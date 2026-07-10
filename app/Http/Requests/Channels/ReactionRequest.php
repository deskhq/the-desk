<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Reacting reuses the `postMessage` rule: only a member of the (non-archived)
     * channel may react, so an archived channel stays read-only for reactions too.
     * The route scopes `{message}` to the channel and excludes soft-deleted rows,
     * so a tombstone can't be reacted to.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The literal unicode emoji character to toggle; capped so a stray
            // payload can't bloat the row while still fitting multi-codepoint
            // emoji (skin-tone and ZWJ sequences run several code points).
            'emoji' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * Get the channel the reaction is being toggled in.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
