<?php

use App\Enums\ChimeSound;
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
            // The account-wide notification chime the client plays for a qualifying
            // incoming message. `off` disables chimes entirely (see App\Enums\ChimeSound).
            $table->string('chime_sound')->default(ChimeSound::Ping->value)->after('current_team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('chime_sound');
        });
    }
};
