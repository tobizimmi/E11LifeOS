<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loyalty / store cards (Stocard-style): a name, the code and its symbology, shown as a
     * scannable barcode or QR code. Shared in the household unless marked private.
     */
    public function up(): void
    {
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('name', 120);
            $table->string('code', 500);
            $table->string('code_format', 20)->default('QR_CODE');
            $table->string('color', 7)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
