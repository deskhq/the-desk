<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class DeleteChannelRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->channel());
    }

    /**
     * Normalize the typed confirmation the way the create form normalizes a new
     * channel's name, so an admin who types the name with its leading `#` — the
     * form it is displayed in — is not told it does not match.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ltrim(trim((string) $this->input('name')), '#'),
        ]);
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
     * The typed name is the deliberate friction on a destructive action, so it is
     * re-checked server side: the dialog's disabled button is a convenience, not
     * the guard.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('name') !== $this->channel()->name) {
                    $validator->errors()->add('name', __('The channel name does not match.'));
                }
            },
        ];
    }
}
