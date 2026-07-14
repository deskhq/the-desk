<?php

namespace App\Actions\Sso;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The shared identity layer for every directory login (OIDC now; LDAP/SCIM as
 * the SSO epic's sub-issues land). Given a provider-verified identity it links
 * to the existing account or just-in-time provisions a new one, following one
 * set of matching rules so every directory behaves identically.
 */
class ProvisionSsoUser
{
    public function __construct(private readonly CreateTeam $createTeam) {}

    /**
     * Resolve the app user for a directory-verified identity.
     *
     * Matching order: (1) an existing identity for this exact directory subject
     * wins, so a login survives the user's email changing at the IdP; (2) else a
     * user with the same email is linked (the directory has verified it), storing
     * the subject for next time; (3) else a new account is JIT-provisioned. New
     * accounts have no local password, are treated as email-verified (the IdP
     * verified them), and land in the default team as a Member.
     *
     * When $syncName is set, the mapped display name is refreshed from the
     * directory on every login for an already-existing account (a blank name is
     * ignored rather than wiping the current one). Directories that push
     * attributes on each bind — LDAP/AD — opt in; a JIT-created account already
     * carries the supplied name, so only the returning-user paths need it.
     */
    public function handle(string $provider, string $providerId, string $email, ?string $name, bool $syncName = false): User
    {
        return DB::transaction(function () use ($provider, $providerId, $email, $name, $syncName): User {
            $email = strtolower($email);

            $identity = SsoIdentity::query()
                ->where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if ($identity) {
                $user = $identity->user;
                $this->syncName($user, $name, $syncName);

                return $user;
            }

            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user) {
                $this->syncName($user, $name, $syncName);
            } else {
                $user = $this->provisionUser($email, $name);
            }

            $user->ssoIdentities()->create([
                'provider' => $provider,
                'provider_id' => $providerId,
            ]);

            return $user;
        });
    }

    /**
     * Refresh an existing user's display name from the directory when opted in.
     *
     * A blank directory name is ignored so a sparsely populated entry never wipes
     * a name the user already has.
     */
    private function syncName(User $user, ?string $name, bool $syncName): void
    {
        if (! $syncName || $name === null || $name === '') {
            return;
        }

        $user->forceFill(['name' => $name])->save();
    }

    /**
     * JIT-create a directory user and place them in the default team.
     */
    private function provisionUser(string $email, ?string $name): User
    {
        $user = User::create([
            'name' => $name !== null && $name !== '' ? $name : $email,
            'email' => $email,
            'password' => null,
        ]);

        // The IdP has already verified the address, so the account is verified
        // regardless of the email-verification flag. `email_verified_at` is not
        // mass-assignable, so stamp it through the MustVerifyEmail contract.
        $user->markEmailAsVerified();

        $this->assignToDefaultTeam($user);

        return $user;
    }

    /**
     * Place a freshly provisioned user in the instance's default team as a Member.
     *
     * The default team is the one named by `SSO_DEFAULT_TEAM_ID`, or the sole
     * team when the instance has exactly one. When neither resolves (no config
     * and zero or several teams) the account still needs a workspace to be
     * usable, so it falls back to its own personal team, mirroring self-service
     * registration.
     */
    private function assignToDefaultTeam(User $user): void
    {
        $team = $this->defaultTeam();

        if (! $team instanceof Team) {
            $this->createTeam->handle($user, __(":name's Team", ['name' => $user->name]), isPersonal: true);

            return;
        }

        // Create the pivot through the Membership model (not members()->attach)
        // so the MembershipObserver fires and the user is joined to #general —
        // the same path CreateTeam uses. attach() writes the row directly and
        // would skip that observer, leaving the provisioned user channel-less.
        $team->memberships()->create([
            'user_id' => $user->id,
            'role' => TeamRole::Member,
        ]);
        $user->switchTeam($team);
    }

    /**
     * Resolve the instance-wide default team, or null when none applies.
     */
    private function defaultTeam(): ?Team
    {
        $configuredId = config('sso.default_team_id');

        if (filled($configuredId)) {
            return Team::query()->whereKey((string) $configuredId)->first();
        }

        if (Team::query()->count() === 1) {
            return Team::query()->first();
        }

        return null;
    }
}
