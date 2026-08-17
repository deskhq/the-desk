<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Http\Requests\RouteBoundRequest;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class DeleteTeamRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->team());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('name') !== $this->team()->name) {
                    $validator->errors()->add('name', __('The team name does not match.'));
                }
            },
        ];
    }
}
