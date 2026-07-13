<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use App\Rules\NotExecutableFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Uploading reuses the post-message policy: if the user could not post a
     * message to this channel, they cannot stage a file for one either.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Any type is accepted by default (arbitrary-file download is a goal), capped
     * at the configured per-file size and rejecting the executable denylist. SVG
     * is accepted but never rendered inline (the serve route forces it to
     * download).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.((int) config('attachments.max_size_mb') * 1024),
                new NotExecutableFile,
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'file.max' => __('The file may not be larger than :size MB.', ['size' => (int) config('attachments.max_size_mb')]),
        ];
    }

    /**
     * Get the channel the attachment is being uploaded to.
     */
    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
