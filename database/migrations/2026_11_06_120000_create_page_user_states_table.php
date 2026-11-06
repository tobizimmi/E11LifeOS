<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user, per-page state: favourites (pinned in the sidebar) and the last visit
        // (for the "recently visited" list). One row per user and page.
        Schema::create('page_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->boolean('favorite')->default(false);
            $table->timestamp('visited_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'item_id']);
            $table->index(['user_id', 'favorite']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_user_states');
    }
};
