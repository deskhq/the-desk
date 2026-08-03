<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class GifSearchRequest extends RouteBoundRequest
{
    /**
     * Searching the picker reuses the post-message policy: if the user could not
     * post a message to this channel, they cannot search GIFs for one either.
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
            // Blank/absent → Giphy trending; otherwise a search term.
            'q' => ['nullable', 'string', 'max:100'],
            // Infinite-scroll cursor (Giphy is offset-paginated).
            'offset' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
