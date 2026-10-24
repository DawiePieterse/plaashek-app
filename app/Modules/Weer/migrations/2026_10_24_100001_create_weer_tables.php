<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Weather module's proxy cache, and the core's daily weather for the farm's position (PLAN.md 4.2).
 * weather_days is a core table that this module fills: Kudde and later modules read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One Open-Meteo answer per distinct query. A finished year never changes, so it never expires and
        // is never fetched twice; the forecast and the current year expire after an hour.
        Schema::create('weer_responses', function (Blueprint $table) {
            $table->id();
            $table->char('query_hash', 64)->unique();
            $table->string('endpoint', 20);
            $table->text('query');
            $table->unsignedSmallInteger('status');
            $table->longText('body');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->nullable();
        });

        Schema::create('weather_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->decimal('temp_max', 4, 1)->nullable();
            $table->decimal('temp_min', 4, 1)->nullable();
            $table->decimal('rain', 5, 1)->nullable();
            $table->decimal('et0', 4, 1)->nullable();
            $table->decimal('wind_max', 4, 1)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_days');
        Schema::dropIfExists('weer_responses');
    }
};
