<?php

declare(strict_types=1);

use App\Support\Unfurl\CachingUnfurler;
use App\Support\Unfurl\UnfurledPreview;
use App\Support\Unfurl\Unfurler;
use App\Support\Unfurl\UnfurlerUnavailable;
use Illuminate\Support\Facades\Cache;

/**
 * An Unfurler that answers from a fixed table and counts what it was asked.
 *
 * @param  array<string, UnfurledPreview|null>  $answers
 */
function recordingUnfurler(array $answers, bool $unavailable = false): Unfurler
{
    return new class($answers, $unavailable) implements Unfurler
    {
        /** @var array<int, list<string>> */
        public array $batches = [];

        /**
         * @param  array<string, UnfurledPreview|null>  $answers
         */
        public function __construct(private array $answers, private readonly bool $unavailable) {}

        #[Override]
        public function unfurl(array $urls): array
        {
            $this->batches[] = $urls;

            if ($this->unavailable) {
                throw UnfurlerUnavailable::badStatus(503);
            }

            $result = [];

            foreach ($urls as $url) {
                $result[$url] = $this->answers[$url] ?? null;
            }

            return $result;
        }
    };
}

function aPreview(string $title = 'Hello'): UnfurledPreview
{
    return new UnfurledPreview($title, null, null, null);
}

it('forwards a cache miss and remembers what came back', function (): void {
    $inner = recordingUnfurler(['https://a.test' => aPreview()]);

    $first = (new CachingUnfurler($inner))->unfurl(['https://a.test']);
    $second = (new CachingUnfurler($inner))->unfurl(['https://a.test']);

    expect($first['https://a.test']->title)->toBe('Hello')
        ->and($second['https://a.test']->title)->toBe('Hello')
        ->and($inner->batches)->toHaveCount(1);
});

it('remembers a refusal too, so a dead link is not re-dialled per message', function (): void {
    $inner = recordingUnfurler(['https://dead.test' => null]);

    (new CachingUnfurler($inner))->unfurl(['https://dead.test']);
    $second = (new CachingUnfurler($inner))->unfurl(['https://dead.test']);

    expect($second)->toBe(['https://dead.test' => null])
        ->and($inner->batches)->toHaveCount(1);
});

it('forwards only the URLs it has no answer for', function (): void {
    Cache::put('link-preview:'.sha1('https://cached.test'), aPreview('Cached'), now()->addHour());

    $inner = recordingUnfurler(['https://fresh.test' => aPreview('Fresh')]);

    $result = (new CachingUnfurler($inner))->unfurl(['https://cached.test', 'https://fresh.test']);

    expect($inner->batches)->toBe([['https://fresh.test']])
        ->and($result['https://cached.test']->title)->toBe('Cached')
        ->and($result['https://fresh.test']->title)->toBe('Fresh');
});

it('asks nothing at all when every URL is already known', function (): void {
    Cache::put('link-preview:'.sha1('https://a.test'), aPreview(), now()->addHour());

    $inner = recordingUnfurler([]);

    (new CachingUnfurler($inner))->unfurl(['https://a.test']);

    expect($inner->batches)->toBeEmpty();
});

it('returns one entry per URL, in the order they were asked', function (): void {
    Cache::put('link-preview:'.sha1('https://b.test'), aPreview('B'), now()->addHour());

    $inner = recordingUnfurler(['https://a.test' => aPreview('A'), 'https://c.test' => aPreview('C')]);

    $result = (new CachingUnfurler($inner))->unfurl(['https://a.test', 'https://b.test', 'https://c.test']);

    expect(array_keys($result))->toBe(['https://a.test', 'https://b.test', 'https://c.test']);
});

it('has nothing to ask about for an empty batch', function (): void {
    $inner = recordingUnfurler([]);

    expect((new CachingUnfurler($inner))->unfurl([]))->toBe([])
        ->and($inner->batches)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| The distinction this class exists for
|--------------------------------------------------------------------------
|
| A link with no preview is a fact about the link and is remembered for a day.
| A service that cannot be reached is a fact about the stack, and remembering it
| would let a five-minute outage decide a day of good links are all broken.
*/
it('does not remember a failure to reach the service', function (): void {
    $unavailable = recordingUnfurler([], unavailable: true);

    $first = (new CachingUnfurler($unavailable))->unfurl(['https://a.test']);

    expect($first)->toBe(['https://a.test' => null])
        ->and(Cache::has('link-preview:'.sha1('https://a.test')))->toBeFalse();

    // The service comes back, and the very next batch asks about the same link.
    $recovered = recordingUnfurler(['https://a.test' => aPreview('Back')]);

    expect((new CachingUnfurler($recovered))->unfurl(['https://a.test'])['https://a.test']->title)
        ->toBe('Back')
        ->and($recovered->batches)->toBe([['https://a.test']]);
});

it('still serves what it already knew while the service is down', function (): void {
    Cache::put('link-preview:'.sha1('https://known.test'), aPreview('Known'), now()->addHour());

    $result = (new CachingUnfurler(recordingUnfurler([], unavailable: true)))
        ->unfurl(['https://known.test', 'https://new.test']);

    expect($result['https://known.test']->title)->toBe('Known')
        ->and($result['https://new.test'])->toBeNull();
});

it('remembers a decision for the configured lifetime', function (): void {
    config(['unfurl.cache_ttl' => 60]);

    (new CachingUnfurler(recordingUnfurler(['https://a.test' => aPreview()])))->unfurl(['https://a.test']);

    $this->travel(59)->seconds();
    expect(Cache::has('link-preview:'.sha1('https://a.test')))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has('link-preview:'.sha1('https://a.test')))->toBeFalse();
});
