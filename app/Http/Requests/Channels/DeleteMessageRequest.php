<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Support\Facades\Gate;

final class DeleteMessageRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->message());
    }
}
