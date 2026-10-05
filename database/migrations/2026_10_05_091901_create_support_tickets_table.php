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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('type', 15)->default('question');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 10)->default('new');
            $table->string('source', 10)->default('user');
            $table->string('area', 40)->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('roadmap_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tags')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('requester_unread')->default(false);
            $table->boolean('agent_unread')->default(true);
            $table->string('satisfaction', 5)->nullable();
            $table->string('satisfaction_comment', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
            $table->index(['assignee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
