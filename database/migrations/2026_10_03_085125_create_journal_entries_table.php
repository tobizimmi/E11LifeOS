<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One journal entry per member and day: mood, activities, the morning and evening questions,
     * notes and photos – only visible to the member, the texts are stored encrypted.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('mood')->nullable();
            $table->json('activities')->nullable();
            $table->text('grateful')->nullable();
            $table->text('great_day')->nullable();
            $table->text('highlights')->nullable();
            $table->text('better')->nullable();
            $table->text('notes')->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
