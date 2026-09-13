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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->json('content')->nullable();
            $table->json('tags')->nullable();
            // nullOnDelete, not cascade: the item belongs to the whole household, not
            // just its creator — deleting one member's account must not wipe shared notes/todos.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
