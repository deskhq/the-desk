<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Rules\MatchesTeamInvitation;
use App\Support\LegalConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitationCode = $input['invitation'] ?? null;

        Validator::make($input, [
            ...$this->profileRules(),
            'email' => [
                ...$this->emailRules(),
                new MatchesTeamInvitation(is_string($invitationCode) ? $invitationCode : null),
            ],
            'password' => $this->passwordRules(),
            // Only an instance whose operator published both documents asks for
            // agreement; elsewhere the field does not exist on the form.
            'terms' => LegalConsent::isRequired() ? ['accepted'] : [],
        ], [
            'terms.accepted' => __('Please accept the terms and the privacy notice to continue.'),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
    }
}
