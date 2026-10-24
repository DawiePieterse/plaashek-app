<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workers sign the Kudde field app in by scanning their card, once a day (owner's call, 25 October 2026), and
 * every animal and event records which worker captured it, beside the office user who did, if any.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per scan: the phone keeps the token, the server only its hash, so a database copy cannot be
        // used to sign in. Valid until the farm's midnight, so a busy day needs one scan.
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

        foreach (['kudde_animals', 'kudde_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('worker_id')->nullable()->after('user_id')->constrained('workers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['kudde_events', 'kudde_animals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('worker_id');
            });
        }
        Schema::dropIfExists('kudde_field_sessions');
    }
};
