<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Rules\PublicWebhookUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The body is the browser's own `PushSubscription.toJSON()`, posted
     * verbatim: the push service's endpoint plus the two keys every payload is
     * encrypted to. The endpoint is length-capped to the column's width — push
     * services issue long URLs, and a longer one would fail at insert rather
     * than at validation.
     *
     * HTTPS only, and public only. This value decides where the server itself
     * posts every push, from a queue worker inside the instance's own network:
     * a plaintext endpoint would put the delivery on the wire in the clear, and
     * a private or reserved host would turn the subscription into a blind
     * request forger aimed at whatever the app container can reach. Real push
     * services are public HTTPS without exception, so the guard costs nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => [
                'required',
                'string',
                'url:https',
                'max:500',
                new PublicWebhookUrl(__('The push endpoint must be a public HTTPS address.')),
            ],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            // Firefox's older endpoints negotiate `aesgcm`; everything current
            // uses `aes128gcm`, which is what the package assumes when absent.
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }

    /**
     * The push service URL this browser can be reached at.
     */
    public function endpoint(): string
    {
        return (string) $this->validated('endpoint');
    }

    /**
     * The browser's ECDH public key.
     */
    public function publicKey(): string
    {
        return (string) $this->validated('keys.p256dh');
    }

    /**
     * The browser's authentication secret.
     */
    public function authToken(): string
    {
        return (string) $this->validated('keys.auth');
    }

    /**
     * The payload encoding this browser negotiated, when it stated one.
     */
    public function contentEncoding(): ?string
    {
        $encoding = $this->validated('contentEncoding');

        return is_string($encoding) ? $encoding : null;
    }
}
