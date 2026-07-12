<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\LinkPreviewStatus;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Jobs\UnfurlMessageLinks;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\FetchLinkPreview;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function unfurlTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Post a message body to #general and return the persisted model.
 */
function postBody(User $owner, $team, Channel $general, string $body): Message
{
    $clientUuid = (string) Str::uuid7();

    test()->actingAs($owner)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), ['body' => $body, 'client_uuid' => $clientUuid]);

    return Message::where('client_uuid', $clientUuid)->firstOrFail();
}

/**
 * A FetchLinkPreview stub that returns a fixed result for every URL.
 *
 * @param  array{title: string, description: string|null, image: string|null, siteName: string|null}|null  $result
 */
function fakeFetcher(?array $result): FetchLinkPreview
{
    return new class($result) extends FetchLinkPreview
    {
        /**
         * @param  array{title: string, description: string|null, image: string|null, siteName: string|null}|null  $result
         */
        public function __construct(private readonly ?array $result) {}

        public function handle(string $url): ?array
        {
            return $this->result;
        }
    };
}

test('posting a message with URLs creates ordered pending previews and queues the unfurl', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();

    $message = postBody($owner, $team, $general, 'See https://example.com and https://other.test today');

    $previews = $message->linkPreviews()->orderBy('position')->get();

    expect($previews)->toHaveCount(2)
        ->and($previews[0]->url)->toBe('https://example.com')
        ->and($previews[0]->position)->toBe(0)
        ->and($previews[0]->status)->toBe(LinkPreviewStatus::Pending)
        ->and($previews[1]->url)->toBe('https://other.test')
        ->and($previews[1]->position)->toBe(1);

    Bus::assertDispatched(UnfurlMessageLinks::class);
});

test('link extraction caps at three URLs and dedupes', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();

    $message = postBody($owner, $team, $general, 'https://a.test https://b.test https://c.test https://d.test https://a.test');

    expect($message->linkPreviews()->pluck('url')->all())
        ->toBe(['https://a.test', 'https://b.test', 'https://c.test']);
});

test('a trailing punctuation mark is not part of the extracted URL', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();

    $message = postBody($owner, $team, $general, 'Look at https://example.com/page.');

    expect($message->linkPreviews()->value('url'))->toBe('https://example.com/page');
});

test('a message without URLs creates no previews and queues no job', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();

    $message = postBody($owner, $team, $general, 'Just a plain message');

    expect($message->linkPreviews()->count())->toBe(0);

    Bus::assertNotDispatched(UnfurlMessageLinks::class);
});

test('the initial MessageSent broadcast carries the pending previews', function (): void {
    Bus::fake();
    Event::fake([MessageSent::class]);

    [$owner, $team, $general] = unfurlTeam();

    postBody($owner, $team, $general, 'Read https://example.com now');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
        $previews = $event->broadcastWith()['linkPreviews'];

        return count($previews) === 1
            && $previews[0]['status'] === 'pending'
            && $previews[0]['url'] === 'https://example.com';
    });
});

test('the unfurl job resolves pending previews and broadcasts the enriched message', function (): void {
    Event::fake([MessageUpdated::class]);

    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0]);

    (new UnfurlMessageLinks($message->id))->handle(fakeFetcher([
        'title' => 'Example',
        'description' => 'A description',
        'image' => 'https://example.com/i.png',
        'siteName' => 'Example',
    ]));

    $preview = $message->linkPreviews()->firstOrFail();

    expect($preview->status)->toBe(LinkPreviewStatus::Ready)
        ->and($preview->title)->toBe('Example')
        ->and($preview->description)->toBe('A description')
        ->and($preview->image_url)->toBe('https://example.com/i.png')
        ->and($preview->site_name)->toBe('Example');

    Event::assertDispatched(MessageUpdated::class, function (MessageUpdated $event): bool {
        $previews = $event->broadcastWith()['linkPreviews'];

        return count($previews) === 1
            && $previews[0]['status'] === 'ready'
            && $previews[0]['title'] === 'Example';
    });
});

test('the unfurl job marks a preview failed when it cannot be fetched', function (): void {
    Event::fake([MessageUpdated::class]);

    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->linkPreviews()->create(['url' => 'https://blocked.internal', 'position' => 0]);

    (new UnfurlMessageLinks($message->id))->handle(fakeFetcher(null));

    expect($message->linkPreviews()->value('status'))->toBe(LinkPreviewStatus::Failed);

    // A failed preview is dropped from the payload, so no broken card renders.
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->broadcastWith()['linkPreviews'] === []);
});

test('the unfurl job bails quietly when the message is gone or deleted', function (bool $delete): void {
    Event::fake([MessageUpdated::class]);

    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0]);

    $id = $message->id;

    if ($delete) {
        $message->delete();
    } else {
        $id = (string) Str::uuid7();
    }

    (new UnfurlMessageLinks($id))->handle(fakeFetcher(['title' => 'x', 'description' => null, 'image' => null, 'siteName' => null]));

    expect($message->linkPreviews()->value('status'))->toBe(LinkPreviewStatus::Pending);

    Event::assertNotDispatched(MessageUpdated::class);
})->with([true, false]);

test('the unfurl job does nothing when no previews are pending', function (): void {
    Event::fake([MessageUpdated::class]);

    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0, 'status' => LinkPreviewStatus::Ready]);

    (new UnfurlMessageLinks($message->id))->handle(fakeFetcher(null));

    Event::assertNotDispatched(MessageUpdated::class);
});

test('editing a message drops previews for removed URLs and queues the new ones', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();
    $message = postBody($owner, $team, $general, 'First https://old.test link');

    $this->actingAs($owner)->patch(route('channels.messages.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]), ['body' => 'Now https://new.test instead']);

    expect($message->linkPreviews()->pluck('url')->all())->toBe(['https://new.test']);

    Bus::assertDispatchedTimes(UnfurlMessageLinks::class, 2);
});

test('editing preserves an already-resolved preview and queues nothing new', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();
    $message = postBody($owner, $team, $general, 'Keep https://kept.test around');
    $message->linkPreviews()->update(['status' => LinkPreviewStatus::Ready, 'title' => 'Kept']);

    $this->actingAs($owner)->patch(route('channels.messages.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]), ['body' => 'Keep https://kept.test around, edited']);

    $preview = $message->linkPreviews()->firstOrFail();

    expect($preview->status)->toBe(LinkPreviewStatus::Ready)
        ->and($preview->title)->toBe('Kept');

    // Only the original post queued a job; the edit added no new link.
    Bus::assertDispatchedTimes(UnfurlMessageLinks::class, 1);
});

test('editing to reorder links reassigns positions without collision', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();
    $message = postBody($owner, $team, $general, 'https://one.test then https://two.test');

    $this->actingAs($owner)->patch(route('channels.messages.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]), ['body' => 'https://two.test then https://one.test']);

    expect($message->linkPreviews()->orderBy('position')->pluck('url')->all())
        ->toBe(['https://two.test', 'https://one.test']);
});

test('editing to remove every link clears the previews', function (): void {
    Bus::fake();

    [$owner, $team, $general] = unfurlTeam();
    $message = postBody($owner, $team, $general, 'A link https://gone.test here');

    $this->actingAs($owner)->patch(route('channels.messages.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]), ['body' => 'No links anymore']);

    expect($message->linkPreviews()->count())->toBe(0);
});

test('a preview belongs to its message', function (): void {
    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $preview = $message->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0]);

    expect($preview->message->is($message))->toBeTrue();
});

test('a deleted message carries no link previews in its payload', function (): void {
    [$owner, , $general] = unfurlTeam();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->linkPreviews()->create(['url' => 'https://example.com', 'position' => 0, 'status' => LinkPreviewStatus::Ready]);
    $message->delete();

    $message->load(['user', 'mentionedUsers', 'linkPreviews']);

    expect(MessageData::fromMessage($message)->linkPreviews)->toBe([]);
});
