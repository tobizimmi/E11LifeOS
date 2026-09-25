<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves the per-item JSON tag arrays into the shared tags/item_tag tables,
     * then drops the JSON column. Uses the query builder (not models) so the
     * migration keeps working when the models change later.
     */
    public function up(): void
    {
        DB::table('items')->whereNotNull('tags')->orderBy('id')->each(function (object $item) {
            $names = collect(json_decode($item->tags, true) ?: [])
                ->map(fn (mixed $name) => mb_strtolower(trim(ltrim((string) $name, '#'))))
                ->filter()
                ->unique();

            foreach ($names as $name) {
                $tagId = DB::table('tags')
                    ->where('household_id', $item->household_id)
                    ->where('name', $name)
                    ->value('id');

                if (! $tagId) {
                    $tagId = DB::table('tags')->insertGetId([
                        'household_id' => $item->household_id,
                        'name' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('item_tag')->insertOrIgnore(['item_id' => $item->id, 'tag_id' => $tagId]);
            }
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }

    /**
     * Restores the JSON column from the pivot table.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('content');
        });

        DB::table('item_tag')
            ->join('tags', 'tags.id', '=', 'item_tag.tag_id')
            ->select('item_tag.item_id', 'tags.name')
            ->orderBy('item_tag.item_id')
            ->get()
            ->groupBy('item_id')
            ->each(function ($rows, $itemId) {
                DB::table('items')->where('id', $itemId)->update([
                    'tags' => json_encode($rows->pluck('name')->values()->all(), JSON_UNESCAPED_UNICODE),
                ]);
            });
    }
};
