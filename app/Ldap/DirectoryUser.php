<?php

declare(strict_types=1);

namespace App\Ldap;

use LdapRecord\Models\Model;

/**
 * A directory-agnostic LDAP entry used to look up and bind directory users.
 *
 * LdapRecord turns `$objectClasses` into an AND query constraint, so it is
 * restricted to `person` — the single structural class shared by both Active
 * Directory user entries (top; person; organizationalPerson; user) and OpenLDAP
 * inetOrgPerson entries — rather than a provider-specific leaf class that would
 * exclude the other directory's users. It still excludes non-person entries such
 * as groups that happen to carry a mail attribute. The GUID key (the attribute
 * holding the stable identity) is configurable so an operator can point it at
 * `objectguid` (AD) or `entryuuid` (OpenLDAP).
 */
class DirectoryUser extends Model
{
    /**
     * The object classes of the LDAP model.
     *
     * @var array<int, string>
     */
    public static array $objectClasses = ['person'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->guidKey = (string) config('sso.ldap.attributes.guid', 'objectguid');

        parent::__construct($attributes);
    }
}
