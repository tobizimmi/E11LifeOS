<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('smart_home_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // homeassistant | evcc
            $table->string('name', 100);
            $table->string('url', 500)->nullable(); // in the home network, through the VPN
            $table->string('external_url', 500)->nullable(); // e.g. Nabu Casa, used when the VPN is down
            $table->text('token')->nullable();
            $table->string('webhook_secret', 64)->unique();
            $table->timestamp('last_contact_at')->nullable();
            $table->string('last_route', 20)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_home_connections');
    }
};
