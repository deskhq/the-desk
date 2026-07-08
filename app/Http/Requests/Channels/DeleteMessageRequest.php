<?php

namespace App\Http\Requests\Channels;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->message());
    }

    /**
     * Get the message being deleted.
     */
    public function message(): Message
    {
        $message = $this->route('message');

        abort_if(! $message instanceof Message, 404);

        return $message;
    }
}
