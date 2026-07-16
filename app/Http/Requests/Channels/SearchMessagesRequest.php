<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Enums\SearchScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchMessagesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Team membership is already enforced by the EnsureTeamMembership middleware
     * on the route, so any request reaching here is from a team member.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            // The author and channel facets arrive as ids already resolved on the
            // client; the channel ACL is re-asserted authoritatively, so an
            // unknown id simply matches nothing rather than needing an exists rule.
            'from' => ['nullable', 'string', 'max:255'],
            'in' => ['nullable', 'string', 'max:255'],
            'after' => ['nullable', 'date'],
            'before' => ['nullable', 'date'],
            'scope' => ['nullable', Rule::enum(SearchScope::class)],
        ];
    }
}
