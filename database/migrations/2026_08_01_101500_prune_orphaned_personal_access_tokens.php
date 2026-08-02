<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Delete the API tokens left behind by accounts deleted before the sweep in
     * App\Observers\UserObserver existed.
     *
     * `personal_access_tokens` addresses its owner through a polymorphic pair
     * with no foreign key, so every bot and human deleted before that observer
     * left one row per token it had been minted, pointing at a `tokenable_id`
     * that no longer resolves. Nothing removes them and the table is scanned on
     * every API request's token lookup.
     *
     * Deleting them is safe rather than merely tidy: Sanctum resolves a token's
     * `tokenable` on each request and refuses when it is gone, so these rows are
     * already dead credentials — no live access is withdrawn here.
     *
     * Scoped to the User morph, the only tokenable this application mints for,
     * so a token type added later is judged against its own table and not
     * silently pruned by this one.
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('users')
                ->whereColumn('users.id', 'personal_access_tokens.tokenable_id'))
            ->delete();
    }

    /**
     * Irreversible: the deleted rows named owners that no longer exist, so there
     * is nothing to restore them to.
     */
    public function down(): void
    {
        //
    }
};
