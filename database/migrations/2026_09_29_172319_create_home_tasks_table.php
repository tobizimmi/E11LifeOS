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
        Schema::create('home_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key', 48)->default('custom');
            $table->string('title');
            $table->string('icon', 16)->nullable();
            $table->unsignedSmallInteger('interval_months')->default(12);
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on');
            $table->unsignedSmallInteger('remind_days')->default(7);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'next_due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_tasks');
    }
};
