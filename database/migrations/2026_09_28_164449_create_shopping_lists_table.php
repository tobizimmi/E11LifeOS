<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Stores of the household with their own order of the sections (walking route).
        Schema::create('shopping_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Known chain (rewe, edeka, aldi_sued …) for offers and weekly flyers.
            $table->string('chain', 30)->nullable();
            $table->json('aisle_order')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Several lists per household (weekly shop, drugstore, party …), each can be shared by link.
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon', 16)->default('🛒');
            $table->foreignId('store_id')->nullable()->constrained('shopping_stores')->nullOnDelete();
            $table->string('share_token', 64)->nullable()->unique();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shopping_list_entries', function (Blueprint $table) {
            $table->foreignId('shopping_list_id')->nullable()->after('household_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('note')->nullable();
            // Price seen in an offer (optional, for the estimated total).
            $table->decimal('price', 8, 2)->nullable();
        });

        // Brands and products whose offers the household follows.
        Schema::create('shopping_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('term');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // How often the household ticked off an item – for "bought often" suggestions.
        Schema::create('shopping_item_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('label_key');
            $table->string('label');
            $table->string('quantity')->nullable();
            $table->string('aisle', 20)->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('last_bought_at')->nullable();
            $table->timestamps();
            $table->unique(['household_id', 'label_key']);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->string('offers_zip', 10)->nullable();
            $table->json('offers_chains')->nullable();
        });

        // Every household that already uses the list gets it as its first list; the old share link keeps working.
        $householdIds = DB::table('shopping_list_entries')->distinct()->pluck('household_id')
            ->merge(DB::table('households')->whereNotNull('shopping_share_token')->pluck('id'))
            ->unique();

        foreach ($householdIds as $householdId) {
            $listId = DB::table('shopping_lists')->insertGetId([
                'household_id' => $householdId,
                'name' => 'Einkaufsliste',
                'icon' => '🛒',
                'share_token' => DB::table('households')->where('id', $householdId)->value('shopping_share_token'),
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('shopping_list_entries')->where('household_id', $householdId)->update(['shopping_list_id' => $listId]);
        }

        Schema::table('households', function (Blueprint $table) {
            $table->dropUnique(['shopping_share_token']);
            $table->dropColumn('shopping_share_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('shopping_share_token', 64)->nullable()->unique();
            $table->dropColumn(['offers_zip', 'offers_chains']);
        });

        foreach (DB::table('shopping_lists')->whereNotNull('share_token')->orderBy('position')->get() as $list) {
            DB::table('households')->where('id', $list->household_id)->whereNull('shopping_share_token')->update(['shopping_share_token' => $list->share_token]);
        }

        Schema::dropIfExists('shopping_item_stats');
        Schema::dropIfExists('shopping_watches');

        Schema::table('shopping_list_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_list_id');
            $table->dropColumn(['position', 'note', 'price']);
        });

        Schema::dropIfExists('shopping_lists');
        Schema::dropIfExists('shopping_stores');
    }
};
