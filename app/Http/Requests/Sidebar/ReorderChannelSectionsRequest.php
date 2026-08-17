<?php

declare(strict_types=1);

namespace App\Http\Requests\Sidebar;

use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class ReorderChannelSectionsRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->belongsToTeam($this->team()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every id must be one of the user's own sections in the team, so the payload
     * can only reorder sections they actually own.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sections' => ['present', 'array'],
            'sections.*' => [
                'uuid',
                Rule::exists('channel_sections', 'id')
                    ->where('user_id', $this->user()?->id)
                    ->where('team_id', $this->team()->id),
            ],
        ];
    }
}
