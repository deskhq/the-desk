<?php

declare(strict_types=1);

use App\Http\Requests\RouteBoundRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Resolve the model this route bound, or 404" was a five-line guard copied into
 * 42 of the 79 files under `app/Http/Requests/` before #1151. It now has one
 * home, {@see RouteBoundRequest}, and this is the test the copies never had.
 *
 * The base is reachable without an HTTP round-trip — a form request is just a
 * request with a route resolver — so the resolve-or-404 rule is stated here
 * directly rather than inferred from whichever endpoint happened to exercise it.
 */

/**
 * A request of the base's type, bound to a route carrying the given parameters.
 *
 * @param  array<string, mixed>  $parameters
 */
function routeBoundRequest(array $parameters): RouteBoundRequest
{
    $request = new class extends RouteBoundRequest {};

    $route = new Route('GET', '/{model}', []);
    $route->bind(Request::create('/'));

    foreach ($parameters as $key => $value) {
        $route->setParameter($key, $value);
    }

    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

/**
 * The recurring three, each keyed by the accessor and the route parameter it
 * reads. `channel` and `message` are the two `Api/V1\ApiRequest` already had;
 * `team` is the one the web subtree copied fifteen times.
 *
 * @return array<string, array{string, string, Model}>
 */
dataset('recurring route models', fn (): array => [
    'channel' => ['channel', 'channel', new Channel],
    'team' => ['team', 'team', new Team],
    'message' => ['message', 'message', new Message],
]);

test('each accessor returns the model the route bound', function (string $accessor, string $key, Model $model): void {
    expect(routeBoundRequest([$key => $model])->{$accessor}())->toBe($model);
})->with('recurring route models');

test('a route that bound the wrong type of model still 404s', function (string $accessor, string $key): void {
    $request = routeBoundRequest([$key => new stdClass]);

    expect(fn () => $request->{$accessor}())
        ->toThrow(NotFoundHttpException::class);
})->with('recurring route models');

test('a route that bound nothing at all still 404s', function (string $accessor): void {
    expect(fn () => routeBoundRequest([])->{$accessor}())
        ->toThrow(NotFoundHttpException::class);
})->with('recurring route models');

/**
 * The named accessors are one line each over a generic resolver, so a one-off
 * binding — a poll, a section, a scheduled message — gets the same rule without
 * earning a method on the base. This is the shape those call sites take.
 */
test('the resolver narrows any bound model, not just the recurring three', function (): void {
    $team = new Team;

    $request = new class extends RouteBoundRequest
    {
        public function owner(): Team
        {
            return $this->routeModel('owner', Team::class);
        }
    };

    $route = new Route('GET', '/{owner}', []);
    $route->bind(Request::create('/'));
    $route->setParameter('owner', $team);
    $request->setRouteResolver(fn (): Route => $route);

    expect($request->owner())->toBe($team);
});
