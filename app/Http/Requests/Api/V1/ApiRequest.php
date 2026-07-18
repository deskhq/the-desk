<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared accessors for the public-API form requests: the authenticated token
 * subject (a bot or a human personal access token) and the route-bound channel
 * / message, each narrowed to its concrete type so the subclasses stay terse.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * The authenticated subject behind the token — a bot or a human.
     */
    protected function subject(): User
    {
        $user = $this->user();

        abort_if(! $user instanceof User, 401);

        return $user;
    }

    /**
     * The route-bound channel.
     */
    protected function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * The route-bound message.
     */
    protected function message(): Message
    {
        $message = $this->route('message');

        abort_if(! $message instanceof Message, 404);

        return $message;
    }
}
