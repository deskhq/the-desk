<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Team;
use App\Support\TeamStorage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class WithinStorageQuota implements ValidationRule
{
    public function __construct(private Team $team, private TeamStorage $storage) {}

    /**
     * Reject an upload that would push the workspace past its storage quota.
     *
     * Checked at validation time, before the blob is written, so a refused upload
     * leaves nothing on disk for the orphan sweep to reclaim. The check is
     * inherently racy under concurrent uploads — two requests can both read the
     * same usage — which is accepted: the quota is an operator's capacity guard,
     * not a billing boundary, and the overshoot is bounded by one file.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if ($this->storage->wouldExceedQuota($this->team, $value->getSize())) {
            $fail(__('This upload would exceed the workspace storage limit of :size MB. Delete some files to free up space.', [
                'size' => (int) config('attachments.storage_quota_mb'),
            ]));
        }
    }
}
