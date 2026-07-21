<?php

declare(strict_types=1);

use App\Enums\IntegrationScope;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * `docs/public/openapi.yaml` is the machine-readable contract for the public
 * REST API: integrators generate clients from it and the docs site renders it.
 * Nothing in the framework keeps it honest, so a route added to
 * `routes/api.php` — or a scope quietly widened on an existing one — would ship
 * a spec that lies. These tests pin the document to the live route table in
 * both directions, so the surface and its contract can only move together.
 * See issue #531.
 */
$repositoryPath = fn (string $file): string => dirname(__DIR__, 2).'/'.$file;

$spec = function () use ($repositoryPath): array {
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile($repositoryPath('docs/public/openapi.yaml'));

    return $document;
};

/**
 * The single scope the route's `scope:` middleware enforces, or null when it
 * enforces none.
 */
function routeScope(RoutingRoute $route): ?string
{
    $middleware = collect($route->gatherMiddleware())
        ->first(static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'scope:'));

    return is_string($middleware) ? Str::after($middleware, 'scope:') : null;
}

/**
 * The live `/api/v1` surface as `"<method> <path>" => "<scope>"`, keyed exactly
 * the way the spec's paths object is flattened below.
 *
 * @return array<string, string|null>
 */
function liveApiOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes() as $route) {
        // Selected by URI, not by route name: a route registered under /api/v1
        // with some other name would otherwise be invisible to this gate, which
        // is precisely the drift it exists to catch.
        $uri = trim((string) $route->uri(), '/');

        if ($uri !== 'api/v1' && ! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        $path = '/'.trim(Str::after($uri, 'api/v1'), '/');

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $operations[strtolower((string) $method).' '.$path] = routeScope($route);
        }
    }

    ksort($operations);

    return $operations;
}

/**
 * The documented surface as `"<method> <path>" => "<scope>"`.
 *
 * OpenAPI 3.1 requires the security requirement array to be empty for any
 * scheme that is not `oauth2`/`openIdConnect`, so the scope cannot live in the
 * `bearerAuth` entry — it is carried in the `x-required-scope` extension.
 *
 * @param  array<string, mixed>  $spec
 * @return array<string, string|null>
 */
function documentedApiOperations(array $spec): array
{
    $operations = array_map(
        static fn (array $operation): ?string => $operation['x-required-scope'] ?? null,
        specOperations($spec),
    );

    ksort($operations);

    return $operations;
}

/**
 * Every operation object in the document, keyed by `"<method> <path>"`.
 *
 * A path item may also carry non-operation keys (`summary`, `parameters`, …),
 * so only the HTTP verbs OpenAPI defines are treated as operations.
 *
 * @param  array<string, mixed>  $spec
 * @return array<string, array<string, mixed>>
 */
function specOperations(array $spec): array
{
    $verbs = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
    $operations = [];

    foreach ($spec['paths'] as $path => $pathItem) {
        foreach (array_intersect_key($pathItem, array_flip($verbs)) as $method => $operation) {
            $operations[$method.' '.$path] = $operation;
        }
    }

    return $operations;
}

/**
 * Collect every `$ref` target in the document, however deeply nested.
 *
 * @param  array<array-key, mixed>  $node
 * @return list<string>
 */
function collectRefs(array $node): array
{
    $refs = [];

    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;

            continue;
        }

        if (is_array($value)) {
            $refs = [...$refs, ...collectRefs($value)];
        }
    }

    return $refs;
}

test('the spec is an OpenAPI 3.1 document', function () use ($spec): void {
    expect($spec()['openapi'])->toStartWith('3.1')
        ->and($spec()['info']['title'])->toBeString()
        ->and($spec()['info']['version'])->toBeString();
});

test('the spec documents exactly the live /api/v1 surface', function () use ($spec): void {
    expect(array_keys(documentedApiOperations($spec())))
        ->toBe(array_keys(liveApiOperations()), 'every /api/v1 route must be documented, and only those');
});

test('every documented operation declares the scope its route enforces', function () use ($spec): void {
    expect(documentedApiOperations($spec()))->toBe(liveApiOperations());
});

test('every /api/v1 route is named under the api.v1 prefix', function (): void {
    $unnamed = [];

    foreach (Route::getRoutes() as $route) {
        $uri = trim((string) $route->uri(), '/');

        if (str_starts_with($uri, 'api/v1/') && ! str_starts_with((string) $route->getName(), 'api.v1.')) {
            $unnamed[] = $uri;
        }
    }

    expect($unnamed)->toBeEmpty('these routes must be named api.v1.*: '.implode(', ', $unnamed));
});

test('the documented scopes all come from the IntegrationScope vocabulary', function () use ($spec): void {
    expect(array_values(array_unique(documentedApiOperations($spec()))))
        ->each->toBeIn(IntegrationScope::values());
});

test('the spec describes the bearer token security scheme the API authenticates with', function () use ($spec): void {
    $scheme = $spec()['components']['securitySchemes']['bearerAuth'];

    expect($scheme['type'])->toBe('http')
        ->and($scheme['scheme'])->toBe('bearer');
});

test('every operation requires the bearer scheme with the empty array 3.1 mandates', function () use ($spec): void {
    foreach (specOperations($spec()) as $name => $operation) {
        expect($operation['security'])->toBe([['bearerAuth' => []]], $name.' must require bearerAuth with no in-band scopes');
    }
});

test('every operation repeats its required scope in its description', function () use ($spec): void {
    // The rendered reference does not surface `x-required-scope`, so the scope
    // is restated at the top of the description. Pinning the two together keeps
    // the human-readable copy from drifting from the machine-readable one.
    $missing = array_keys(array_filter(
        specOperations($spec()),
        static fn (array $operation): bool => ! str_contains(
            (string) $operation['description'],
            '**Required scope:** `'.$operation['x-required-scope'].'`',
        ),
    ));

    expect($missing)->toBeEmpty('these operations must state their scope: '.implode(', ', $missing));
});

test('every operation carries an operationId, a summary and a tag', function () use ($spec): void {
    foreach (specOperations($spec()) as $name => $operation) {
        expect(array_keys($operation))->toContain('operationId', 'summary', 'tags')
            ->and($operation['tags'])->not->toBeEmpty($name.' must be grouped under a tag');
    }
});

test('operation ids are unique so generated clients do not collide', function () use ($spec): void {
    $ids = array_column(array_values(specOperations($spec())), 'operationId');

    expect($ids)->toBe(array_unique($ids));
});

test('every tag an operation uses is declared at the document root', function () use ($spec): void {
    $declared = array_column($spec()['tags'], 'name');

    foreach (specOperations($spec()) as $name => $operation) {
        expect($operation['tags'])->each->toBeIn($declared, $name.' uses an undeclared tag');
    }
});

test('every operation documents the shared error responses', function (string $status) use ($spec): void {
    $missing = array_keys(array_filter(
        specOperations($spec()),
        static fn (array $operation): bool => ! array_key_exists($status, $operation['responses']),
    ));

    expect($missing)->toBeEmpty('these operations must document a '.$status.': '.implode(', ', $missing));
})->with([
    // Every route sits behind the same three guards: the Sanctum token, its
    // scope, and the per-token throttle. The 404 is universal too — the whole
    // surface disappears when INTEGRATIONS_ENABLED is off.
    '401', '403', '404', '429',
]);

test('every operation that accepts a body documents the validation failure', function () use ($spec): void {
    $missing = array_keys(array_filter(
        specOperations($spec()),
        static fn (array $operation): bool => isset($operation['requestBody'])
            && ! array_key_exists('422', $operation['responses']),
    ));

    expect($missing)->toBeEmpty('these operations must document a 422: '.implode(', ', $missing));
});

test('every path parameter the template declares is described', function () use ($spec): void {
    $document = $spec();

    foreach (specOperations($document) as $name => $operation) {
        $path = Str::after($name, ' ');

        preg_match_all('/\{(\w+)}/', $path, $matches);

        // A path item may hoist parameters shared by all its operations, so the
        // two levels are merged before the comparison.
        $parameters = [...$document['paths'][$path]['parameters'] ?? [], ...$operation['parameters'] ?? []];

        $described = array_unique(array_column(
            array_filter($parameters, static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'path'),
            'name',
        ));

        sort($described);
        $expected = $matches[1];
        sort($expected);

        expect($described)->toBe($expected, $name.' must describe each path parameter');
    }
});

test('every $ref in the spec resolves to a component that exists', function () use ($spec): void {
    $document = $spec();

    foreach (array_unique(collectRefs($document)) as $ref) {
        expect($ref)->toStartWith('#/components/');

        $target = $document;

        foreach (explode('/', Str::after($ref, '#/')) as $segment) {
            expect(array_key_exists($segment, $target))->toBeTrue($ref.' does not resolve');
            $target = $target[$segment];
        }
    }
});

test('the spec is published as a static asset alongside the rendered docs', function () use ($repositoryPath): void {
    expect($repositoryPath('docs/public/openapi.yaml'))->toBeReadableFile();
});
