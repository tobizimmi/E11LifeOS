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
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // login, login_new_device, login_failed, two_factor_failed, lockout, logout_other_devices, session_revoked,
            // password_changed, two_factor_enabled, two_factor_disabled, export, sensitive_area, trusted_access, api_login
            $table->string('event', 32);
            $table->string('ip_address', 45)->nullable();
            $table->string('device', 120)->nullable();
            // Hash of the browser signature, to recognise new devices.
            $table->string('device_hash', 64)->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
