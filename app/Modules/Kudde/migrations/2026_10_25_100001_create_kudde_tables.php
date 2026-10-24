<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kudde's animals and their events (PLAN.md section 4.2), as the farm-PC Kudde keeps them: identity on the
 * animal, everything that happens to it as an event, and everything else (camp, last weight, withdrawal,
 * class) derived from those. Photos, herds and breeding arrive in their own migrations (phase 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kudde_animals', function (Blueprint $table) {
            $table->id();
            // The ear tag, the farm's identity key. Unique under the database's case-insensitive collation:
            // "a1" and "A1" painted on two animals would be read as one tag by anyone at the crush.
            $table->string('tag', 50)->unique();
            $table->string('name', 100)->default('');
            $table->string('breed', 100)->default('');
            $table->string('sex', 10);
            $table->date('birth_date')->nullable();
            // Parentage by tag, not a foreign key: the sire may be a neighbour's bull, the dam long sold.
            $table->string('sire_tag', 50)->nullable();
            $table->string('dam_tag', 50)->nullable();
            $table->boolean('castrated')->default(false);
            // Set by a death or sale event (or an office correction), never typed on a phone.
            $table->string('status', 10)->default('alive');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('dam_tag');
        });

        Schema::create('kudde_events', function (Blueprint $table) {
            $table->id();
            // The id the capturing phone gave this event before it reached a server. Unique, so a replay out
            // of the outbox records once; null for office entries (several nulls are allowed).
            $table->string('client_uuid', 80)->nullable()->unique();
            $table->foreignId('animal_id')->constrained('kudde_animals')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('event_date');
            $table->decimal('value', 8, 1)->nullable();
            $table->string('note', 1000)->default('');
            // The camp as typed (snapped to the office's spelling), not a foreign key: see App\Models\Camp.
            $table->string('location', 100)->default('');
            $table->string('product', 100)->default('');
            $table->string('dose', 50)->default('');
            // As days off the label, not an end date, so a corrected event date keeps it right.
            $table->unsignedSmallInteger('withdrawal_days')->nullable();
            // From weather_days for the event's date; null until that day has been filled. Never blocks a save.
            $table->decimal('weather_temp_max', 4, 1)->nullable();
            $table->decimal('weather_temp_min', 4, 1)->nullable();
            $table->decimal('weather_rain', 5, 1)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['animal_id', 'event_date']);
            $table->index(['kind', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kudde_events');
        Schema::dropIfExists('kudde_animals');
    }
};
