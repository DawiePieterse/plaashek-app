<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A worker's day on a paired phone, in the core now that every module's field screens share one app (PLAN.md
 * section 5.1): the card scan opens a session on that phone until the farm's midnight. And the modules each
 * worker may use, ticked in the office: the phone shows a tile only for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The phone keeps the token, the server only its hash. The session belongs to the phone it was opened
        // on: a token copied to another phone is refused there.
        Schema::create('field_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('expires_at');
        });

        // Null: every module, as for the workers registered before modules were chosen per worker.
        Schema::table('workers', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
        Schema::dropIfExists('field_sessions');
    }
};
