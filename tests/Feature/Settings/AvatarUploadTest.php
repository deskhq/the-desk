<?php

use App\Events\UserProfileUpdated;
use App\Models\User;
use App\Support\Avatars\AvatarStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * A fresh user with the public avatar disk faked.
 */
function avatarUser(): User
{
    Storage::fake(AvatarStorage::DISK);

    return User::factory()->create();
}

/**
 * The storage path of a stored avatar's thumbnail sibling.
 */
function avatarThumbnail(string $path): string
{
    return 'avatars/thumbnails/'.basename($path);
}

/**
 * A minimal but valid APNG: a real PNG (so it clears the `image`/`mimes:png`
 * rules) carrying an animation-control (`acTL`) chunk (so it is animated).
 */
function animatedPng(): UploadedFile
{
    $ihdr = pack('N', 13).'IHDR'.pack('N', 1).pack('N', 1)."\x08\x06\x00\x00\x00".pack('N', 0);
    $actl = pack('N', 8).'acTL'.pack('N', 1).pack('N', 0).pack('N', 0);
    $iend = pack('N', 0).'IEND'.pack('N', 0);

    return UploadedFile::fake()->createWithContent('anim.png', "\x89PNG\r\n\x1a\n".$ihdr.$actl.$iend);
}

test('a user can upload an avatar', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 800, 800)])
        ->assertRedirect();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull()
        ->and($user->avatar_url)->toContain($user->avatar_path);

    Storage::disk(AvatarStorage::DISK)->assertExists($user->avatar_path);
    Storage::disk(AvatarStorage::DISK)->assertExists(avatarThumbnail($user->avatar_path));

    Event::assertDispatched(UserProfileUpdated::class);
});

test('the stored avatar is downscaled to a square with a smaller thumbnail', function (): void {
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 1200, 1000)]);

    $user->refresh();
    $disk = Storage::disk(AvatarStorage::DISK);

    [$width, $height] = getimagesizefromstring($disk->get($user->avatar_path));
    expect(max($width, $height))->toBeLessThanOrEqual(AvatarStorage::MAX_PX);

    [$thumbWidth, $thumbHeight] = getimagesizefromstring($disk->get(avatarThumbnail($user->avatar_path)));
    expect(max($thumbWidth, $thumbHeight))->toBeLessThanOrEqual(AvatarStorage::THUMBNAIL_PX);
});

test('replacing an avatar deletes the previous blob and thumbnail', function (): void {
    $user = avatarUser();

    $this->actingAs($user)->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('first.jpg', 400, 400)]);
    $first = $user->refresh()->avatar_path;

    $this->actingAs($user)->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('second.jpg', 400, 400)]);
    $user->refresh();

    expect($user->avatar_path)->not->toBe($first);

    $disk = Storage::disk(AvatarStorage::DISK);
    $disk->assertMissing($first);
    $disk->assertMissing(avatarThumbnail($first));
    $disk->assertExists($user->avatar_path);
});

test('a user can remove their avatar', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = avatarUser();

    $this->actingAs($user)->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)]);
    $path = $user->refresh()->avatar_path;

    $this->actingAs($user)
        ->delete(route('avatar.destroy'))
        ->assertRedirect();

    $user->refresh();

    expect($user->avatar_url)->toBeNull()
        ->and($user->avatar_path)->toBeNull();

    $disk = Storage::disk(AvatarStorage::DISK);
    $disk->assertMissing($path);
    $disk->assertMissing(avatarThumbnail($path));

    Event::assertDispatched(UserProfileUpdated::class);
});

test('an oversized image is rejected with a translated error', function (): void {
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('big.jpg', 800, 800)->size(6000)])
        ->assertSessionHasErrors(['photo' => 'That file is over 5 MB. Try a smaller image.']);

    expect($user->refresh()->avatar_path)->toBeNull();
});

test('a non-image file is rejected', function (): void {
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('photo');

    expect($user->refresh()->avatar_path)->toBeNull();
});

test('a disallowed image type is rejected', function (): void {
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('logo.gif', 100, 100)])
        ->assertSessionHasErrors('photo');
});

test('an animated image is rejected with a translated error', function (): void {
    $user = avatarUser();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['photo' => animatedPng()])
        ->assertSessionHasErrors(['photo' => 'Animated images aren’t supported. Use a static JPEG, PNG or WebP.']);

    expect($user->refresh()->avatar_path)->toBeNull();
});

test('a guest cannot upload an avatar', function (): void {
    $this->post(route('avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)])
        ->assertRedirect(route('login'));
});
