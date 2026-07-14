<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Directory-provisioned (JIT) users authenticate through their IdP and never
     * set a local password, so the column must allow null.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Directory-provisioned users may have a null password by now, so give each
     * an unusable random hash before restoring the NOT NULL constraint —
     * otherwise the rollback would fail on any database with a JIT user, and
     * those accounts would keep an un-guessable password (SSO remains their only
     * way in) instead of silently gaining a usable credential.
     */
    public function down(): void
    {
        DB::table('users')->whereNull('password')->update([
            'password' => Hash::make(Str::random(64)),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
