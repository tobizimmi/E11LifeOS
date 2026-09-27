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
        // Links for people outside the household: shopping list (tick off and add) and collections (read-only).
        Schema::table('households', function (Blueprint $table) {
            $table->string('shopping_share_token', 64)->nullable()->unique();
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropUnique(['shopping_share_token']);
            $table->dropColumn('shopping_share_token');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
