<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base every form request bound to a route model extends. Route model
 * binding hands the request a `mixed`, and narrowing it back to the model the
 * route named — or 404ing when it did not bind one — is a rule the requests
 * copied 53 times before #1151.
 *
 * Accessors are named for the recurring three (channel, team, message); a
 * one-off binding reaches the same rule in one line through {@see routeModel()}
 * rather than earning a method here.
 */
abstract class RouteBoundRequest extends FormRequest
{
    /**
     * The route-bound channel.
     */
    public function channel(): Channel
    {
        return $this->routeModel('channel', Channel::class);
    }

    /**
     * The route-bound team.
     */
    public function team(): Team
    {
        return $this->routeModel('team', Team::class);
    }

    /**
     * The route-bound message.
     */
    public function message(): Message
    {
        return $this->routeModel('message', Message::class);
    }

    /**
     * The model the given route parameter bound, 404ing when the route bound
     * nothing of that type.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function routeModel(string $key, string $model): Model
    {
        $bound = $this->route($key);

        abort_if(! $bound instanceof $model, 404);

        return $bound;
    }
}
