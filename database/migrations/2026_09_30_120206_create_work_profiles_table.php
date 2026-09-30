<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal working time and holiday planning: contract hours, days worked and leave.
     */
    public function up(): void
    {
        Schema::create('work_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('weekly_hours', 4, 2)->default(40);
            $table->json('work_days');
            $table->decimal('vacation_days', 4, 1)->default(30);
            $table->decimal('carry_over_days', 4, 1)->default(0);
            $table->unsignedSmallInteger('carry_over_year')->nullable();
            $table->string('state', 2)->default('BY');
            $table->integer('overtime_start_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('work_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 12)->default('work');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_days');
        Schema::dropIfExists('work_profiles');
    }
};
