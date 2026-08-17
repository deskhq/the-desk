<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RouteBoundRequest;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in viewer.
     *
     * `Request::user()` is nullable because a request need not be
     * authenticated, but a controller method reached through the `auth`
     * middleware is only ever called for one that is. Narrowing it here —
     * rather than letting a `?User` spread into every action, page object and
     * `Data` factory the method calls — is the same trade
     * {@see RouteBoundRequest::routeModel()} makes for a
     * route binding: a null means the route is wrong, so it 401s instead of
     * being rendered around.
     *
     * A page that genuinely renders for a guest asks `$request->user()` and
     * handles the null itself.
     */
    protected function viewer(Request $request): User
    {
        $viewer = $request->user();

        abort_if(! $viewer instanceof User, 401);

        return $viewer;
    }
}
