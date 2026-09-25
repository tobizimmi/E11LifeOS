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
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->nullable();
            // "shared" = whole household, "private" = only created_by (same rule as items).
            $table->string('visibility')->default('shared');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Built-in collections (e.g. "tasks") are created by the app and cannot be deleted.
            $table->string('system_key')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'system_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
