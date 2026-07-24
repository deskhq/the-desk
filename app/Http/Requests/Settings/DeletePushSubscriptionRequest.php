<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeletePushSubscriptionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only the endpoint is needed to revoke: it is the subscription's identity,
     * and the delete is scoped to the caller's own rows, so naming someone
     * else's endpoint removes nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * The push service URL of the device being revoked.
     */
    public function endpoint(): string
    {
        return (string) $this->validated('endpoint');
    }
}
