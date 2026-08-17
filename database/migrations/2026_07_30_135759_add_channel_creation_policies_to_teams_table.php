<?php

declare(strict_types=1);

use App\Enums\ChannelCreationPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Who may open a channel, held once per visibility so a workspace can curate
     * its public directory without also locking down private channels (or the
     * reverse). Typed string columns rather than a settings blob: they are read
     * on the authorization path, so they stay queryable and each carries its own
     * default. Both default to `members`, which is exactly how every existing
     * workspace already behaves.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('public_channel_creation_policy')
                ->default(ChannelCreationPolicy::Members->value)
                ->after('is_personal');
            $table->string('private_channel_creation_policy')
                ->default(ChannelCreationPolicy::Members->value)
                ->after('public_channel_creation_policy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn(['public_channel_creation_policy', 'private_channel_creation_policy']);
        });
    }
};
