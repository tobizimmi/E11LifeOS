<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per topic: how the member wants to be notified (all = push + bell, bell, off).
            $table->json('notification_prefs')->nullable();
        });

        // Native app installations (iOS) that receive pushes through Apple's push service.
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->default('ios');
            $table->string('token', 255)->unique();
            $table->string('environment', 12)->default('production');
            $table->string('name', 100)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_prefs');
        });
    }
};
