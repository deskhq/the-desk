<?php

namespace App\Models;

use Database\Factories\SsoIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's stable identity at an external directory (IdP). The `provider`
 * namespaces the identifier ('oidc', later 'ldap'/'scim') and `provider_id`
 * holds the directory's stable subject (OIDC `sub`, LDAP `objectGUID`, …), so a
 * login survives the user's email changing at the IdP.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'provider', 'provider_id'])]
class SsoIdentity extends Model
{
    /** @use HasFactory<SsoIdentityFactory> */
    use HasFactory, HasUuids;

    /**
     * The user this directory identity belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
