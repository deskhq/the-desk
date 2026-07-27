<?php

declare(strict_types=1);

use App\Enums\AttachmentStatus;
use App\Enums\SecurityEventType;
use App\Models\Attachment;
use App\Models\AuditExport;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SecurityEvent;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Storage;

/**
 * The screens the mobile design does not draw (#778): admin settings pages,
 * browse, search, the marketing page and the lightbox, held to the epic's
 * breakpoint rules at a phone width — nothing clipped, long strings intact,
 * 44px touch targets.
 */

/**
 * Whether the first element the selector matches renders its full text — i.e.
 * an ellipsis is not hiding part of it — and sits inside the viewport.
 */
function rendersFullTextInsideViewport(string $selector): string
{
    return <<<JS
    (() => {
        const el = document.querySelector('{$selector}');

        return el !== null
            && el.scrollWidth <= el.clientWidth + 1
            && el.getBoundingClientRect().right <= window.innerWidth + 1;
    })()
    JS;
}

/**
 * Whether every element the selector matches offers at least a 44px-tall
 * touch target. Rounded before comparing: `h-11` is exactly 44 CSS px, but
 * the rect can report a sub-pixel sliver under it.
 */
function offersPhoneTouchTargets(string $selector): string
{
    return <<<JS
    (() => {
        const targets = [...document.querySelectorAll('{$selector}')];

        return targets.length > 0
            && targets.every(el => Math.round(el.getBoundingClientRect().height) >= 44);
    })()
    JS;
}

test('the audit-export period fields stay reachable on a phone', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/settings/teams/{$team->slug}/exports")
        ->assertScript(<<<'JS'
        (() => {
            const end = document.querySelector('[data-test="audit-export-range-end"]');

            return end !== null
                && end.getBoundingClientRect().right <= window.innerWidth + 1;
        })()
        JS, true);
});

test('an audit-export row keeps its meta line readable on a phone', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $export = AuditExport::factory()->security()->ready()->create([
        'team_id' => $team->id,
        'requested_by' => $alice->id,
    ]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/settings/teams/{$team->slug}/exports")
        ->assertScript(rendersFullTextInsideViewport(
            "[data-test=\"audit-export-row-{$export->id}\"] p + p",
        ), true);
});

test('a security-log row keeps a long member name readable on a phone', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $alice->update(['name' => 'Bartholomew Featherstonehaugh']);

    $event = SecurityEvent::factory()
        ->ofType(SecurityEventType::LoggedIn)
        ->create(['user_id' => $alice->id]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/settings/teams/{$team->slug}/security-log")
        ->assertScript(rendersFullTextInsideViewport(
            "[data-test=\"security-log-event-{$event->id}\"] span + div span",
        ), true);
});

test('a group row keeps its handle readable and its actions tappable on a phone', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    UserGroup::factory()->create([
        'team_id' => $team->id,
        'name' => 'Engineering Leadership',
        'slug' => 'engineering-leadership',
    ]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/settings/teams/{$team->slug}/groups")
        ->assertScript(rendersFullTextInsideViewport(
            '[data-test="group-row-engineering-leadership"] span[class*="font-mono"]',
        ), true)
        ->assertScript(offersPhoneTouchTargets(
            '[data-test="group-edit-engineering-leadership"], [data-test="group-remove-engineering-leadership"]',
        ), true);
});

test('the browse-channels heading survives a phone width untruncated', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    Channel::factory()->create([
        'team_id' => $team->id,
        'created_by' => $alice->id,
        'name' => 'design-reviews',
        'slug' => 'design-reviews',
    ]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/t/{$team->slug}/channels/browse")
        ->assertScript(rendersFullTextInsideViewport('h1'), true)
        ->assertScript(offersPhoneTouchTargets('button[type="submit"]'), true);
});

test('the search facet pickers offer 44px touch targets on a phone', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate("/t/{$team->slug}/search?q=hello")
        ->assertScript(offersPhoneTouchTargets(
            '[data-test="facet-author-picker"], [data-test="facet-channel-picker"], [data-test="facet-date-picker"]',
        ), true)
        ->click('[data-test="facet-author-picker"]')
        ->assertScript(offersPhoneTouchTargets('[data-test="facet-author-option"]'), true);
});

test('the disclosed composer tools offer 44px touch targets on a phone', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('[data-test="composer-tools-toggle"]')
        ->assertScript(offersPhoneTouchTargets(
            '[data-test="composer-format-cluster"] button, [data-test="message-composer-attach"]',
        ), true);
});

test('the welcome preview thread pill stays inside its row on a phone', function (): void {
    visit('/')
        ->resize(360, 740)
        ->assertScript(<<<'JS'
        (() => {
            const matches = [...document.querySelectorAll('span')]
                .filter(el => el.textContent.includes('4 replies'));
            const pill = matches[matches.length - 1];

            if (!pill) {
                return false;
            }

            const row = pill.parentElement.getBoundingClientRect();

            return pill.getBoundingClientRect().right <= row.right + 1
                && pill.getBoundingClientRect().right <= window.innerWidth + 1;
        })()
        JS, true);
});

test('the poll builder fits a landscape phone viewport', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(740, 360)
        ->navigate(browserChannelUrl($team, $channel))
        ->type('[data-test="message-composer-input"]', '/poll')
        ->keys('[data-test="message-composer-input"]', ['Enter'])
        ->assertScript(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="poll-builder"]');

            if (!panel) {
                return false;
            }

            const rect = panel.getBoundingClientRect();

            return rect.top >= -1 && rect.bottom <= window.innerHeight + 1;
        })()
        JS, true);
});

test('the lightbox truncates a long filename clear of its header buttons', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'Here is the screenshot.',
    ]);

    $attachment = Attachment::factory()->create([
        'message_id' => $message->id,
        'user_id' => $alice->id,
        'channel_id' => $channel->id,
        'original_filename' => 'quarterly-platform-infrastructure-review-deployment-screenshot-final-v2.png',
        'status' => AttachmentStatus::Attached,
    ]);

    // A real blob behind the record: without it the image request 404s and the
    // lightbox trigger never gets a stable click target.
    Storage::disk($attachment->disk)->put(
        $attachment->path,
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true),
    );

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('[data-test="attachment-image"]')
        // The image stays inside the viewport even though its stored box
        // (800 × 600 → 320 × 240) is wider than the phone's content column.
        ->assertScript(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-test="attachment-image"]');

            return trigger !== null
                && trigger.getBoundingClientRect().right <= window.innerWidth + 1;
        })()
        JS, true)
        // A scripted click: a pointer click can race the timeline's settling
        // scroll and land on the hover toolbar instead of the image.
        ->assertScript(<<<'JS'
        (() => {
            document.querySelector('[data-test="attachment-image"]').click();

            return true;
        })()
        JS, true)
        ->assertPresent('[data-test="attachment-lightbox"]')
        ->assertScript(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-test="attachment-lightbox"]');
            const title = dialog?.querySelector('h2');
            const download = dialog?.querySelector('a[download]');

            return Boolean(title) && Boolean(download)
                && title.getBoundingClientRect().right <= download.getBoundingClientRect().left;
        })()
        JS, true);
});
