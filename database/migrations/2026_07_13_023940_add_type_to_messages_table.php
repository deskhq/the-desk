<?php

use App\Enums\MessageType;
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
        Schema::table('messages', function (Blueprint $table): void {
            // Distinguishes an ordinary user message from a system notice
            // (member joined / left). Defaults to standard so every existing row
            // and every normal send stays a user-authored message.
            $table->string('type')->default(MessageType::Standard->value)->after('body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
