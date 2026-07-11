<?php

namespace App\Http\Requests\Teams;

use App\Enums\AnalyticsRange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ViewAnalyticsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to view the workspace analytics.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewAnalytics', $this->route('team'));
    }

    /**
     * Get the validation rules that apply to the analytics range filter.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::enum(AnalyticsRange::class)],
        ];
    }

    /**
     * Resolve the requested window, falling back to the default range.
     */
    public function range(): AnalyticsRange
    {
        $range = $this->validated('range');

        return $range !== null
            ? AnalyticsRange::from($range)
            : AnalyticsRange::default();
    }
}
