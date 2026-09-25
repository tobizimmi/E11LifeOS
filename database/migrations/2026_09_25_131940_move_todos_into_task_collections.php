<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gives every household its built-in "tasks" collection (Status, due date,
     * assignee) and moves the existing to-dos into it; done to-dos get the
     * status "done". Query builder only, so later model changes can't break it.
     * New households get the collection on demand (Collection::tasksFor()).
     */
    public function up(): void
    {
        $now = now();

        DB::table('households')->orderBy('id')->each(function (object $household) use ($now) {
            if (DB::table('collections')->where('household_id', $household->id)->where('system_key', 'tasks')->exists()) {
                return;
            }

            $collectionId = DB::table('collections')->insertGetId([
                'household_id' => $household->id,
                'name' => 'Aufgaben',
                'icon' => '✅',
                'visibility' => 'shared',
                'system_key' => 'tasks',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $statusId = DB::table('collection_properties')->insertGetId([
                'collection_id' => $collectionId,
                'name' => 'Status',
                'type' => 'select',
                'options' => json_encode([
                    ['id' => 'todo', 'name' => 'Offen', 'color' => 'gray'],
                    ['id' => 'doing', 'name' => 'In Arbeit', 'color' => 'blue'],
                    ['id' => 'done', 'name' => 'Erledigt', 'color' => 'green'],
                ], JSON_UNESCAPED_UNICODE),
                'system_key' => 'status',
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('collection_properties')->insert([
                ['collection_id' => $collectionId, 'name' => 'Fällig', 'type' => 'date', 'options' => null, 'system_key' => 'due', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['collection_id' => $collectionId, 'name' => 'Zuständig', 'type' => 'person', 'options' => null, 'system_key' => 'assignee', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ]);

            foreach ([true => 'done', false => 'todo'] as $done => $status) {
                DB::table('items')
                    ->where('household_id', $household->id)
                    ->where('type', 'todo')
                    ->where('done', (bool) $done)
                    ->update([
                        'collection_id' => $collectionId,
                        'properties' => json_encode([(string) $statusId => $status]),
                    ]);
            }
        });
    }

    /**
     * The to-dos keep their title/done state; only the collection data goes.
     */
    public function down(): void
    {
        $taskCollectionIds = DB::table('collections')->where('system_key', 'tasks')->pluck('id');

        DB::table('items')->whereIn('collection_id', $taskCollectionIds)->update(['collection_id' => null, 'properties' => null]);
        DB::table('collections')->whereIn('id', $taskCollectionIds)->delete();
    }
};
