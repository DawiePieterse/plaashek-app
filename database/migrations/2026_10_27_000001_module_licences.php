<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid module's licence (PLAN.md section 6, ADR 0004: flipped by hand after an EFT): its status, since when,
 * and the last day of a grace period. Free modules keep null: they have no licence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('licence', 20)->nullable()->after('enabled');
            $table->date('licence_since')->nullable()->after('licence');
            $table->date('grace_until')->nullable()->after('licence_since');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['licence', 'licence_since', 'grace_until']);
        });
    }
};
