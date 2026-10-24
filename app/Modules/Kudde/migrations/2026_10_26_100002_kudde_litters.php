<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Litters (PLAN.md section 2.2, phase 4b, owner's call): a sow farrows ten piglets nobody tags in the pen. The
 * birth goes on the sow with a count (born alive, stillborn); the piglets become animals with tags and records
 * only at weaning, with her as dam. Until then the litter is the record: how many lived, how many were fostered
 * on or off, how many reached weaning, which is the sow's number that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kudde_litters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_id')->constrained('kudde_animals');
            // The dam's birth event: the litter is that birth, counted.
            $table->foreignId('birth_event_id')->unique()->constrained('kudde_events');
            $table->date('born_on');
            $table->unsignedSmallInteger('born_alive');
            $table->unsignedSmallInteger('stillborn')->default(0);
            $table->string('sire_tag', 50)->nullable();
            // Fostered on minus fostered off, so it may be negative.
            $table->smallInteger('fostered')->default(0);
            $table->date('weaned_on')->nullable();
            $table->unsignedSmallInteger('weaned')->nullable();
            // The weaning's capture id: a replayed weaning tags its young once.
            $table->string('wean_uuid', 80)->nullable()->unique();
            $table->timestamps();
            $table->index(['weaned_on', 'born_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kudde_litters');
    }
};
