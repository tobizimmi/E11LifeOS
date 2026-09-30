<?php

use App\Models\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spaces (workspaces) group pages and tasks, e.g. "Work", "Home", "Club". Tasks also get a
     * "Category" field.
     */
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('name', 60);
            $table->string('icon', 16)->default('🗂️');
            $table->string('color', 16)->default('indigo');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable()->after('collection_id')->constrained()->nullOnDelete();
        });

        // Existing task collections get the new "Category" field.
        foreach (DB::table('collections')->where('system_key', Collection::SYSTEM_TASKS)->pluck('id') as $collectionId) {
            if (DB::table('collection_properties')->where('collection_id', $collectionId)->where('system_key', 'category')->exists()) {
                continue;
            }

            DB::table('collection_properties')->insert([
                'collection_id' => $collectionId,
                'name' => __('Category'),
                'type' => 'select',
                'options' => json_encode(Collection::defaultTaskCategories()),
                'system_key' => 'category',
                'position' => (int) DB::table('collection_properties')->where('collection_id', $collectionId)->max('position') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('collection_properties')->where('system_key', 'category')->delete();

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
        });

        Schema::dropIfExists('spaces');
    }
};
