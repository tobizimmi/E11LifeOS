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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('shared');
            $table->string('name');
            $table->string('make_model')->nullable();
            $table->string('plate', 20)->nullable();
            $table->string('fuel', 16)->default('petrol');
            $table->date('first_registration')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('current_value', 12, 2)->nullable();
            $table->unsignedInteger('odometer_start')->nullable();
            $table->date('inspection_due')->nullable();
            $table->date('service_due')->nullable();
            $table->unsignedInteger('service_due_km')->nullable();
            $table->boolean('tyre_reminders')->default(true);
            $table->decimal('tax_yearly', 10, 2)->nullable();
            $table->decimal('insurance_yearly', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->date('date');
            $table->unsignedInteger('odometer')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->unsignedInteger('distance_km')->nullable();
            $table->string('purpose', 16)->nullable();
            $table->string('destination')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_logs');
        Schema::dropIfExists('vehicles');
    }
};
