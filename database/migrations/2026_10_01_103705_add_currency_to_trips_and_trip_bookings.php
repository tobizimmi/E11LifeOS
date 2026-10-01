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
        Schema::table('trips', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('budget');
        });

        Schema::table('trip_bookings', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('cost');
            $table->decimal('foreign_amount', 12, 2)->nullable()->after('currency');
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('foreign_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_bookings', function (Blueprint $table) {
            $table->dropColumn(['currency', 'foreign_amount', 'exchange_rate']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
