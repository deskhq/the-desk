<?php

use App\Support\GiphyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.giphy.key', 'test-key');
    config()->set('services.giphy.rating', 'g');
    Cache::flush();
});

/**
 * Build a Giphy GIF object with a usable `fixed_height` rendition.
 *
 * @return array<string, mixed>
 */
function giphyGif(string $id, string $description = 'a cat'): array
{
    return [
        'id' => $id,
        'alt_text' => $description,
        'images' => [
            'fixed_height' => ['url' => "https://media.giphy.com/{$id}/200.gif", 'width' => '360', 'height' => '200'],
            'fixed_height_small' => ['url' => "https://media.giphy.com/{$id}/100.gif", 'width' => '180', 'height' => '100'],
        ],
    ];
}

it('reports enabled only when a key is configured', function (): void {
    expect(app(GiphyClient::class)->isEnabled())->toBeTrue();

    config()->set('services.giphy.key');

    expect(app(GiphyClient::class)->isEnabled())->toBeFalse();
});

it('fetches trending when the query is blank and normalizes the renditions', function (): void {
    Http::fake([
        'api.giphy.com/v1/gifs/trending*' => Http::response([
            'data' => [giphyGif('abc', 'a happy cat')],
            'pagination' => ['total_count' => 100, 'count' => 1, 'offset' => 0],
        ]),
    ]);

    $page = app(GiphyClient::class)->search(null);

    expect($page->results)->toHaveCount(1);
    $gif = $page->results[0];
    expect($gif->id)->toBe('abc')
        ->and($gif->url)->toBe('https://media.giphy.com/abc/200.gif')
        ->and($gif->previewUrl)->toBe('https://media.giphy.com/abc/100.gif')
        ->and($gif->width)->toBe(360)
        ->and($gif->height)->toBe(200)
        ->and($gif->description)->toBe('a happy cat');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/trending')
        && $request['api_key'] === 'test-key'
        && $request['rating'] === 'g');
});

it('searches Giphy with the query and language when a query is given', function (): void {
    Http::fake([
        'api.giphy.com/v1/gifs/search*' => Http::response([
            'data' => [giphyGif('xyz')],
            'pagination' => ['total_count' => 100, 'count' => 1, 'offset' => 0],
        ]),
    ]);

    app(GiphyClient::class)->search('cats', offset: 0, limit: 24, lang: 'fr');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/search')
        && $request['q'] === 'cats'
        && $request['lang'] === 'fr');
});

it('computes the next offset from pagination and stops when exhausted', function (): void {
    Http::fakeSequence('api.giphy.com/v1/gifs/search*')
        ->push(['data' => [giphyGif('a'), giphyGif('b')], 'pagination' => ['total_count' => 3, 'count' => 2, 'offset' => 0]])
        ->push(['data' => [giphyGif('c')], 'pagination' => ['total_count' => 3, 'count' => 1, 'offset' => 2]]);

    expect(app(GiphyClient::class)->search('cats', offset: 0)->nextOffset)->toBe(2);
    expect(app(GiphyClient::class)->search('cats', offset: 2)->nextOffset)->toBeNull();
});

it('caches identical pages so the shared key is only hit once', function (): void {
    Http::fake([
        'api.giphy.com/*' => Http::response(['data' => [giphyGif('a')], 'pagination' => ['total_count' => 1, 'count' => 1, 'offset' => 0]]),
    ]);

    app(GiphyClient::class)->search('cats');
    app(GiphyClient::class)->search('cats');

    Http::assertSentCount(1);
});

it('skips GIFs without a usable animated rendition', function (): void {
    Http::fake([
        'api.giphy.com/*' => Http::response([
            'data' => [
                ['id' => 'no-images', 'images' => []],
                giphyGif('good'),
            ],
            'pagination' => ['total_count' => 2, 'count' => 2, 'offset' => 0],
        ]),
    ]);

    $page = app(GiphyClient::class)->search('cats');

    expect($page->results)->toHaveCount(1)
        ->and($page->results[0]->id)->toBe('good');
});

it('leaves the description null when a GIF has no alt text or title', function (): void {
    Http::fake([
        'api.giphy.com/*' => Http::response([
            'data' => [[
                'id' => 'plain',
                'images' => ['fixed_height' => ['url' => 'https://media.giphy.com/plain/200.gif', 'width' => '100', 'height' => '100']],
            ]],
            'pagination' => ['total_count' => 1, 'count' => 1, 'offset' => 0],
        ]),
    ]);

    $page = app(GiphyClient::class)->search('cats');

    expect($page->results[0]->description)->toBeNull();
});

it('degrades to an empty page when Giphy errors', function (): void {
    Http::fake(['api.giphy.com/*' => Http::response(null, 500)]);

    $page = app(GiphyClient::class)->search('cats');

    expect($page->results)->toBe([])
        ->and($page->nextOffset)->toBeNull();
});

it('degrades to an empty page on a connection failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $page = app(GiphyClient::class)->search('cats');

    expect($page->results)->toBe([])
        ->and($page->nextOffset)->toBeNull();
});

it('returns null when resolving hits a connection failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(app(GiphyClient::class)->resolve('abc'))->toBeNull();
});

it('re-resolves an opaque id to authoritative media', function (): void {
    Http::fake([
        'api.giphy.com/v1/gifs/abc*' => Http::response(['data' => giphyGif('abc', 'a dog')]),
    ]);

    $gif = app(GiphyClient::class)->resolve('abc');

    expect($gif)->not->toBeNull()
        ->and($gif->id)->toBe('abc')
        ->and($gif->url)->toBe('https://media.giphy.com/abc/200.gif')
        ->and($gif->description)->toBe('a dog');
});

it('returns null when resolving an unknown id', function (): void {
    Http::fake(['api.giphy.com/*' => Http::response(['meta' => ['status' => 404]], 404)]);

    expect(app(GiphyClient::class)->resolve('nope'))->toBeNull();
});

it('returns null when resolving an id whose data is empty', function (): void {
    Http::fake(['api.giphy.com/*' => Http::response(['data' => []])]);

    expect(app(GiphyClient::class)->resolve('empty'))->toBeNull();
});
