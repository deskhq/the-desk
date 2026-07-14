<?php

namespace App\Services\Sso;

use App\Actions\Sso\ProvisionSsoUser;
use App\Ldap\DirectoryUser;
use App\Models\User;
use LdapRecord\Container;
use LdapRecord\LdapRecordException;

/**
 * Verifies credentials against the configured LDAP/AD directory and resolves
 * them to an app user. It locates the directory entry by the mapped username
 * attribute, binds with the submitted password to prove the credentials, then
 * hands the verified identity (stable GUID, mail, name) to the shared
 * provisioning layer so directory logins match/JIT exactly like OIDC.
 */
class LdapAuthenticator
{
    public function __construct(private readonly ProvisionSsoUser $provisionSsoUser) {}

    /**
     * Bind the given credentials against the directory and resolve the app user.
     *
     * Returns null on any clear failure — unknown user, rejected bind,
     * unreachable directory, or an entry with no mail attribute to match on — so
     * the caller can fail the login (or fall back to a local password) without
     * an exception leaking out.
     */
    public function attempt(string $identifier, string $password): ?User
    {
        if (blank($identifier) || blank($password)) {
            return null;
        }

        $connection = config('sso.ldap.connection');
        $attributes = config('sso.ldap.attributes');

        try {
            $directoryUser = DirectoryUser::on($connection)
                ->where($attributes['username'], '=', $identifier)
                ->first();

            if (! $directoryUser instanceof DirectoryUser) {
                return null;
            }

            $bound = Container::getConnection($connection)
                ->auth()
                ->attempt((string) $directoryUser->getDn(), $password);
        } catch (LdapRecordException) {
            return null;
        }

        if (! $bound) {
            return null;
        }

        $guid = $directoryUser->getConvertedGuid();
        $mail = $directoryUser->getFirstAttribute($attributes['mail']);
        $name = $directoryUser->getFirstAttribute($attributes['name']);

        if (blank($guid) || blank($mail)) {
            return null;
        }

        return $this->provisionSsoUser->handle('ldap', $guid, $mail, $name, syncName: true);
    }
}
