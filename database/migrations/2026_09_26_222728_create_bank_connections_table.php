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
        // Online banking access via FinTS/HBCI (securities account holdings and account bookings).
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            // Key of a preset (ing, dkb, comdirect …) or "custom".
            $table->string('bank', 32);
            $table->string('bank_name', 100);
            $table->string('fints_url');
            $table->string('blz', 12);
            $table->string('login_name', 150);
            // Encrypted; only stored when the user allows it (needed for automatic sync).
            $table->text('pin')->nullable();
            $table->string('tan_mode', 16)->nullable();
            // Holdings are compared with this portfolio.
            $table->foreignId('portfolio_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('auto_sync')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            // success, error, tan_required
            $table->string('sync_status', 16)->nullable();
            $table->text('sync_message')->nullable();
            // Encrypted state of a dialog waiting for a TAN.
            $table->longText('pending_state')->nullable();
            $table->timestamp('pending_until')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'blz', 'login_name']);
        });

        Schema::create('bank_depot_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 10)->nullable();
            $table->string('name')->default('');
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('acquisition_price', 15, 4)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('price_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_depot_positions');
        Schema::dropIfExists('bank_connections');
    }
};
