<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\RouteBoundRequest;
use App\Models\User;

/**
 * What the public-API form requests share beyond the route-bound models every
 * request resolves through {@see RouteBoundRequest}: the authenticated token
 * subject, a bot or a human personal access token, narrowed to its concrete
 * type so the subclasses stay terse.
 */
abstract class ApiRequest extends RouteBoundRequest
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
}
