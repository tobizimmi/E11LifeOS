<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the built-in "repeat" property to every existing tasks collection
     * (new households get it from Collection::tasksFor()).
     */
    public function up(): void
    {
        $now = now();

        DB::table('collections')->where('system_key', 'tasks')->orderBy('id')->each(function (object $collection) use ($now) {
            if (DB::table('collection_properties')->where('collection_id', $collection->id)->where('system_key', 'repeat')->exists()) {
                return;
            }

            DB::table('collection_properties')->insert([
                'collection_id' => $collection->id,
                'name' => 'Wiederholung',
                'type' => 'select',
                'options' => json_encode([
                    ['id' => 'daily', 'name' => 'Täglich', 'color' => 'gray'],
                    ['id' => 'weekly', 'name' => 'Wöchentlich', 'color' => 'blue'],
                    ['id' => 'monthly', 'name' => 'Monatlich', 'color' => 'purple'],
                    ['id' => 'yearly', 'name' => 'Jährlich', 'color' => 'orange'],
                ], JSON_UNESCAPED_UNICODE),
                'system_key' => 'repeat',
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
        $propertyIds = DB::table('collection_properties')->where('system_key', 'repeat')->pluck('id');

        DB::table('collection_properties')->whereIn('id', $propertyIds)->delete();
    }
};
