<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class UpdateChannelStarRequest extends RouteBoundRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starred' => ['required', 'boolean'],
        ];
    }
}
