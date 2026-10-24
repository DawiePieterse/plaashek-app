<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The farm's camps (PLAN.md section 4.2): core master data, used by Kudde now and by Water, Bespuitings and
 * Veldnotas later. A record names its camp as text, as old Kudde did (see App\Models\Camp), so this table
 * only decides what the pickers show and which spelling a typed name snaps to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camps', function (Blueprint $table) {
            $table->id();
            // Not unique in the database: names are compared ignoring case in PHP (App\Support\CampNames),
            // because MariaDB's collations and PHP's case folding disagree on some Afrikaans diacritics.
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camps');
    }
};
