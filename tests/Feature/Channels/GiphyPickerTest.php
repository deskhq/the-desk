<?php

use App\Actions\Channels\CreateGiphyAttachment;
use App\Actions\Teams\CreateTeam;
use App\Data\GiphyGifData;
use App\Enums\AttachmentSource;
use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Create a team with its owner in #general and Giphy configured.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function giphyTeam(): array
{
    config()->set('services.giphy.key', 'test-key');
    config()->set('services.giphy.rating', 'g');

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * A Giphy GIF object with a usable `fixed_height` rendition.
 *
 * @return array<string, mixed>
 */
function fakeGiphyGif(string $id, string $description = 'a cat'): array
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

test('a channel member can search Giphy, scoped to their locale', function (): void {
    [$owner, $team, $general] = giphyTeam();
    $owner->update(['locale' => 'fr']);

    Http::fake(['api.giphy.com/v1/gifs/search*' => Http::response([
        'data' => [fakeGiphyGif('abc', 'a happy cat')],
        'pagination' => ['total_count' => 100, 'count' => 1, 'offset' => 0],
    ])]);

    $response = $this->actingAs($owner)
        ->getJson(route('channels.gifs.search', ['team' => $team->slug, 'channel' => $general->slug, 'q' => 'cats']));

    $response->assertOk()
        ->assertJsonPath('results.0.id', 'abc')
        ->assertJsonPath('results.0.url', 'https://media.giphy.com/abc/200.gif')
        ->assertJsonPath('results.0.description', 'a happy cat')
        ->assertJsonPath('nextOffset', 1);

    Http::assertSent(fn ($request): bool => $request['q'] === 'cats' && $request['lang'] === 'fr');
});

test('a blank query returns Giphy trending', function (): void {
    [$owner, $team, $general] = giphyTeam();

    Http::fake(['api.giphy.com/v1/gifs/trending*' => Http::response([
        'data' => [fakeGiphyGif('trend')],
        'pagination' => ['total_count' => 100, 'count' => 1, 'offset' => 0],
    ])]);

    $this->actingAs($owner)
        ->getJson(route('channels.gifs.search', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertJsonPath('results.0.id', 'trend');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/trending'));
});

test('search 404s when Giphy is not configured', function (): void {
    [$owner, $team, $general] = giphyTeam();
    config()->set('services.giphy.key');

    $this->actingAs($owner)
        ->getJson(route('channels.gifs.search', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertNotFound();
});

test('a non-member cannot search a channel they cannot post to', function (): void {
    [, $team, $general] = giphyTeam();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('channels.gifs.search', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();
});

test('picking a GIF re-resolves its id and stores a pending remote attachment', function (): void {
    [$owner, $team, $general] = giphyTeam();

    Http::fake(['api.giphy.com/v1/gifs/abc*' => Http::response(['data' => fakeGiphyGif('abc', 'a dog waving')])]);

    $response = $this->actingAs($owner)
        ->postJson(route('channels.gifs.store', ['team' => $team->slug, 'channel' => $general->slug]), ['id' => 'abc']);

    $response->assertCreated()
        ->assertJsonPath('source', 'giphy')
        ->assertJsonPath('url', 'https://media.giphy.com/abc/200.gif')
        ->assertJsonPath('description', 'a dog waving')
        ->assertJsonPath('isImage', true)
        ->assertJsonPath('mimeType', 'image/gif');

    $attachment = Attachment::sole();
    expect($attachment->source)->toBe(AttachmentSource::Giphy)
        ->and($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->user_id)->toBe($owner->id)
        ->and($attachment->channel_id)->toBe($general->id)
        ->and($attachment->message_id)->toBeNull()
        ->and($attachment->disk)->toBeNull()
        ->and($attachment->path)->toBeNull()
        ->and($attachment->remote_url)->toBe('https://media.giphy.com/abc/200.gif')
        ->and($attachment->width)->toBe(360)
        ->and($attachment->height)->toBe(200);
});

test('picking a GIF Giphy no longer knows is rejected', function (): void {
    [$owner, $team, $general] = giphyTeam();

    Http::fake(['api.giphy.com/*' => Http::response(['meta' => ['status' => 404]], 404)]);

    $this->actingAs($owner)
        ->postJson(route('channels.gifs.store', ['team' => $team->slug, 'channel' => $general->slug]), ['id' => 'gone'])
        ->assertStatus(422);

    expect(Attachment::count())->toBe(0);
});

test('attaching a GIF 404s when Giphy is not configured', function (): void {
    [$owner, $team, $general] = giphyTeam();
    config()->set('services.giphy.key');

    $this->actingAs($owner)
        ->postJson(route('channels.gifs.store', ['team' => $team->slug, 'channel' => $general->slug]), ['id' => 'abc'])
        ->assertNotFound();
});

test('CreateGiphyAttachment stores a pending remote row with its channel and team loaded', function (): void {
    [$owner, , $general] = giphyTeam();

    $gif = new GiphyGifData(
        id: 'abc',
        url: 'https://media.giphy.com/abc/200.gif',
        previewUrl: 'https://media.giphy.com/abc/100.gif',
        width: 360,
        height: 200,
        description: 'a dog waving',
    );

    $attachment = app(CreateGiphyAttachment::class)->handle($general, $owner, $gif);

    expect($attachment->source)->toBe(AttachmentSource::Giphy)
        ->and($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->user_id)->toBe($owner->id)
        ->and($attachment->channel_id)->toBe($general->id)
        ->and($attachment->message_id)->toBeNull()
        ->and($attachment->disk)->toBeNull()
        ->and($attachment->path)->toBeNull()
        ->and($attachment->remote_url)->toBe('https://media.giphy.com/abc/200.gif')
        ->and($attachment->description)->toBe('a dog waving')
        ->and($attachment->width)->toBe(360)
        ->and($attachment->height)->toBe(200)
        // The channel is returned with its team loaded, so the DTO's url accessor
        // resolves N+1-free.
        ->and($attachment->relationLoaded('channel'))->toBeTrue()
        ->and($attachment->channel->relationLoaded('team'))->toBeTrue();
});

test('a sent GIF is claimed by its message through the ordinary attachment flow', function (): void {
    [$owner, $team, $general] = giphyTeam();

    $gif = Attachment::factory()->giphy()->create([
        'user_id' => $owner->id,
        'channel_id' => $general->id,
    ]);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'check this out',
            'client_uuid' => (string) Str::uuid(),
            'attachment_ids' => [$gif->id],
        ])
        ->assertRedirect();

    $gif->refresh();
    expect($gif->status)->toBe(AttachmentStatus::Attached)
        ->and($gif->message_id)->not->toBeNull();
});
