<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Bookmarks": saved links/articles/magazines for later, grouped into the member's own areas
     * (categories), with a comment and web-page preview (title, description, image).
     */
    public function up(): void
    {
        Schema::create('bookmark_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('icon', 16)->nullable();
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'position']);
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('bookmark_categories')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('url', 2048);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('site_name', 120)->nullable();
            $table->string('favicon_url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable(); // remote fallback image
            $table->string('image_path')->nullable();       // downloaded preview image
            $table->text('comment')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('bookmark_categories');
    }
};
