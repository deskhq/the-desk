<?php

namespace App\Http\Requests\Settings;

use App\Rules\NotAnimatedImage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAvatarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The endpoint only ever acts on the authenticated user's own avatar, and
     * the route's `auth` middleware already guarantees a signed-in user.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A static JPEG/PNG/WebP up to 5 MB. `NotAnimatedImage` rejects multi-frame
     * uploads (animated GIF/APNG/WebP) that pass the format check but would
     * otherwise be flattened by the processor.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
                new NotAnimatedImage,
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
            'photo.required' => __('Choose an image to upload.'),
            'photo.image' => __('Choose a JPEG, PNG or WebP image.'),
            'photo.mimes' => __('Choose a JPEG, PNG or WebP image.'),
            'photo.max' => __('That file is over 5 MB. Try a smaller image.'),
        ];
    }
}
