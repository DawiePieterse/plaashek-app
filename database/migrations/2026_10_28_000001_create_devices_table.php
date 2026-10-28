<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The farm's phones (PLAN.md section 5.1, owner's call 25 October 2026): a phone is paired by the office before a
 * card scan works on it, so a printed card alone is not enough to capture. The phone keeps a long-lived token;
 * the server keeps only hashes, of the token and of the one-use pairing code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('pairing_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // The last successful sync: the 21-day window counts from here.
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignId('last_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->string('user_agent', 200)->default('');
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
