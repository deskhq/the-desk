<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-key existing OIDC identities under the issuer-namespaced provider.
     *
     * OIDC login originally stored identities under a bare `provider = 'oidc'`.
     * A `sub` is only unique within its issuer, so the key is now `oidc:{issuer}`
     * (see App\Http\Controllers\Auth\Sso\OidcController::providerKey()). Any rows
     * written before this change would otherwise stop matching on the next login
     * and re-provision the account, so they are migrated in place to the current
     * issuer's namespaced key. The instance-wide, single-issuer model means every
     * bare `oidc` row belongs to the one configured issuer.
     *
     * A no-op when OIDC is unconfigured (no issuer to namespace under, and no such
     * rows can exist) or when there are none to migrate — e.g. a fresh install.
     */
    public function up(): void
    {
        $namespaced = $this->namespacedProvider();

        if ($namespaced === null) {
            return;
        }

        DB::table('sso_identities')
            ->where('provider', 'oidc')
            ->update(['provider' => $namespaced]);
    }

    /**
     * Restore the bare `oidc` provider key for this issuer's identities.
     */
    public function down(): void
    {
        $namespaced = $this->namespacedProvider();

        if ($namespaced === null) {
            return;
        }

        DB::table('sso_identities')
            ->where('provider', $namespaced)
            ->update(['provider' => 'oidc']);
    }

    /**
     * The issuer-namespaced provider key for the configured OIDC issuer, or null
     * when no issuer is configured. Matches OidcController::providerKey().
     */
    private function namespacedProvider(): ?string
    {
        $issuer = rtrim((string) config('services.oidc.issuer'), '/');

        if ($issuer === '') {
            return null;
        }

        return 'oidc:'.$issuer;
    }
};
