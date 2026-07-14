<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user's stable directory identities live in a dedicated table rather than
     * columns on `users`: one user can carry several (an OIDC `sub`, an LDAP
     * `objectGUID`, a SCIM `externalId`) as the SSO epic's sub-issues land, and
     * the already-wide users table stays clean. Each (provider, provider_id) pair
     * is globally unique so a directory subject maps to exactly one account.
     */
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
