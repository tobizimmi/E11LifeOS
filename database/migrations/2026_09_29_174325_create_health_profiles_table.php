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
        Schema::create('health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->string('sex', 8)->nullable();
            $table->string('blood_type', 8)->nullable();
            $table->text('allergies')->nullable();
            $table->text('conditions')->nullable();
            $table->text('insurance')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->text('doctors')->nullable();
            $table->timestamps();
        });

        Schema::create('health_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('key', 48)->default('custom');
            $table->string('title');
            $table->date('done_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->string('time_of_day', 5)->nullable();
            $table->json('times')->nullable();
            $table->string('dosage')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['health_profile_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_entries');
        Schema::dropIfExists('health_profiles');
    }
};
