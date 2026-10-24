<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The farm's worker register, minimal (owner's call, 25 October 2026): only what the modules need to know who
 * someone is and to print the card whose QR names them, Boord's worker record less its leftovers. Identity,
 * contact, employment and pay belong to the Werkers module, which adds its own tables beside this one when it
 * is built, so a farm without Werkers still has workers for Kudde, Boord and Span to scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            // The farm's own number, typed by the office, and the only thing the card's QR holds (ADR 0011).
            // Unique ignoring capitals, as the collation compares. A lost card is reprinted with the same number.
            $table->string('number', 20)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->default('');
            // What the card prints and phones show, when it is not simply the first name.
            $table->string('preferred_name', 50)->default('');
            $table->string('cell', 30)->default('');
            // Path on the private disk; served only through the app, never from public/.
            $table->string('photo')->nullable();
            // A worker who leaves is made inactive, never deleted: records keep pointing at them, and their
            // card then scans as "no longer working here".
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
