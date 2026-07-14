<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Sso\ProvisionSsoUser;
use App\Actions\Sso\SetSsoUserActivation;
use App\Models\User;
use App\Support\SessionRegistry;
use ArieTimmerman\Laravel\SCIMServer\Events\Create;
use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;
use ArieTimmerman\Laravel\SCIMServer\Http\Controllers\ResourceController;
use ArieTimmerman\Laravel\SCIMServer\PolicyDecisionPoint;
use ArieTimmerman\Laravel\SCIMServer\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SCIM `/Users` endpoint, wired to this app's identity model.
 *
 * Read, list-with-filter, and the SCIM attribute mapping are inherited from the
 * package's ResourceController; the write paths are overridden so every mutation
 * flows through the shared directory layer:
 *
 * - Create matches or just-in-time provisions through App\Actions\Sso\
 *   ProvisionSsoUser (email match or create, default team as Member, stable
 *   provider id), exactly like OIDC and LDAP.
 * - Deactivate (DELETE, or `active: false` via PUT/PATCH) tombstones the account
 *   and revokes its sessions rather than hard-deleting, so history is retained.
 * - Reactivate (`active: true`) reverses it.
 *
 * The controller is bound over the package's ResourceController in
 * App\Providers\SsoServiceProvider so the package routes resolve to it.
 */
class ScimUserController extends ResourceController
{
    public function __construct(
        private readonly ProvisionSsoUser $provisionSsoUser,
        private readonly SetSsoUserActivation $activation,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * Provision (or link) the app user for a SCIM create.
     *
     * The IdP's `userName` is treated as the user's email — the identity the
     * shared layer matches on — and `externalId` (falling back to the email) as
     * the stable provider id stored on the SCIM identity. The `active` flag is
     * applied after provisioning so a create can immediately (de)activate.
     *
     * @param  bool  $isMe
     */
    #[\Override]
    public function createObject(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, $isMe = false): Model
    {
        $email = $this->email($request);

        if ($email === '') {
            throw (new SCIMException('The userName attribute is required.'))->setCode(400)->setScimType('invalidValue');
        }

        $externalId = trim((string) $request->input('externalId'));
        $name = $request->input('name.formatted');

        $user = $this->provisionSsoUser->handle(
            'scim',
            $externalId !== '' ? $externalId : $email,
            $email,
            is_string($name) ? $name : null,
            syncName: true,
        );

        $this->applyActiveFlag($user, $request->input('active'));

        event(new Create($user, $resourceType, $isMe, $request->input()));

        return $user;
    }

    /**
     * Replace a user (PUT), revoking sessions if the write deactivated them.
     *
     * @param  bool  $isMe
     */
    #[\Override]
    public function replace(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject, $isMe = false): Response
    {
        $wasDeactivated = $this->isDeactivated($resourceObject);

        $response = parent::replace($request, $pdp, $resourceType, $resourceObject, $isMe);

        $this->revokeSessionsIfNewlyDeactivated($resourceObject, $wasDeactivated);

        return $response;
    }

    /**
     * Patch a user, revoking sessions if the write deactivated them.
     *
     * @param  bool  $isMe
     */
    #[\Override]
    public function update(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject, $isMe = false): Response
    {
        $wasDeactivated = $this->isDeactivated($resourceObject);

        $response = parent::update($request, $pdp, $resourceType, $resourceObject, $isMe);

        $this->revokeSessionsIfNewlyDeactivated($resourceObject, $wasDeactivated);

        return $response;
    }

    /**
     * Deactivate on DELETE — tombstone and revoke access instead of removing.
     */
    #[\Override]
    public function delete(Request $request, PolicyDecisionPoint $pdp, ResourceType $resourceType, Model $resourceObject): Response
    {
        $this->deactivate($resourceObject);

        return response(null, 204);
    }

    /**
     * Resolve the email the SCIM create keys on: `userName`, else the first email.
     */
    private function email(Request $request): string
    {
        $userName = trim((string) $request->input('userName'));

        if ($userName !== '') {
            return $userName;
        }

        return trim((string) $request->input('emails.0.value'));
    }

    /**
     * Apply an incoming `active` flag (default active) to a provisioned user.
     */
    private function applyActiveFlag(User $user, mixed $active): void
    {
        if ($active === false) {
            $this->deactivate($user);

            return;
        }

        $this->activation->reactivate($user);
    }

    /**
     * Deactivate a user through the shared action (tombstone + session flush).
     */
    private function deactivate(Model $user): void
    {
        if ($user instanceof User) {
            $this->activation->deactivate($user);
        }
    }

    /**
     * Whether the resolved resource is a deactivated app user.
     */
    private function isDeactivated(Model $user): bool
    {
        return $user instanceof User && $user->isDeactivated();
    }

    /**
     * Revoke sessions when a PUT/PATCH flipped an active account to deactivated.
     *
     * The `active` mapping has already stamped `deactivated_at` and saved by the
     * time this runs, so only the session revocation remains — and only on the
     * active-to-deactivated edge, never on a reactivation or a no-op write.
     */
    private function revokeSessionsIfNewlyDeactivated(Model $user, bool $wasDeactivated): void
    {
        if (! $wasDeactivated && $this->isDeactivated($user)) {
            $this->sessions->flush((string) $user->getKey());
        }
    }
}
