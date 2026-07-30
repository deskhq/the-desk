<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class DeleteChannelRequest extends FormRequest
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

    /**
     * Get the channel being deleted.
     */
    private function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_if(! $channel instanceof Channel, 404);

        return $channel;
    }
}
