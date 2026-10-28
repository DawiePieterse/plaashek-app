<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card scans moved into the core (field_sessions, on a paired phone: PLAN.md section 5.1), so Kudde's own session
 * table goes; a worker scans once more the morning this lands. And every record names the phone it was captured on,
 * beside the worker, so the office can see what a lost phone captured.
 */
return new class extends Migration
{
    private const TABLES = ['kudde_animals', 'kudde_events', 'kudde_photos'];

    public function up(): void
    {
        Schema::dropIfExists('kudde_field_sessions');

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('device_id')->nullable()->after('worker_id')->constrained('devices')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('device_id');
            });
        }

        Schema::create('kudde_field_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('user_agent', 200)->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->index('expires_at');
        });
    }
};
