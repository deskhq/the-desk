<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class SaveChannelDraftRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('updateMembership', $this->channel());
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
}
