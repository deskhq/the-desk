<?php

use App\Enums\SidebarPosition;
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
            // Which edge of the workspace the navigation sidebar sits on. A purely
            // ergonomic per-user preference; `left` preserves today's behaviour
            // (see App\Enums\SidebarPosition).
            $table->string('sidebar_position')->default(SidebarPosition::Left->value)->after('share_read_receipts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sidebar_position');
        });
    }
};
