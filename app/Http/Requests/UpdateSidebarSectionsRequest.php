<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSidebarSectionsRequest extends FormRequest
{
    /**
     * The built-in sidebar sections whose collapsed state may be persisted.
     *
     * @var list<string>
     */
    public const SECTIONS = ['starred', 'channels', 'direct'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * Any authenticated user manages only their own sidebar layout, so the
     * `auth` middleware on the route is sufficient.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `collapsed` must be present (an empty array clears every collapse), and
     * each entry is constrained to a known section key so the stored payload
     * stays bounded and meaningful.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'collapsed' => ['present', 'array'],
            'collapsed.*' => ['string', Rule::in(self::SECTIONS)],
        ];
    }
}
