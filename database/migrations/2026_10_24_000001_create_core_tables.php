<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core every module stands on (PLAN.md section 4.2). One farm per database, so no table carries a
 * farm_id: the database is the farm.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Office logins. Field users (username or cell + PIN) arrive with Kudde and get their own columns then.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Farm settings as key/value, read through App\Support\Settings, so a module can add a setting
        // without a migration.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->timestamps();
        });

        // One row per module the farm has ever had. Licence status and grace arrive with the first paid module.
        Schema::create('modules', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // Who changed what: settings now, office corrections and staff access as the modules arrive. Visible
        // to the farm, so it can see what Plaashek's support account did.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->string('subject', 100)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
