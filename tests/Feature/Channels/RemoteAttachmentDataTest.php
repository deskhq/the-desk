<?php

use App\Data\AttachmentData;
use App\Enums\AttachmentSource;
use App\Models\Attachment;
use App\Support\Images\ImageProxy;

it('serves a giphy attachment through the first-party image proxy, bypassing the blob route', function (): void {
    $attachment = Attachment::factory()->giphy()->create([
        'remote_url' => 'https://media.giphy.com/media/abc123/giphy.gif',
    ]);

    expect($attachment->source)->toBe(AttachmentSource::Giphy)
        ->and($attachment->url)->toBe(ImageProxy::url('https://media.giphy.com/media/abc123/giphy.gif'))
        ->and($attachment->thumb_url)->toBeNull();
});

it('exposes source, remote url and description through AttachmentData for a giphy row', function (): void {
    $attachment = Attachment::factory()->giphy()->create([
        'remote_url' => 'https://media.giphy.com/media/abc123/giphy.gif',
        'description' => 'a cat waving',
        'width' => 480,
        'height' => 270,
    ]);

    $data = AttachmentData::fromAttachment($attachment);

    expect($data->source)->toBe(AttachmentSource::Giphy)
        ->and($data->url)->toBe(ImageProxy::url('https://media.giphy.com/media/abc123/giphy.gif'))
        ->and($data->thumbUrl)->toBeNull()
        ->and($data->description)->toBe('a cat waving')
        ->and($data->filename)->toBeNull()
        ->and($data->mimeType)->toBe('image/gif')
        ->and($data->isImage)->toBeTrue()
        ->and($data->width)->toBe(480)
        ->and($data->height)->toBe(270);
});

it('still serves an uploaded attachment through the authorized download route', function (): void {
    $attachment = Attachment::factory()->create();
    $attachment->load('channel.team');

    expect($attachment->source)->toBe(AttachmentSource::Upload)
        ->and($attachment->url)->toContain('/attachments/'.$attachment->id.'/download');

    $data = AttachmentData::fromAttachment($attachment);

    expect($data->source)->toBe(AttachmentSource::Upload)
        ->and($data->description)->toBeNull();
});
