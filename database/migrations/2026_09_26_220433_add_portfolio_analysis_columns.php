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
        Schema::table('portfolio_settings', function (Blueprint $table) {
            // 0, 0.08 (Bavaria, Baden-Württemberg) or 0.09.
            $table->decimal('church_tax_rate', 4, 3)->default(0)->after('tax_allowance');
        });

        Schema::table('savings_plans', function (Blueprint $table) {
            $table->date('last_executed_on')->nullable()->after('next_date');
        });

        Schema::table('portfolio_positions', function (Blueprint $table) {
            // Stop loss / take profit notifications are sent once per crossing.
            $table->timestamp('stop_loss_notified_at')->nullable()->after('take_profit_price');
            $table->timestamp('take_profit_notified_at')->nullable()->after('stop_loss_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_positions', function (Blueprint $table) {
            $table->dropColumn(['stop_loss_notified_at', 'take_profit_notified_at']);
        });

        Schema::table('savings_plans', function (Blueprint $table) {
            $table->dropColumn('last_executed_on');
        });

        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->dropColumn('church_tax_rate');
        });
    }
};
