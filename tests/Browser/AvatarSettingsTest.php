<?php

declare(strict_types=1);

use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Open Settings → Profile through the user menu, keeping the visit client-side
 * (a hard navigate would drop the in-process browser session), matching
 * SidebarPositionTest.
 */
function visitProfileSettings(User $user): AwaitableWebpage
{
    return signInThroughBrowser($user)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings/profile');
}

test('the photo block shows an upload control and no remove when there is no uploaded avatar', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    visitProfileSettings($alice)
        ->assertSee('Upload photo')
        ->assertMissing('@remove-avatar-button');
});

test('a user removes their uploaded photo and the block reverts to upload-only', function (): void {
    // Removing broadcasts UserProfileUpdated; with the sync queue there is no
    // Reverb to reach, so pin the null broadcaster (in production the broadcast
    // is queued and never blocks the request). The config override reaches the
    // in-process server, like useReverbForBrowserTests().
    config(['broadcasting.default' => 'null']);

    ['owner' => $alice] = browserTeamWithChannel();
    $alice->forceFill([
        'avatar_url' => 'https://desk.test/storage/avatars/alice.jpg',
        'avatar_path' => 'avatars/alice.jpg',
    ])->save();

    visitProfileSettings($alice)
        ->assertPresent('@remove-avatar-button')
        ->assertSee('Replace photo')
        ->click('@remove-avatar-button')
        // The DELETE redirects back; the refreshed prop drops the Remove button
        // and the primary control returns to "Upload photo".
        ->assertMissing('@remove-avatar-button')
        ->assertSee('Upload photo');
});
