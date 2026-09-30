<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared expenses (like Splid/Tricount): groups, their participants (members or external
     * people), expenses and how each expense is split.
     */
    public function up(): void
    {
        Schema::create('expense_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('title', 150);
            $table->string('icon', 16)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('share_token', 64)->nullable()->unique();
            $table->text('share_token_encrypted')->nullable();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->decimal('weight', 6, 2)->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paid_by')->constrained('expense_members')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->decimal('amount', 12, 2);
            $table->date('spent_on');
            $table->string('category', 30)->default('other');
            $table->string('split_mode', 10)->default('equal');
            $table->boolean('is_transfer')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['expense_group_id', 'spent_on']);
        });

        Schema::create('expense_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_member_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 12, 2)->default(1);
            $table->decimal('amount', 12, 2);

            $table->unique(['expense_id', 'expense_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_shares');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_members');
        Schema::dropIfExists('expense_groups');
    }
};
