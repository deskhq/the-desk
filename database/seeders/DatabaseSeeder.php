<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately does NOT use `WithoutModelEvents`: the workspace dataset relies
     * on `MembershipObserver` firing to create each team's #general channel and
     * join members to it.
     */
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            $this->command->warn('Skipping workspace seeding: only intended for local and testing environments.');

            return;
        }

        $this->call(WorkspaceSeeder::class);
    }
}
