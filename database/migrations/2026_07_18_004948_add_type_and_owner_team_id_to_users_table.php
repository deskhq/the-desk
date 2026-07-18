<?php

use App\Enums\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Discriminates humans from non-human integration identities (bots).
            // Defaults to human so every existing account and every ordinary
            // registration stays a person with no backfill.
            $table->string('type')->default(UserType::Human->value)->after('email');

            // The single team a bot belongs to. Null for humans (who belong to
            // teams through the team_members pivot); set for a bot, which has no
            // pivot row and so is absent from every team-member surface by
            // construction. Nulled if the team is deleted, leaving the bot inert.
            $table->foreignUuid('owner_team_id')->nullable()->after('current_team_id')
                ->constrained('teams')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_team_id');
            $table->dropColumn('type');
        });
    }
};
