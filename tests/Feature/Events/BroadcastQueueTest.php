<?php

declare(strict_types=1);

use App\Data\UserData;
use App\Events\UserTyping;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * Every broadcast is a queued job, so it shares a worker with `UnfurlMessageLinks`
 * (up to 5 seconds of outbound HTTP), webhook delivery, exports, and queued mail.
 * One unfurl in flight stalls every message, reaction, and typing indicator
 * behind it — the second half of the lag in issue #763. Routing broadcasts onto
 * their own queue is what lets a dedicated worker drain them.
 */
test('a dispatched broadcast is queued away from the shared default queue', function (): void {
    Queue::fake();

    $channel = Channel::factory()->create();

    event(new UserTyping($channel, UserData::fromUser(User::factory()->create())));

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
});

/**
 * The route is registered against the `ShouldBroadcast` *interface*, which
 * `QueueRoutes::getRoute` matches alongside the class itself — so it covers the
 * fourteenth event as well as the thirteen that exist today, with no per-event
 * edit to forget. This proves that for every event actually in the app.
 */
test('every broadcast event in the app routes to the broadcasts queue', function (): void {
    $events = collect(File::files(app_path('Events')))
        ->map(static fn (SplFileInfo $file): string => 'App\\Events\\'.$file->getBasename('.php'))
        ->filter(static fn (string $event): bool => is_subclass_of($event, ShouldBroadcast::class));

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        // Never constructed: the route matches on the class and its interfaces,
        // so the event's own dependencies are irrelevant here.
        $queue = Queue::resolveQueueFromQueueRoute(
            (new ReflectionClass($event))->newInstanceWithoutConstructor(),
        );

        expect($queue)->toBe('broadcasts', $event.' would broadcast off the shared default queue');
    }
});

/**
 * A blocking pop returns the instant a job lands, so an idle worker stops
 * polling-and-napping. The default is bounded at one second rather than five
 * because the shared worker blocks only on `broadcasts` (the first queue in its
 * list) and polls `default` once per block cycle — so this value is also how
 * long a slow job can sit before it starts.
 */
test('the redis queue blocks for a job rather than polling and sleeping', function (): void {
    expect(config('queue.connections.redis.block_for'))->toBe(1);
});

test('an operator can tune how long the worker blocks', function (): void {
    $this->reloadWithEnv(['REDIS_QUEUE_BLOCK_FOR' => 5]);

    expect(config('queue.connections.redis.block_for'))->toBe(5);
});

/**
 * The driver hands this value to `BLPOP`, where a timeout of 0 means "wait
 * forever" — the opposite of what an operator typing it would mean. Unfloored,
 * the shared worker would block on `broadcasts` indefinitely and never poll
 * `default`, so mail, unfurls, and webhooks would only ever start when a
 * broadcast happened to arrive.
 */
test('a zero blocking window is floored rather than read as wait forever', function (int|string $value): void {
    $this->reloadWithEnv(['REDIS_QUEUE_BLOCK_FOR' => $value]);

    expect(config('queue.connections.redis.block_for'))->toBe(1);
})->with([0, -1, 'nonsense']);
