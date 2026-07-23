<?php

use App\Enums\TimeFormat;
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
            // Whether times of day render on a 12- or 24-hour clock. `auto` keeps
            // today's behaviour — the clock style follows the display language —
            // so the column is a no-op for every existing account until they
            // choose otherwise (see App\Enums\TimeFormat).
            $table->string('time_format')->default(TimeFormat::Auto->value)->after('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('time_format');
        });
    }
};
