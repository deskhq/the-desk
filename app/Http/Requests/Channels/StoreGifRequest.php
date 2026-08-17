<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class StoreGifRequest extends RouteBoundRequest
{
    /**
     * Attaching a GIF reuses the post-message policy, exactly like uploading a
     * file: if the user could not post here, they cannot stage a GIF for a
     * message either.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The opaque Giphy id, the only thing the client sends; the server
            // re-resolves it authoritatively (never trusting a client URL).
            'id' => ['required', 'string', 'max:100'],
        ];
    }
}
