<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic price tracking for a wish: the price last found at its saved link, the lowest seen
     * so far, when it was last checked and whether the last check worked.
     */
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->decimal('tracked_price', 12, 2)->nullable()->after('price');
            $table->string('tracked_currency', 3)->nullable()->after('tracked_price');
            $table->decimal('lowest_price', 12, 2)->nullable()->after('tracked_currency');
            $table->timestamp('price_checked_at')->nullable()->after('lowest_price');
            $table->string('price_check_status', 10)->nullable()->after('price_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn(['tracked_price', 'tracked_currency', 'lowest_price', 'price_checked_at', 'price_check_status']);
        });
    }
};
