<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Career of a member: jobs, school and studies, trainings with certificates, milestones, work
     * contracts and applications. Personal – only the member sees them.
     */
    public function up(): void
    {
        Schema::create('career_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title');
            $table->string('organization')->nullable();
            $table->string('location')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_entries');
    }
};
