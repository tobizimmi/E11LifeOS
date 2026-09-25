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
        Schema::table('items', function (Blueprint $table) {
            // Collection entries (and tasks, which live in the built-in "tasks" collection).
            $table->foreignId('collection_id')->nullable()->after('parent_id')->constrained()->cascadeOnDelete();
            // Property values keyed by collection_properties.id.
            $table->json('properties')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_id');
            $table->dropColumn('properties');
        });
    }
};
