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
        Schema::table('shopping_list_entries', function (Blueprint $table) {
            // Supermarket section (see App\Support\ShoppingAisles), guessed from the label.
            $table->string('aisle', 30)->nullable()->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopping_list_entries', function (Blueprint $table) {
            $table->dropColumn('aisle');
        });
    }
};
