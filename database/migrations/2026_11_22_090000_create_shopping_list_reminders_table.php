<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text reminders pinned above a shopping list (e.g. "take the deposit bottles"): always
     * visible while scrolling, tickable when done, several per list.
     */
    public function up(): void
    {
        Schema::create('shopping_list_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('shopping_list_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_reminders');
    }
};
