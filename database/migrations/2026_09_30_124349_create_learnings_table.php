<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lessons learned from experiences, mistakes, books and people – reviewed at growing intervals.
     */
    public function up(): void
    {
        Schema::create('learnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('private');
            $table->text('title');
            $table->text('body')->nullable();
            $table->string('kind', 20)->default('experience');
            $table->string('area', 20)->default('self');
            $table->text('source')->nullable();
            $table->unsignedSmallInteger('interval_days')->default(1);
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('review_count')->default(0);
            $table->date('last_reviewed_on')->nullable();
            $table->unsignedSmallInteger('applied_count')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learnings');
    }
};
