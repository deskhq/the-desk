<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Enums\AnalyticsRange;
use App\Http\Requests\RouteBoundRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ViewAnalyticsRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to view the workspace analytics.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewAnalytics', $this->team());
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
