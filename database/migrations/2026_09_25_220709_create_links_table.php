<?php

use App\Models\Item;
use App\Support\Links;
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
        // Links between pages, tasks, entries, inbox items and recipes ("item" / "recipe"),
        // from a text ("body"), a relation property ("property") or the link button ("manual").
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('from_type', 10);
            $table->unsignedBigInteger('from_id');
            $table->string('to_type', 10);
            $table->unsignedBigInteger('to_id');
            $table->string('source', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_type', 'from_id', 'to_type', 'to_id', 'source']);
            $table->index(['to_type', 'to_id']);
        });

        // Existing [[wiki links]] and internal links in texts.
        Item::query()->whereNotNull('content')->with('creator')->chunkById(200, function ($items) {
            $items->each(fn (Item $item) => Links::syncBody($item));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
