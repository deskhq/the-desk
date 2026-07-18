<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreGifRequest extends FormRequest
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

    /**
     * Get the channel the GIF is being attached to.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
