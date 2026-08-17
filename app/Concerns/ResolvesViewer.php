<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Http\Controllers\Controller;
use App\Models\User;

/**
 * The form-request twin of {@see Controller::viewer()}.
 *
 * A form request that asks who is making the request is, without exception, one
 * behind the `auth` middleware — its rules and its authorization are written
 * about a signed-in person. `Request::user()` is still nullable, so this
 * narrows it in one place rather than at every rule that reads it, and treats a
 * null as the routing mistake it would be.
 */
trait ResolvesViewer
{
    /**
     * The signed-in viewer this request is authorized against.
     */
    public function viewer(): User
    {
        $viewer = $this->user();

        abort_if(! $viewer instanceof User, 401);

        return $viewer;
    }
}
