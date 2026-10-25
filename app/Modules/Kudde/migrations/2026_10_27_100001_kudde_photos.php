<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos of an animal (PLAN.md section 2.2): the file on the private disk, this row saying whose it is. The
 * capture id makes a phone's retried upload land once, as every other capture does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kudde_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('kudde_animals');
            $table->string('path');
            $table->string('client_uuid', 80)->nullable()->unique();
            $table->date('taken_on');
            $table->string('note', 200)->default('');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['animal_id', 'taken_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kudde_photos');
    }
};
