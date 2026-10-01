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
        // Instagram's signed media links can be longer than 1000 characters.
        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->text('thumbnail_url')->nullable()->change();
            $table->text('video_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->string('thumbnail_url', 1000)->nullable()->change();
            $table->string('video_url', 1000)->nullable()->change();
        });
    }
};
