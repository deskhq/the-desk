<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\UploadAttachment;
use App\Data\AttachmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreAttachmentRequest;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Team;
use App\Policies\AttachmentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Pre-upload a file to the channel, returning the pending attachment.
     *
     * This is the first phase of the two-phase upload: the file is stored and
     * registered immediately (so the composer gets a real progress bar and an id
     * to remember), then claimed later by the message that sends it. Uploading is
     * authorized by the post-message policy (see {@see StoreAttachmentRequest}).
     */
    public function store(StoreAttachmentRequest $request, Team $team, Channel $channel, UploadAttachment $uploadAttachment): JsonResponse
    {
        $attachment = $uploadAttachment->handle($channel, $request->user(), $request->file('file'));

        return response()->json(AttachmentData::fromAttachment($attachment), 201);
    }

    /**
     * Stream an attachment to an authorized channel member.
     *
     * The route scopes `$attachment` to `$channel`, so a file from another
     * channel 404s before reaching here. Authorization then mirrors reading the
     * message (see {@see AttachmentPolicy::view()}); a denied view
     * becomes a 404, never a 403, so the endpoint never confirms a file it won't
     * serve exists. Images render inline; everything else — SVG included — is
     * forced to download, and `nosniff` stops the browser from second-guessing
     * the declared type.
     */
    public function download(Request $request, Team $team, Channel $channel, Attachment $attachment): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $attachment), 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response(
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            $attachment->isImage() ? 'inline' : 'attachment',
        );
    }
}
