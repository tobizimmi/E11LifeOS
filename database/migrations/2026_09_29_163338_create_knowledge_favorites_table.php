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
        Schema::create('knowledge_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('key', 64);
            $table->string('title');
            $table->string('url', 2000)->nullable();
            $table->text('summary')->nullable();
            $table->string('source')->nullable();
            $table->string('category', 32)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_favorites');
    }
};
