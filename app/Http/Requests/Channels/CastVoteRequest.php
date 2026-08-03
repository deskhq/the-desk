<?php

namespace App\Http\Requests\Channels;

use App\Http\Requests\RouteBoundRequest;
use App\Models\Poll;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CastVoteRequest extends RouteBoundRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Voting requires the same channel view/post rights as sending a message. The
     * route scope-binds the poll to this channel, so a cross-channel poll already
     * 404s before reaching here.
     */
    public function authorize(): bool
    {
        return Gate::allows('postMessage', $this->channel());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The option being toggled must belong to this poll.
            'option_id' => [
                'required',
                'uuid',
                Rule::exists('poll_options', 'id')->where('poll_id', $this->poll()->id),
            ],
        ];
    }

    /**
     * Get the poll being voted on.
     */
    public function poll(): Poll
    {
        return $this->routeModel('poll', Poll::class);
    }
}
