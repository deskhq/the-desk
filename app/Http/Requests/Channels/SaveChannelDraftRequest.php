<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveChannelDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('saveDraft', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The body is stored verbatim (mention tokens and all) so it restores
     * faithfully, so it is not trimmed here; a blank value clears the draft.
     * The length cap mirrors {@see PostMessageRequest} so a draft can never hold
     * more than a sendable message.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:8000'],
        ];
    }

    /**
     * Get the channel whose draft is being saved.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
