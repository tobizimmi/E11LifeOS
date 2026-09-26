<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the built-in "Priorität" property to existing task collections.
     */
    public function up(): void
    {
        $now = now();

        DB::table('collections')->where('system_key', 'tasks')->orderBy('id')->each(function (object $collection) use ($now) {
            if (DB::table('collection_properties')->where('collection_id', $collection->id)->where('system_key', 'priority')->exists()) {
                return;
            }

            DB::table('collection_properties')->insert([
                'collection_id' => $collection->id,
                'name' => 'Priorität',
                'type' => 'select',
                'options' => json_encode([
                    ['id' => 'high', 'name' => 'Hoch', 'color' => 'red'],
                    ['id' => 'medium', 'name' => 'Mittel', 'color' => 'orange'],
                    ['id' => 'low', 'name' => 'Niedrig', 'color' => 'blue'],
                ], JSON_UNESCAPED_UNICODE),
                'system_key' => 'priority',
                'position' => ((int) DB::table('collection_properties')->where('collection_id', $collection->id)->max('position')) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('collection_properties')->where('system_key', 'priority')->delete();
    }
};
