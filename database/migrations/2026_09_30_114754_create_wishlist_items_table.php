<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wishes with reason, priority and price; checked against the cashflow before buying.
     */
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('title', 150);
            $table->decimal('price', 12, 2)->nullable();
            $table->string('priority', 10)->default('nice');
            $table->string('category', 30)->default('other');
            $table->text('reason')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('status', 10)->default('open');
            $table->date('wanted_by')->nullable();
            $table->foreignId('savings_goal_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
