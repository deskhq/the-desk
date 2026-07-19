<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\User;

/**
 * A team owner in their seeded #general channel, plus the message-store URL.
 *
 * @return array{0: User, 1: string}
 */
function demoWriter(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug])];
}

test('demo mode rate-limits message sends by IP', function (): void {
    $this->reloadWithDemoMode(true);
    [$owner, $url] = demoWriter();

    $this->actingAs($owner);

    // An empty body is rejected by validation, but the throttle increments before
    // the controller runs, so 30 attempts exhaust the per-minute cap without
    // creating a single message.
    foreach (range(1, 30) as $ignored) {
        $this->post($url, [])->assertStatus(302);
    }

    $this->post($url, [])->assertStatus(429);

    // A request from a different IP has its own bucket — proving the throttle is
    // keyed by IP, not by the (shared) user.
    $this->call('POST', $url, [], [], [], ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertStatus(302);
});

test('message sends are not rate-limited when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);
    [$owner, $url] = demoWriter();

    $this->actingAs($owner);

    foreach (range(1, 31) as $ignored) {
        $this->post($url, [])->assertStatus(302);
    }
});

test('demo mode rate-limits attachment uploads by IP', function (): void {
    $this->reloadWithDemoMode(true);

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $url = route('channels.attachments.store', ['team' => $team->slug, 'channel' => $general->slug]);

    $this->actingAs($owner);

    foreach (range(1, 10) as $ignored) {
        $this->post($url, [])->assertStatus(302);
    }

    $this->post($url, [])->assertStatus(429);
});

test('attachment uploads are not rate-limited when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $url = route('channels.attachments.store', ['team' => $team->slug, 'channel' => $general->slug]);

    $this->actingAs($owner);

    foreach (range(1, 11) as $ignored) {
        $this->post($url, [])->assertStatus(302);
    }
});
